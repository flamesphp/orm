<?php
declare(strict_types=1);


namespace Flames\Orm\Database\QueryBuilder;

use Flames\Collection\Arr;
use Flames\Orm\Database\QueryBuilder\Support\OperatorSql;
use Flames\Orm\Database\QueryBuilder\Support\VectorSql;
use Flames\Orm\Exception\UnsupportedQueryException;
use Flames\Orm\Database\Type\Kinds;
use PDO;
use Exception;

/**
 * @internal
 */
class MySql extends DefaultEx
{
    // Per-table column list (survives across requests in long-running processes)
    protected static array $columnCache = [];

    // Cached SELECT column SQL string per table  (avoids rebuilding string on each get())
    protected static array $selectSqlCache = [];

    // Prepared statement cache keyed by connection-id + query hash
    private static array $stmtCache = [];

    // ── WHERE clause ─────────────────────────────────────────────────────────

    protected function _pushSimpleWhere(
        WhereOperator $operator,
        string $key,
        string $condition,
        mixed $value,
        array $options = [],
    ): static {
        if ($this->mode === 'model' && isset($this->modelData->column[$key])) {
            $column = $this->modelData->column[$key];
            $value  = match ($condition) {
                'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN' => array_map(
                    fn (mixed $item): mixed => $this->modelCast::pre($column, $item),
                    (array) $value,
                ),
                'IS TRUE', 'IS FALSE', 'IS UNKNOWN', 'IS NOT TRUE', 'IS NOT FALSE', 'IS NOT UNKNOWN' => $value,
                default => $this->modelCast::pre($column, $value),
            };
        }

        return parent::_pushSimpleWhere($operator, $key, $condition, $value, $options);
    }

    protected function _nativeWhere(array $data): array
    {
        $this->_ensureSoftDeleteScope();

        if (empty($this->wheres)) {
            return ['data' => $data, 'query' => ''];
        }

        $fragments  = [];
        $whereIndex = 0;

        foreach ($this->wheres as $where) {
            [$fragment, $data, $whereIndex] = match ($where['type']) {
                WhereType::Simple       => $this->_whereSimplePart($where, $data, $whereIndex),
                WhereType::Raw          => $this->_whereRawPart($where, $data, $whereIndex),
                WhereType::Delegate     => $this->_whereDelegatePart($where, $data, $whereIndex, false),
                WhereType::NotDelegate  => $this->_whereDelegatePart($where, $data, $whereIndex, true),
                WhereType::Expression   => $this->_whereExpressionPart($where, $data, $whereIndex),
                WhereType::Column       => $this->_whereColumnPart($where, $data, $whereIndex),
                WhereType::Bitwise      => $this->_whereBitwisePart($where, $data, $whereIndex),
                WhereType::Strcmp       => $this->_whereStrcmpPart($where, $data, $whereIndex),
                WhereType::RegexpLike   => $this->_whereRegexpLikePart($where, $data, $whereIndex),
                WhereType::JsonPath     => $this->_whereJsonPathPart($where, $data, $whereIndex),
                WhereType::FullText     => $this->_whereFullTextPart($where, $data, $whereIndex),
                WhereType::Operator     => $this->_whereOperatorPart($where, $data, $whereIndex),
            };
            $fragments[] = [$where['operator']->value, $fragment];
        }

        $sql = $this->_combineWhereFragments($fragments);

        return ['data' => $data, 'query' => $sql . "\r\n"];
    }

    /**
     * @param list<array{0: string, 1: string}> $fragments
     */
    protected function _combineWhereFragments(array $fragments): string
    {
        if ($fragments === []) {
            return '';
        }

        $sql = $fragments[0][1];

        for ($index = 1, $count = count($fragments); $index < $count; $index++) {
            [$operator, $fragment] = $fragments[$index];
            $sql = $sql . ' ' . $operator . ' ' . $fragment;
        }

        return $sql;
    }

