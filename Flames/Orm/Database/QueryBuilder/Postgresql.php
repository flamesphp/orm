<?php
declare(strict_types=1);


namespace Flames\Orm\Database\QueryBuilder;

use Flames\Collection\Arr;
use Flames\Orm\Database\Type\Kinds;
use PDO;
use Exception;

/**
 * @internal
 */
class Postgresql extends DefaultEx
{
    // Per-table column list (survives across requests in long-running processes)
    private static array $columnCache = [];

    // Cached SELECT column SQL string per table (avoids rebuilding string on each get())
    private static array $selectSqlCache = [];

    // Prepared statement cache keyed by connection-id + query hash
    private static array $stmtCache = [];

    // ── Identifier quoting ────────────────────────────────────────────────────

    protected function _q(string $id): string
    {
        return '"' . str_replace('"', '""', $id) . '"';
    }

    protected function _tableRef(): string
    {
        return $this->_q($this->table);
    }

    protected function _colRef(string $column): string
    {
        return $this->_tableRef() . '.' . $this->_q($column);
    }

    protected function _qualifiedColumn(string $columnName): string
    {
        return $this->_colRef($columnName);
    }

    protected function _resolveExpression(string $expression): string
    {
        return preg_replace_callback(
            '/\{(\w+)\}/',
            function (array $matches): string {
                $key = $matches[1];

                if ($this->mode === 'model' && isset($this->modelData->column[$key])) {
                    return $this->_colRef($this->_resolveWhereKey($key));
                }

                return '{' . $key . '}';
            },
            $expression,
        ) ?? $expression;
    }

    // ── WHERE clause ─────────────────────────────────────────────────────────

    private function _whereParamRef(?string $columnKey, string $paramKey, mixed $value = null): string
    {
        return 'CAST(:' . $paramKey . ' AS ' . $this->_pgCastTypeForBind($columnKey, $value) . ')';
    }