    protected function _whereSimplePart(array $w, array $data, int $idx): array
    {
        $base = 'where_' . $this->whereBaseIndex . $idx . '_' . $w['key'];
        $col  = '`' . $this->table . '`.`' . $w['key'] . '`';

        return match ($w['condition']) {
            'IN', 'NOT IN' => $this->_whereListPart($col, $w, $data, $base, $idx),
            'BETWEEN', 'NOT BETWEEN' => $this->_whereBetweenPart($col, $w, $data, $base, $idx),
            'IS NULL'      => ["$col IS NULL", $data, $idx],
            'IS NOT NULL'  => ["$col IS NOT NULL", $data, $idx],
            'IS TRUE'      => ["$col IS TRUE", $data, $idx],
            'IS FALSE'     => ["$col IS FALSE", $data, $idx],
            'IS UNKNOWN'   => ["$col IS UNKNOWN", $data, $idx],
            'IS NOT TRUE'  => ["$col IS NOT TRUE", $data, $idx],
            'IS NOT FALSE' => ["$col IS NOT FALSE", $data, $idx],
            'IS NOT UNKNOWN' => ["$col IS NOT UNKNOWN", $data, $idx],
            'LIKE'         => $this->_whereLikePart($col, $w['value'], $data, $base, $idx, false, true, false),
            'NOT LIKE'     => $this->_whereLikePart($col, $w['value'], $data, $base, $idx, true, true, false),
            'LIKE_PATTERN' => $this->_whereLikePart($col, $w['value'], $data, $base, $idx, false, false, false),
            'NOT_LIKE_PATTERN' => $this->_whereLikePart($col, $w['value'], $data, $base, $idx, true, false, false),
            'ILIKE'        => $this->_whereLikePart($col, $w['value'], $data, $base, $idx, false, $w['options']['wrap'] ?? false, true),
            'NOT ILIKE'    => $this->_whereLikePart($col, $w['value'], $data, $base, $idx, true, $w['options']['wrap'] ?? false, true),
            'REGEXP', 'RLIKE' => $this->_whereRegexpPart($col, $w['value'], $data, $base, $idx, false),
            'NOT REGEXP', 'NOT RLIKE' => $this->_whereRegexpPart($col, $w['value'], $data, $base, $idx, true),
            'IS DISTINCT FROM' => $this->_whereDistinctPart($col, $w, $data, $base, $idx, false),
            'IS NOT DISTINCT FROM' => $this->_whereDistinctPart($col, $w, $data, $base, $idx, true),
            default        => $this->_whereComparePart($col, $w, $data, $base, $idx),
        };
    }

    private function _whereListPart(string $col, array $w, array $data, string $base, int $idx): array
    {
        $values = $w['value'];
        $not    = $w['condition'] === 'NOT IN';

        if ($values === []) {
            return [$not ? '1=1' : '0=1', $data, $idx];
        }

        $params = [];
        foreach ($values as $v) {
            $k        = $base . '_' . $idx++;
            $params[] = $k;
            $data[$k] = $v;
        }

        $operator = $not ? 'NOT IN' : 'IN';

        return ["$col $operator (:" . implode(', :', $params) . ')', $data, $idx];
    }

    private function _whereBetweenPart(string $col, array $w, array $data, string $base, int $idx): array
    {
        [$from, $to] = $w['value'];
        $fromKey     = $base . '_from';
        $toKey       = $base . '_to';
        $data[$fromKey] = $from;
        $data[$toKey]   = $to;
        $between        = $w['condition'] === 'NOT BETWEEN' ? 'NOT BETWEEN' : 'BETWEEN';

        return ["$col $between :$fromKey AND :$toKey", $data, $idx + 1];
    }

    protected function _whereLikePart(
        string $col,
        mixed $value,
        array $data,
        string $base,
        int $idx,
        bool $not,
        bool $wrap,
        bool $ilike = false,
    ): array {
        $data[$base] = $value;

        if ($ilike) {
            $param = ':' . $base;
            $expr  = OperatorSql::mysqlIlike($col, $param, $not, $wrap);

            return ['(' . $expr . ')', $data, ++$idx];
        }

        $operator   = $not ? 'NOT LIKE' : 'LIKE';
        $expression = $wrap
            ? "$operator CONCAT('%', :$base, '%')"
            : "$operator :$base";

        return ["$col $expression", $data, ++$idx];
    }

    protected function _whereDistinctPart(
        string $col,
        array $w,
        array $data,
        string $base,
        int $idx,
        bool $notDistinct,
    ): array {
        $data[$base] = $w['value'];
        $param       = ':' . $base;

        return ['(' . OperatorSql::mysqlDistinctFrom($col, $param, $notDistinct) . ')', $data, ++$idx];
    }

    protected function _whereRegexpPart(
        string $col,
        mixed $value,
        array $data,
        string $base,
        int $idx,
        bool $not,
    ): array {
        $data[$base] = $value;
        $operator    = $not ? 'NOT REGEXP' : 'REGEXP';

        return ["$col $operator :$base", $data, ++$idx];
    }

    protected function _whereComparePart(string $col, array $w, array $data, string $base, int $idx): array
    {
        $data[$base] = $w['value'];
        $type        = $this->_whereColumnType($w['key']);

        if (in_array($type, ['json', 'jsonb'], true) && in_array($w['condition'], ['=', '<=>'], true)) {
            return ['(' . $this->_jsonEqualitySql($col, $base) . ')', $data, ++$idx];
        }

        return ["$col {$w['condition']} :$base", $data, ++$idx];
    }

    protected function _jsonEqualitySql(string $col, string $paramName): string
    {
        return "$col = CAST(:$paramName AS JSON)";
    }

    protected function _whereColumnType(string $columnName): ?string
    {
        if ($this->mode !== 'model') {
            return null;
        }

        foreach ($this->modelData->column as $column) {
            if ($column->name === $columnName) {
                return Kinds::normalize($column->type);
            }
        }

        return null;
    }

    private function _whereRawPart(array $w, array $data, int $idx): array
    {
        if ($w['condition'] === '' || $w['value'] === null) {
            return ['(' . $w['condition'] . ')', $data, $idx];
        }

        $condition = $w['condition'];
        foreach ($w['value'] as $key => $value) {
            $pKey        = 'where_' . $this->whereBaseIndex . $idx++ . '_' . $key;
            $condition   = str_replace('{' . $key . '}', ':' . $pKey, $condition);
            $data[$pKey] = $value;
        }
        return ['(' . $condition . ')', $data, $idx];
    }

    private function _whereDelegatePart(array $w, array $data, int $idx, bool $negated = false): array
    {
        $sub = new static($this->connection);
        $sub->_setBaseIndex($this->whereBaseIndex . $idx . '_');
        $this->mode === 'model' ? $sub->setModel($this->model) : $sub->setTable($this->table);

        ($w['value'])($sub);

        $result = $sub->_nativeWhere([]);
        $query  = rtrim($result['query']);
        $fragment = $negated ? '(NOT ' . $query . ')' : '(' . $query . ')';

        return [$fragment, array_merge($data, $result['data']), $idx + 1];
    }

    private function _whereExpressionPart(array $w, array $data, int $idx): array
    {
        $expression = $this->_resolveExpression($w['expression']);
        $base       = 'where_' . $this->whereBaseIndex . $idx . '_value';

        foreach ($w['bindings'] ?? [] as $key => $value) {
            $pKey        = 'where_' . $this->whereBaseIndex . $idx++ . '_' . $key;
            $expression  = str_replace('{' . $key . '}', ':' . $pKey, $expression);
            $data[$pKey] = $value;
        }

        $data[$base] = $w['value'];

        return ['(' . $expression . ') ' . $w['condition'] . ' :' . $base, $data, ++$idx];
    }

    private function _whereColumnPart(array $w, array $data, int $idx): array
    {
        $left  = $this->_qualifiedColumn($w['left']);
        $right = $this->_qualifiedColumn($w['right']);

        return ['(' . $left . ' ' . $w['condition'] . ' ' . $right . ')', $data, $idx];
    }

    protected function _whereBitwisePart(array $w, array $data, int $idx): array
    {
        $col = $this->_qualifiedColumn($w['key']);

        if (($w['unary'] ?? false) === true) {
            $valueKey        = 'where_' . $this->whereBaseIndex . $idx . '_value';
            $data[$valueKey] = $w['value'];

            return ['((CAST(~' . $col . ' AS SIGNED) ' . $w['condition'] . ' :' . $valueKey . '))', $data, ++$idx];
        }

        $base = 'where_' . $this->whereBaseIndex . $idx . '_operand';
        $data[$base] = $w['operand'];

        $expression = '(' . $col . ' ' . $w['bitOperator'] . ' :' . $base . ')';
        $valueKey   = 'where_' . $this->whereBaseIndex . $idx . '_value';
        $data[$valueKey] = $w['value'];

        return [$expression . ' ' . $w['condition'] . ' :' . $valueKey, $data, ++$idx];
    }