    private function _pgCastTypeForBind(?string $columnKey, mixed $value): string
    {
        if ($columnKey !== null && $this->mode === 'model' && isset($this->modelData->column[$columnKey])) {
            return $this->_pgCastTypeFromDdl(Kinds::ddlTypePostgresql($this->modelData->column[$columnKey]));
        }

        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value)  => 'integer',
            is_float($value)=> 'double precision',
            default         => 'text',
        };
    }

    private function _pgCastTypeFromDdl(string $ddlType): string
    {
        if (preg_match('/^bit varying\((\d+)\)$/', $ddlType, $matches)) {
            return 'bit(' . $matches[1] . ')';
        }

        if ($ddlType === 'bit varying') {
            return 'varbit';
        }

        return match ($ddlType) {
            'serial'      => 'integer',
            'bigserial'   => 'bigint',
            'smallserial' => 'smallint',
            default       => $ddlType,
        };
    }

    private function _prepareBindValue(?string $columnKey, mixed $value): mixed
    {
        if ($columnKey !== null && $this->mode === 'model' && isset($this->modelData->column[$columnKey])) {
            $column = $this->modelData->column[$columnKey];
            $type   = Kinds::normalize($column->type);

            if (in_array($type, ['bool', 'boolean', 'bit'], true)) {
                return \Flames\Orm\Database\Cast\Postgresql\Boolean::pre($column, $value);
            }

            if (Kinds::isBinary($type) && is_string($value)) {
                return \Flames\Orm\Database\Cast\Postgresql\Binary::pre($column, $value);
            }
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value;
    }

    protected function _nativeWhere(array $data): array
    {
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
            };
            $fragments[] = [$where['operator']->value, $fragment];
        }

        $sql = $this->_combineWhereFragments($fragments);

        return ['data' => $data, 'query' => $sql . "\r\n"];
    }

    private function _combineWhereFragments(array $fragments): string
    {
        if ($fragments === []) {
            return '';
        }

        $sql = $fragments[0][1];

        for ($index = 1, $count = count($fragments); $index < $count; $index++) {
            [$operator, $fragment] = $fragments[$index];

            $sql = match ($operator) {
                'XOR' => '((' . $sql . ' AND NOT (' . $fragment . ')) OR (NOT (' . $sql . ') AND (' . $fragment . ')))',
                default => $sql . ' ' . $operator . ' ' . $fragment,
            };
        }

        return $sql;
    }

    private function _whereSimplePart(array $w, array $data, int $idx): array
    {
        $base = 'where_' . $this->whereBaseIndex . $idx . '_' . $w['key'];
        $col  = $this->_colRef($w['key']);

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
            'LIKE'         => $this->_whereLikePart($col, $w['key'], $w['value'], $data, $base, $idx, false, true),
            'NOT LIKE'     => $this->_whereLikePart($col, $w['key'], $w['value'], $data, $base, $idx, true, true),
            'LIKE_PATTERN' => $this->_whereLikePart($col, $w['key'], $w['value'], $data, $base, $idx, false, false),
            'NOT_LIKE_PATTERN' => $this->_whereLikePart($col, $w['key'], $w['value'], $data, $base, $idx, true, false),
            'REGEXP', 'RLIKE' => $this->_whereRegexpPart($col, $w['key'], $w['value'], $data, $base, $idx, false),
            'NOT REGEXP', 'NOT RLIKE' => $this->_whereRegexpPart($col, $w['key'], $w['value'], $data, $base, $idx, true),
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
            $params[] = $this->_whereParamRef($w['key'], $k, $v);
            $data[$k] = $this->_prepareBindValue($w['key'], $v);
        }

        $operator = $not ? 'NOT IN' : 'IN';

        return ["$col $operator (" . implode(', ', $params) . ')', $data, $idx];
    }

    private function _whereBetweenPart(string $col, array $w, array $data, string $base, int $idx): array
    {
        [$from, $to] = $w['value'];
        $fromKey     = $base . '_from';
        $toKey       = $base . '_to';
        $data[$fromKey] = $this->_prepareBindValue($w['key'], $from);
        $data[$toKey]   = $this->_prepareBindValue($w['key'], $to);

        $between = $w['condition'] === 'NOT BETWEEN' ? 'NOT BETWEEN' : 'BETWEEN';

        return [
            $col . ' ' . $between . ' '
            . $this->_whereParamRef($w['key'], $fromKey, $from)
            . ' AND '
            . $this->_whereParamRef($w['key'], $toKey, $to),
            $data,
            $idx + 1,
        ];
    }

    private function _whereLikePart(
        string $col,
        string $columnKey,
        mixed $value,
        array $data,
        string $base,
        int $idx,
        bool $not,
        bool $wrap,
    ): array {
        $data[$base] = $this->_prepareBindValue($columnKey, $value);
        $operator    = $not ? 'NOT LIKE' : 'LIKE';
        $param       = $this->_whereParamRef($columnKey, $base, $value);
        $expression  = $wrap
            ? "$operator CONCAT('%', $param, '%')"
            : "$operator $param";

        return ["$col $expression", $data, ++$idx];
    }

    private function _whereRegexpPart(
        string $col,
        string $columnKey,
        mixed $value,
        array $data,
        string $base,
        int $idx,
        bool $not,
    ): array {
        $data[$base] = $this->_prepareBindValue($columnKey, $value);
        $operator    = $not ? '!~' : '~';
        $param       = $this->_whereParamRef($columnKey, $base, $value);

        return ["$col $operator $param", $data, ++$idx];
    }

    private function _whereComparePart(string $col, array $w, array $data, string $base, int $idx): array
    {
        $data[$base] = $this->_prepareBindValue($w['key'], $w['value']);
        $param       = $this->_whereParamRef($w['key'], $base, $w['value']);

        if ($w['condition'] === '<=>') {
            return ["$col IS NOT DISTINCT FROM $param", $data, ++$idx];
        }

        $type = $this->_whereColumnType($w['key']);
        if (in_array($type, ['json', 'jsonb'], true) && in_array($w['condition'], ['=', '<=>'], true)) {
            return ['(' . $col . '::jsonb = CAST(' . $param . ' AS jsonb))', $data, ++$idx];
        }

        return ["$col {$w['condition']} $param", $data, ++$idx];
    }

    private function _whereColumnType(string $columnName): ?string
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
            $condition   = str_replace('{' . $key . '}', $this->_whereParamRef(null, $pKey, $value), $condition);
            $data[$pKey] = $this->_prepareBindValue(null, $value);
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
            $expression  = str_replace('{' . $key . '}', $this->_whereParamRef(null, $pKey, $value), $expression);
            $data[$pKey] = $this->_prepareBindValue(null, $value);
        }

        $data[$base] = $this->_prepareBindValue(null, $w['value']);
        $param       = $this->_whereParamRef(null, $base, $w['value']);

        return ['(' . $expression . ') ' . $w['condition'] . ' ' . $param, $data, ++$idx];
    }

    private function _whereColumnPart(array $w, array $data, int $idx): array
    {
        $left  = $this->_qualifiedColumn($w['left']);
        $right = $this->_qualifiedColumn($w['right']);

        return ['(' . $left . ' ' . $w['condition'] . ' ' . $right . ')', $data, $idx];
    }

    private function _whereBitwisePart(array $w, array $data, int $idx): array
    {
        $col = $this->_qualifiedColumn($w['key']);

        if (($w['unary'] ?? false) === true) {
            $valueKey        = 'where_' . $this->whereBaseIndex . $idx . '_value';
            $data[$valueKey] = $this->_prepareBindValue($w['key'], $w['value']);

            return [
                '((~' . $col . ') ' . $w['condition'] . ' ' . $this->_whereParamRef($w['key'], $valueKey, $w['value']) . ')',
                $data,
                ++$idx,
            ];
        }

        $base = 'where_' . $this->whereBaseIndex . $idx . '_operand';
        $data[$base] = $this->_prepareBindValue($w['key'], $w['operand']);

        $expression = '(' . $col . ' ' . $w['bitOperator'] . ' ' . $this->_whereParamRef($w['key'], $base, $w['operand']) . ')';
        $valueKey   = 'where_' . $this->whereBaseIndex . $idx . '_value';
        $data[$valueKey] = $this->_prepareBindValue($w['key'], $w['value']);

        return [
            $expression . ' ' . $w['condition'] . ' ' . $this->_whereParamRef($w['key'], $valueKey, $w['value']),
            $data,
            ++$idx,
        ];
    }

    private function _whereStrcmpPart(array $w, array $data, int $idx): array
    {
        $left      = $this->_qualifiedColumn($w['left']);
        $compareOp = $w['condition'] === '=' ? '=' : $w['condition'];

        if ($w['rightIsValue'] ?? false) {
            $base        = 'where_' . $this->whereBaseIndex . $idx . '_right';
            $data[$base] = $this->_prepareBindValue($w['left'], $w['right']);
            $param       = $this->_whereParamRef($w['left'], $base, $w['right']);

            return ['(' . $left . ' ' . $compareOp . ' ' . $param . ')', $data, ++$idx];
        }

        $right = $this->_qualifiedColumn((string) $w['right']);

        return ['(' . $left . ' ' . $compareOp . ' ' . $right . ')', $data, ++$idx];
    }

    private function _whereRegexpLikePart(array $w, array $data, int $idx): array
    {
        $col        = $this->_qualifiedColumn($w['key']);
        $patternKey = 'where_' . $this->whereBaseIndex . $idx . '_pattern';
        $data[$patternKey] = $this->_prepareBindValue($w['key'], $w['pattern']);
        $param      = $this->_whereParamRef($w['key'], $patternKey, $w['pattern']);
        $operator   = ($w['flags'] !== null && str_contains(strtolower($w['flags']), 'i'))
            ? ($w['not'] ? '!~*' : '~*')
            : ($w['not'] ? '!~' : '~');

        return ['(' . $col . ' ' . $operator . ' ' . $param . ')', $data, ++$idx];
    }

    private function _whereJsonPathPart(array $w, array $data, int $idx): array
    {
        $col      = $this->_qualifiedColumn($w['key']);
        $pathLit  = $this->_pgJsonPathLiteral($w['path']);
        $extract  = ($w['unquoted'] ?? false)
            ? $col . ' #>> ' . $pathLit
            : $col . ' #> ' . $pathLit;
        $valueKey = 'where_' . $this->whereBaseIndex . $idx . '_value';

        if ($w['unquoted'] ?? false) {
            $data[$valueKey] = $this->_pgJsonPathScalar($w['value']);
            $param = 'CAST(:' . $valueKey . ' AS text)';
        } else {
            $data[$valueKey] = $this->_prepareBindValue($w['key'], $w['value']);
            $param = $this->_whereParamRef($w['key'], $valueKey, $w['value']);
        }

        return ['(' . $extract . ' ' . $w['condition'] . ' ' . $param . ')', $data, ++$idx];
    }

    private function _pgJsonPathScalar(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value)   => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default           => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        };
    }

    private function _pgJsonPathLiteral(string $path): string
    {
        $path = str_starts_with($path, '$') ? $path : '$.' . ltrim($path, '.');
        $segments = array_values(array_filter(explode('.', ltrim($path, '$.'))));

        if ($segments === []) {
            return '\'{}\'';
        }

        $formatted = implode(',', array_map(
            static function (string $segment): string {
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) === 1) {
                    return $segment;
                }

                return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $segment) . '"';
            },
            $segments,
        ));

        return '\'{' . $formatted . '}\'';
    }

    private function _whereFullTextPart(array $w, array $data, int $idx): array
    {
        $vectors = implode(' || ', array_map(
            fn (string $name): string => 'to_tsvector(\'simple\', coalesce(' . $this->_colRef($name) . '::text, \'\'))',
            $w['columns'],
        ));
        $queryKey = 'where_' . $this->whereBaseIndex . $idx . '_query';
        $data[$queryKey] = $w['query'];
        $param    = $this->_whereParamRef(null, $queryKey, $w['query']);

        return ['((' . $vectors . ') @@ plainto_tsquery(\'simple\', ' . $param . '))', $data, ++$idx];
    }

    // ── ORDER / GROUP (unified helper) ────────────────────────────────────────

    private function _clauseList(array $items, bool $withDirection = false): string
    {
        return empty($items) ? '' : implode(",\r\n", array_map(
            fn($i) => $this->_colRef($i['key']) . ($withDirection ? ' ' . $i['direction'] : ''),
            $items
        )) . "\r\n";
    }

    protected function _nativeOrder(): string { return $this->_clauseList($this->orders, true); }
    protected function _nativeGroup(): string { return $this->_clauseList($this->groups); }
    protected function _nativeJoin():  string { return ''; }
    public    function join(string $model, string $name, callable $delegate): static { return $this; }

    // ── Column resolution ─────────────────────────────────────────────────────

    private function _tableColumns(): array
    {
        if (isset(self::$columnCache[$this->table])) {
            return self::$columnCache[$this->table];
        }

        if ($this->mode === 'table') {
            $stmt = $this->connection->prepare(
                'SELECT column_name FROM information_schema.columns'
                . ' WHERE table_schema = current_schema() AND table_name = :table'
                . ' ORDER BY ordinal_position'
            );
            $stmt->execute(['table' => $this->table]);
            $cols = array_column($stmt->fetchAll(), 'column_name');
        } else {
            $cols = [];
            foreach ($this->modelData->column as $col) {
                $cols[] = $col->name;
            }
        }

        return self::$columnCache[$this->table] = $cols;
    }

    private function _selectColumnsSql(): string
    {
        if ($this->mode === 'model') {
            $parts = [];
            foreach ($this->modelData->column as $col) {
                $expression = match (true) {
                    Kinds::isSpatial($col->type)       => 'ST_AsText(' . $this->_colRef($col->name) . ')',
                    Kinds::isVector($col->type)        => $this->_vectorToExpression($col->name),
                    Kinds::isBinary($col->type)        => $this->_byteaToExpression($col->name),
                    Kinds::needsPgTextCast($col->type) => $this->_colRef($col->name) . '::text',
                    default                            => $this->_colRef($col->name),
                };
                $parts[] = $expression . ' AS ' . $this->_q($this->table . '.' . $col->name);
            }

            return implode(",\r\n", $parts);
        }

        return self::$selectSqlCache[$this->table] ??= implode(",\r\n", array_map(
            fn($col) => $this->_colRef($col) . ' AS ' . $this->_q($this->table . '.' . $col),
            $this->_tableColumns()
        ));
    }

    private function _sqlValueExpression(string $key): string
    {
        if ($this->mode !== 'model' || !isset($this->modelData->column[$key])) {
            return ':' . $key;
        }

        $column = $this->modelData->column[$key];
        if (Kinds::isSpatial($column->type)) {
            return 'ST_SetSRID(ST_GeomFromText(:' . $key . '), ' . (int) ($column->srid ?? 0) . ')';
        }

        if (Kinds::isVector($column->type)) {
            return $this->_vectorFromExpression($key);
        }

        if (Kinds::isBinary($column->type)) {
            return $this->_byteaFromExpression($key);
        }

        if (Kinds::needsPgTextCast($column->type) || Kinds::isRange($column->type)) {
            return 'CAST(:' . $key . ' AS ' . Kinds::ddlTypePostgresql($column) . ')';
        }

        return ':' . $key;
    }

    protected function _byteaFromExpression(string $paramKey): string
    {
        return 'decode(:' . $paramKey . ", 'hex')";
    }

    protected function _byteaToExpression(string $columnName): string
    {
        return 'encode(' . $this->_colRef($columnName) . ", 'hex')";
    }

    protected function _vectorFromExpression(string $paramKey): string
    {
        return 'CAST(:' . $paramKey . ' AS vector)';
    }

    protected function _vectorToExpression(string $columnName): string
    {
        return $this->_colRef($columnName) . '::text';
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
        $sql = 'SELECT ' . $this->_selectColumnsSql() . "\r\nFROM " . $this->_tableRef() . ' ' . $this->_nativeJoin();

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

    private function _identityColumnName(): ?string
    {
        foreach ($this->modelData->column as $column) {
            if ($column->autoIncrement || $column->primary) {
                return $column->name;
            }
        }

        return null;
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

        $set   = implode(', ', array_map(
            fn($k) => $this->_q($this->_colName($k)) . ' = ' . $this->_sqlValueExpression($k),
            array_keys($data)
        ));
        $sql   = 'UPDATE ' . $this->_tableRef() . ' ' . $this->_nativeJoin() . " SET $set\r\n";

        $where = $this->_nativeWhere($data);
        $data  = $where['data'];
        if ($where['query'] !== '') { $sql .= "\r\nWHERE\r\n" . $where['query']; }

        $this->_prepare($sql)->execute($data);
        return true;
    }

    public function insert(Arr|array $data): mixed
    {
        $data = $this->mode === 'model' ? $this->_prepareModelData((array)$data) : (array)$data;

        if ($this->mode === 'model') {
            $data = $this->_stripNullIdentityColumns($data);
        }

        if (empty($data)) {
            throw new Exception("Insert payload in table {$this->table} can't be empty.");
        }

        $cols = implode(', ', array_map(fn($k) => $this->_q($this->_colName($k)), array_keys($data)));
        $vals = implode(', ', array_map(fn($k) => $this->_sqlValueExpression($k), array_keys($data)));

        $sql = 'INSERT INTO ' . $this->_tableRef() . " ($cols) VALUES ($vals)";

        $identityColumn = null;
        if ($this->mode === 'model') {
            $identityColumn = $this->_identityColumnName();
            if ($identityColumn !== null) {
                $sql .= ' RETURNING ' . $this->_q($identityColumn);
            }
        }

        $stmt = $this->_prepare($sql . ';');
        $stmt->execute($data);

        if ($this->mode === 'model') {
            $id = $identityColumn !== null ? $stmt->fetchColumn() : false;

            return $this->_insertIdentity($id);
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

    protected function _insertIdentity(string|int|false $id): Arr
    {
        if ($id === false || $id === '' || $id === 0 || $id === '0') {
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