    protected function _whereStrcmpPart(array $w, array $data, int $idx): array
    {
        $left  = $this->_qualifiedColumn($w['left']);
        $right = ($w['rightIsValue'] ?? false)
            ? ':' . ($base = 'where_' . $this->whereBaseIndex . $idx . '_right')
            : $this->_qualifiedColumn((string) $w['right']);

        if ($w['rightIsValue'] ?? false) {
            $data[$base] = $w['right'];
        }

        $compare = $w['condition'] === '=' ? '= 0' : $w['condition'] . ' 0';

        return ['(STRCMP(' . $left . ', ' . $right . ') ' . $compare . ')', $data, ++$idx];
    }

    protected function _whereRegexpLikePart(array $w, array $data, int $idx): array
    {
        $col        = $this->_qualifiedColumn($w['key']);
        $patternKey = 'where_' . $this->whereBaseIndex . $idx . '_pattern';
        $pattern    = $w['pattern'];

        if ($w['flags'] !== null && $w['flags'] !== '') {
            if (str_contains((string) $w['flags'], 'i')) {
                $pattern = '(?i)' . $pattern;
            }
        }

        $data[$patternKey] = $pattern;
        $operator = ($w['not'] ?? false) ? 'NOT REGEXP' : 'REGEXP';

        return ['(' . $col . ' ' . $operator . ' :' . $patternKey . ')', $data, ++$idx];
    }

    protected function _whereJsonPathPart(array $w, array $data, int $idx): array
    {
        $col      = $this->_qualifiedColumn($w['key']);
        $path     = str_starts_with($w['path'], '$') ? $w['path'] : '$.' . ltrim($w['path'], '.');
        $pathLit  = "'" . str_replace("'", "''", $path) . "'";
        $valueKey = 'where_' . $this->whereBaseIndex . $idx . '_value';
        $data[$valueKey] = $w['value'];

        $extract = ($w['unquoted'] ?? false)
            ? 'JSON_UNQUOTE(JSON_EXTRACT(' . $col . ', ' . $pathLit . '))'
            : 'JSON_EXTRACT(' . $col . ', ' . $pathLit . ')';

        if (($w['condition'] ?? '=') === '=' && ($w['unquoted'] ?? false) === false) {
            return ['(' . $extract . ' = CAST(:' . $valueKey . ' AS JSON))', $data, ++$idx];
        }

        return ['(' . $extract . ' ' . $w['condition'] . ' :' . $valueKey . ')', $data, ++$idx];
    }

    protected function _whereOperatorPart(array $w, array $data, int $idx): array
    {
        $opts     = $w['options'] ?? [];
        $operator = $w['compare'];
        $domain   = $opts['domain'] ?? null;
        $col      = ($opts['leftIsValue'] ?? false) || ($opts['leftIsExpression'] ?? false)
            ? (string) $w['left']
            : $this->_qualifiedColumn((string) $w['left']);

        if (in_array($operator, ['~', '~*', '!~', '!~*'], true)) {
            $pattern = $w['right'];
            if (str_contains($operator, '*') && is_string($pattern)) {
                $pattern = '(?i)' . $pattern;
            }
            $key = 'where_' . $this->whereBaseIndex . $idx . '_pattern';
            $data[$key] = $pattern;
            $param = ':' . $key;
            $expr  = OperatorSql::mysqlRegex($col, $param, $operator);

            return ['(' . $expr . ')', $data, ++$idx];
        }

        if ($domain === 'json') {
            return $this->_whereMysqlJsonOperatorPart($w, $data, $idx, $col);
        }

        if ($domain === 'array') {
            if ($operator === '&&') {
                throw new UnsupportedQueryException('whereArrayOverlaps', 'mysql');
            }

            if (in_array($operator, ['@>', '<@'], true) === false) {
                throw new UnsupportedQueryException('whereOperator(array,' . $operator . ')', 'mysql');
            }

            $key = 'where_' . $this->whereBaseIndex . $idx . '_json';
            $data[$key] = json_encode($w['right'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $param = ':' . $key;
            $expr  = $operator === '@>'
                ? OperatorSql::mysqlJsonContains($col, $param)
                : OperatorSql::mysqlJsonContainedBy($col, $param);

            return ['(' . $expr . ')', $data, ++$idx];
        }

        if ($domain === 'tsvector' && $operator === '@@') {
            $key = 'where_' . $this->whereBaseIndex . $idx . '_query';
            $data[$key] = $w['right'];

            return ['(' . $col . " LIKE CONCAT('%', :$key, '%'))", $data, ++$idx];
        }

        if ($domain === 'tsquery') {
            throw new UnsupportedQueryException('whereTsQuery*', 'mysql');
        }

        if ($domain === 'network' || $domain === 'range') {
            throw new UnsupportedQueryException('whereNetwork*/whereRange*', 'mysql');
        }

        if ($domain === 'concat') {
            $compare    = $opts['compare'] ?? '=';
            $appendKey  = 'where_' . $this->whereBaseIndex . $idx . '_append';
            $equalsKey  = 'where_' . $this->whereBaseIndex . $idx . '_equals';
            $data[$appendKey] = $w['right'];
            $data[$equalsKey] = $opts['compareValue'] ?? null;

            return ['((CONCAT(' . $col . ', :' . $appendKey . ') ' . $compare . ' :' . $equalsKey . '))', $data, ++$idx];
        }

        if ($opts['leftIsExpression'] ?? false) {
            $key = 'where_' . $this->whereBaseIndex . $idx . '_value';
            $data[$key] = $w['right'];

            return ['(' . $col . ' ' . $operator . ' :' . $key . ')', $data, ++$idx];
        }

        $key = 'where_' . $this->whereBaseIndex . $idx . '_value';
        $data[$key] = $w['right'];

        return ['(' . $col . ' ' . $operator . ' :' . $key . ')', $data, ++$idx];
    }

    protected function _whereMysqlJsonOperatorPart(array $w, array $data, int $idx, string $col): array
    {
        $operator = $w['compare'];
        $opts     = $w['options'] ?? [];

        if (in_array($operator, ['#>', '#>>'], true)) {
            $path = $opts['path'] ?? '';
            $path = str_starts_with((string) $path, '$') ? $path : '$.' . ltrim((string) $path, '.');
            $pathLit = "'" . str_replace("'", "''", (string) $path) . "'";
            $extract = $operator === '#>>'
                ? 'JSON_UNQUOTE(JSON_EXTRACT(' . $col . ', ' . $pathLit . '))'
                : 'JSON_EXTRACT(' . $col . ', ' . $pathLit . ')';
            $compare = $opts['compare'] ?? '=';
            $key = 'where_' . $this->whereBaseIndex . $idx . '_value';
            $data[$key] = $w['right'];

            return ['(' . $extract . ' ' . $compare . ' :' . $key . ')', $data, ++$idx];
        }

        if ($operator === '?') {
            $key = 'where_' . $this->whereBaseIndex . $idx . '_key';
            $data[$key] = (string) $w['right'];
            $param = ':' . $key;

            return ['(' . OperatorSql::mysqlJsonHasKey($col, $param) . ')', $data, ++$idx];
        }

        if (in_array($operator, ['?&', '?|'], true)) {
            throw new UnsupportedQueryException('whereJsonHasAllKeys/whereJsonHasAnyKey', 'mysql');
        }

        if (in_array($operator, ['@>', '<@'], true)) {
            $key = 'where_' . $this->whereBaseIndex . $idx . '_doc';
            $data[$key] = is_string($w['right']) ? $w['right'] : json_encode($w['right'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $param = ':' . $key;
            $expr  = $operator === '@>'
                ? OperatorSql::mysqlJsonContains($col, $param)
                : OperatorSql::mysqlJsonContainedBy($col, $param);

            return ['(' . $expr . ')', $data, ++$idx];
        }

        throw new UnsupportedQueryException('whereOperator(json,' . $operator . ')', 'mysql');
    }

    protected function _whereFullTextPart(array $w, array $data, int $idx): array
    {
        $terms = $this->_parseFullTextTerms((string) $w['query'], (string) ($w['mode'] ?? 'BOOLEAN'));
        if ($terms === []) {
            return ['(1=1)', $data, $idx];
        }

        $termClauses = [];
        foreach ($terms as $termIndex => $term) {
            $columnClauses = [];
            foreach ($w['columns'] as $colIndex => $column) {
                $key = 'where_' . $this->whereBaseIndex . $idx . '_ft_' . $termIndex . '_' . $colIndex;
                $data[$key] = $term;
                $columnClauses[] = $this->_qualifiedColumn($column) . " LIKE CONCAT('%', :$key, '%')";
                ++$idx;
            }
            $termClauses[] = '(' . implode(' OR ', $columnClauses) . ')';
        }

        return ['(' . implode(' AND ', $termClauses) . ')', $data, $idx];
    }

    /** @return list<string> */
    private function _parseFullTextTerms(string $query, string $mode): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        preg_match_all('/[\p{L}\p{N}]+/u', $query, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    // ── ORDER / GROUP (unified helper) ────────────────────────────────────────

    private function _clauseList(array $items, bool $withDirection = false): string
    {
        return empty($items) ? '' : implode(",\r\n", array_map(
            fn($i) => '`' . $this->table . '`.`' . $i['key'] . '`' . ($withDirection ? ' ' . $i['direction'] : ''),
            $items
        )) . "\r\n";
    }

    protected function _nativeOrder(): string { return $this->_clauseList($this->orders, true); }
    protected function _nativeGroup(): string { return $this->_clauseList($this->groups); }
    protected function _nativeJoin():  string { return ''; }
    public    function join(string $model, string $name, callable $delegate): static { return $this; }

    // ── Column resolution ─────────────────────────────────────────────────────

    protected function _tableColumns(): array
    {
        if (isset(self::$columnCache[$this->table])) {
            return self::$columnCache[$this->table];
        }

        if ($this->mode === 'table') {
            $cols = array_column(
                $this->connection->query('SHOW COLUMNS FROM `' . $this->table . '`;')->fetchAll(),
                'Field'
            );
        } else {
            $cols = [];
            foreach ($this->modelData->column as $col) {
                $cols[] = $col->name;
            }
        }

        return self::$columnCache[$this->table] = $cols;
    }

    protected function _selectColumnsSql(): string
    {
        if ($this->mode === 'model') {
            $parts = [];
            foreach ($this->modelData->column as $col) {
                $expression = match (true) {
                    Kinds::normalize($col->type) === 'varbit'
                        => 'CAST(`' . $this->table . '`.`' . $col->name . '` AS UNSIGNED)',
                    Kinds::isSpatial($col->type) => 'ST_AsText(`' . $this->table . '`.`' . $col->name . '`)',
                    Kinds::isVector($col->type)    => $this->_vectorToExpression($col->name),
                    default                        => '`' . $this->table . '`.`' . $col->name . '`',
                };
                $parts[] = $expression . " AS '" . $this->table . '.' . $col->name . "'";
            }

            return implode(",\r\n", $parts);
        }

        return self::$selectSqlCache[$this->table] ??= implode(",\r\n", array_map(
            fn($col) => '`' . $this->table . '`.`' . $col . "` AS '" . $this->table . '.' . $col . "'",
            $this->_tableColumns()
        ));
    }

    protected function _sqlValueExpression(string $key): string
    {
        if ($this->mode !== 'model' || !isset($this->modelData->column[$key])) {
            return ':' . $key;
        }

        $column = $this->modelData->column[$key];
        if (Kinds::isSpatial($column->type)) {
            return 'ST_GeomFromText(:' . $key . ', ' . (int) ($column->srid ?? 0) . ')';
        }

        if (Kinds::isVector($column->type)) {
            return $this->_vectorFromExpression($key);
        }

        if (Kinds::normalize($column->type) === 'varbit') {
            return 'CAST(:' . $key . ' AS UNSIGNED)';
        }

        return ':' . $key;
    }

    protected function _vectorFromExpression(string $paramKey): string
    {
        $fn = VectorSql::resolve($this->connection)['from'];

        return $fn . '(:' . $paramKey . ')';
    }

    protected function _vectorToExpression(string $columnName): string
    {
        $fn = VectorSql::resolve($this->connection)['to'];

        return $fn . '(`' . $this->table . '`.`' . $columnName . '`)';
    }

    // ── Prepared statement cache ──────────────────────────────────────────────

    private function _prepare(string $sql): \PDOStatement
    {
        $key = spl_object_id($this->connection) . ':' . $sql;
        return self::$stmtCache[$key] ??= $this->connection->prepare($sql);
    }

    // ── Query builders ────────────────────────────────────────────────────────

    private function _buildGetSql(array &$data): string
    {
        $sql = 'SELECT ' . $this->_selectColumnsSql() . "\r\nFROM `{$this->table}` " . $this->_nativeJoin();

        $where = $this->_nativeWhere($data);
        $data  = $where['data'];
        if ($where['query'] !== '') { $sql .= "\r\nWHERE\r\n"    . $where['query'];      }

        $group = $this->_nativeGroup();
        if ($group !== '')          { $sql .= "\r\nGROUP BY\r\n" . $group;               }

        $order = $this->_nativeOrder();
        if ($order !== '')          { $sql .= "\r\nORDER BY\r\n" . $order;               }

        if ($this->limit  !== null) { $sql .= "\r\nLIMIT "  . $this->limit;              }
        if ($this->offset !== null) { $sql .= "\r\nOFFSET " . $this->offset;             }

        return $sql;
    }

    private function _colName(string $key): string
    {
        return $this->mode === 'model' ? $this->modelData->column[$key]->name : $key;
    }

    // ── Result hydration ──────────────────────────────────────────────────────

    private function _hydrateModels(\PDOStatement $stmt): Arr
    {
        $cast   = $this->modelCast;
        $class  = $this->modelData->class;
        $models = Arr();

        while ($row = $stmt->fetch()) {
            $modelData = [];
            foreach ($this->modelData->column as $col) {
                $value = $this->_hydrateRowValue($row, $col->name);
                if ($value !== null || $this->_hydrateRowValueExists($row, $col->name)) {
                    $modelData[$col->property] = $cast::pos($col, $value, true);
                }
            }
            $models[] = new $class($modelData, true);
        }
        return $models;
    }

    private function _hydrateRowValue(array $row, string $columnName): mixed
    {
        $alias      = $this->table . '.' . $columnName;
        $underscore = str_replace('.', '_', $alias);

        if (array_key_exists($alias, $row)) {
            return $row[$alias];
        }

        if (array_key_exists($underscore, $row)) {
            return $row[$underscore];
        }

        if (array_key_exists($columnName, $row)) {
            return $row[$columnName];
        }

        return null;
    }

    private function _hydrateRowValueExists(array $row, string $columnName): bool
    {
        $alias      = $this->table . '.' . $columnName;
        $underscore = str_replace('.', '_', $alias);

        return array_key_exists($alias, $row)
            || array_key_exists($underscore, $row)
            || array_key_exists($columnName, $row);
    }

    // ── Prepare model data (cast pos → pre) ───────────────────────────────────

    private function _prepareModelData(array $data): array
    {
        return $this->_castDataPre($data);
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public function get(): Arr
    {
        $data = [];
        $stmt = $this->_prepare($this->_buildGetSql($data));
        $stmt->execute($data);

        return $this->mode === 'model' ? $this->_hydrateModels($stmt) : Arr($stmt->fetchAll());
    }

    public function update(Arr|array $data): bool
    {
        $data = $this->mode === 'model' ? $this->_prepareModelData((array)$data) : (array)$data;

        if (empty($data)) {
            throw new Exception("Update payload in table {$this->table} can't be empty.");
        }

        $set   = implode(', ', array_map(fn($k) => '`' . $this->_colName($k) . '` = ' . $this->_sqlValueExpression($k), array_keys($data)));
        $sql   = "UPDATE `{$this->table}` " . $this->_nativeJoin() . " SET $set\r\n";

        $where = $this->_nativeWhere($data);
        $data  = $where['data'];
        if ($where['query'] !== '') { $sql .= "\r\nWHERE\r\n" . $where['query']; }

        $order = $this->_nativeOrder();
        if ($order !== '')          { $sql .= "\r\nORDER BY\r\n" . $order;        }
        if ($this->limit !== null)  { $sql .= "\r\nLIMIT " . $this->limit;        }

        $preResolved   = $this->_resolveModifiedIdsFromWheres();
        $returningCol  = $preResolved === null && $this->trackModifiedIds && $this->_driverSupportsReturning()
            ? $this->_returningPrimaryKeyColumnName()
            : null;
        $usedReturning = $returningCol !== null;

        if ($usedReturning) {
            $sql .= "\r\nRETURNING `{$returningCol}`";
        }

        $stmt = $this->_prepare($sql);
        $stmt->execute($data);

        return $this->_finalizeUpdate(true, $this->_resolveModifiedIdsAfterMutation($preResolved, $stmt, $usedReturning));
    }

    protected function _driverSupportsReturning(): bool
    {
        return false;
    }

    protected function _executeSoftDelete(): int
    {
        $data = $this->mode === 'model'
            ? $this->_prepareModelData([$this->_softDeleteColumnProperty() => $this->_softDeleteTimestamp()])
            : [$this->_softDeleteColumnProperty() => $this->_softDeleteTimestamp()];

        if ($data === []) {
            throw new Exception("Soft delete payload in table {$this->table} can't be empty.");
        }

        $set   = implode(', ', array_map(fn($k) => '`' . $this->_colName($k) . '` = ' . $this->_sqlValueExpression($k), array_keys($data)));
        $sql   = "UPDATE `{$this->table}` " . $this->_nativeJoin() . " SET $set\r\n";

        $where = $this->_nativeWhere($data);
        $data  = $where['data'];
        if ($where['query'] !== '') {
            $sql .= "\r\nWHERE\r\n" . $where['query'];
        }

        if ($this->limit !== null) {
            $sql .= "\r\nLIMIT " . $this->limit;
        }

        $stmt = $this->_prepare($sql);
        $stmt->execute($data);

        return $stmt->rowCount();
    }

    protected function _executeHardDelete(?Arr $preResolvedIds = null): int
    {
        $data = [];
        $sql  = "DELETE FROM `{$this->table}` " . $this->_nativeJoin();

        $where = $this->_nativeWhere($data);
        $data  = $where['data'];
        if ($where['query'] !== '') {
            $sql .= "\r\nWHERE\r\n" . $where['query'];
        }

        $order = $this->_nativeOrder();
        if ($order !== '') {
            $sql .= "\r\nORDER BY\r\n" . $order;
        }

        if ($this->limit !== null) {
            $sql .= "\r\nLIMIT " . $this->limit;
        }

        $returningCol  = $preResolvedIds === null && $this->trackModifiedIds && $this->_driverSupportsReturning()
            ? $this->_returningPrimaryKeyColumnName()
            : null;
        $usedReturning = $returningCol !== null;

        if ($usedReturning) {
            $sql .= "\r\nRETURNING `{$returningCol}`";
        }

        $stmt = $this->_prepare($sql);
        $stmt->execute($data);

        if ($usedReturning) {
            $this->pendingModifiedIds = $this->_readModifiedIdsFromStatement($stmt);
        }

        return $stmt->rowCount();
    }

    public function insert(Arr|array $data): mixed
    {
        $data = $this->mode === 'model' ? $this->_prepareModelData((array) $data) : (array) $data;

        if ($this->mode !== 'model') {
            return $this->_executeInsert($data);
        }

        return $this->_insertWithUuidCollisionRetry(
            $data,
            fn (array $payload): mixed => $this->_executeInsert($payload),
        );
    }

    private function _executeInsert(array $data): mixed
    {
        if ($data === []) {
            throw new Exception("Insert payload in table {$this->table} can't be empty.");
        }

        $cols = implode(', ', array_map(fn($k) => '`' . $this->_colName($k) . '`', array_keys($data)));
        $vals = implode(', ', array_map(fn($k) => $this->_sqlValueExpression($k), array_keys($data)));

        $this->_prepare("INSERT INTO `{$this->table}` ($cols) VALUES ($vals);")->execute($data);

        if ($this->mode === 'model') {
            return $this->_insertIdentity($this->_resolveInsertIdentity($data, $this->connection->lastInsertId()));
        }

        return $this->connection->lastInsertId();
    }

    protected function _stripNullIdentityColumns(array $data): array
    {
        foreach ($this->modelData->column as $column) {
            if (($column->primary || $column->autoIncrement) === false) {
                continue;
            }

            if (array_key_exists($column->property, $data) === false) {
                continue;
            }

            $value = $data[$column->property];
            if ($value === null || ($column->autoIncrement && ($value === 0 || $value === '0'))) {
                unset($data[$column->property]);
            }
        }

        return $data;
    }

    protected function _insertIdentity(string|false $id): Arr
    {
        if ($id === false || $id === '' || $id === '0') {
            throw new Exception("Insert in table {$this->table} did not return an auto-increment id.");
        }

        $cast = $this->modelCast;

        foreach ($this->modelData->column as $column) {
            if ($column->autoIncrement || $column->primary) {
                return Arr([$column->property => $cast::pos($column, $id, true)]);
            }
        }

        throw new Exception("Insert in table {$this->table} requires a primary or auto-increment column.");
    }
}
