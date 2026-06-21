<?php
declare(strict_types=1);

namespace Flames\Orm\Database\QueryBuilder;

use Flames\Orm\Exception\UnsupportedQueryException;

/**
 * @internal
 */
class Sqlite extends MySql
{
    private const DRIVER = 'sqlite';

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

            $sql = match ($operator) {
                'XOR' => '((' . $sql . ' AND NOT (' . $fragment . ')) OR (NOT (' . $sql . ') AND (' . $fragment . ')))',
                default => $sql . ' ' . $operator . ' ' . $fragment,
            };
        }

        return $sql;
    }

    protected function _whereSimplePart(array $w, array $data, int $idx): array
    {
        if (in_array($w['condition'], ['REGEXP', 'RLIKE', 'NOT REGEXP', 'NOT RLIKE'], true)) {
            throw new UnsupportedQueryException('whereRegexp', self::DRIVER);
        }

        return match ($w['condition']) {
            'IS TRUE'      => [$this->_qualifiedColumn($w['key']) . ' = 1', $data, $idx],
            'IS FALSE'     => [$this->_qualifiedColumn($w['key']) . ' = 0', $data, $idx],
            'IS UNKNOWN'   => ['(' . $this->_qualifiedColumn($w['key']) . ' IS NULL)', $data, $idx],
            'IS NOT TRUE'  => ['(' . $this->_qualifiedColumn($w['key']) . ' IS NULL OR ' . $this->_qualifiedColumn($w['key']) . ' = 0)', $data, $idx],
            'IS NOT FALSE' => ['(' . $this->_qualifiedColumn($w['key']) . ' IS NULL OR ' . $this->_qualifiedColumn($w['key']) . ' = 1)', $data, $idx],
            'IS NOT UNKNOWN' => ['(' . $this->_qualifiedColumn($w['key']) . ' IS NOT NULL)', $data, $idx],
            default        => parent::_whereSimplePart($w, $data, $idx),
        };
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
        $operator    = $not ? 'NOT LIKE' : 'LIKE';
        $expression  = $wrap
            ? "$operator ('%' || :$base || '%') COLLATE NOCASE"
            : "$operator :$base COLLATE NOCASE";

        if ($ilike === false) {
            $operator   = $not ? 'NOT LIKE' : 'LIKE';
            $expression = $wrap
                ? "$operator ('%' || :$base || '%')"
                : "$operator :$base";
        }

        return ["($col $expression)", $data, ++$idx];
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
        $nullSafeEqual = "(($col = $param) OR ($col IS NULL AND $param IS NULL))";
        $expr        = $notDistinct
            ? $nullSafeEqual
            : '(NOT ' . $nullSafeEqual . ')';

        return ['(' . $expr . ')', $data, ++$idx];
    }

    protected function _whereRegexpPart(
        string $col,
        mixed $value,
        array $data,
        string $base,
        int $idx,
        bool $not,
    ): array {
        throw new UnsupportedQueryException('whereRegexp', self::DRIVER);
    }

    protected function _whereComparePart(string $col, array $w, array $data, string $base, int $idx): array
    {
        $data[$base] = $w['value'];
        $type        = $this->_whereColumnType($w['key']);

        if (in_array($type, ['json', 'jsonb'], true) && in_array($w['condition'], ['=', '<=>'], true)) {
            return ['(' . $this->_jsonEqualitySql($col, $base) . ')', $data, ++$idx];
        }

        if ($w['condition'] === '<=>') {
            $param = ':' . $base;

            return ["(($col = $param) OR ($col IS NULL AND $param IS NULL))", $data, ++$idx];
        }

        return ["$col {$w['condition']} :$base", $data, ++$idx];
    }

    protected function _jsonEqualitySql(string $col, string $paramName): string
    {
        return "json($col) = json(:$paramName)";
    }

    protected function _whereBitwisePart(array $w, array $data, int $idx): array
    {
        throw new UnsupportedQueryException('whereBitwise', self::DRIVER);
    }

    protected function _whereStrcmpPart(array $w, array $data, int $idx): array
    {
        throw new UnsupportedQueryException('whereStrcmp', self::DRIVER);
    }

    protected function _whereRegexpLikePart(array $w, array $data, int $idx): array
    {
        throw new UnsupportedQueryException('whereRegexpLike', self::DRIVER);
    }

    protected function _whereJsonPathPart(array $w, array $data, int $idx): array
    {
        $col      = $this->_qualifiedColumn($w['key']);
        $path     = str_starts_with($w['path'], '$') ? $w['path'] : '$.' . ltrim($w['path'], '.');
        $pathLit  = "'" . str_replace("'", "''", $path) . "'";
        $valueKey = 'where_' . $this->whereBaseIndex . $idx . '_value';
        $value    = $w['value'];
        $data[$valueKey] = $value;

        $extract = 'json_extract(' . $col . ', ' . $pathLit . ')';

        if (($w['condition'] ?? '=') === '=' && ($w['unquoted'] ?? false) === false) {
            return ['(json(' . $extract . ') = json(:' . $valueKey . '))', $data, ++$idx];
        }

        if ($w['unquoted'] ?? false) {
            if (is_int($value) || is_float($value)) {
                return ['(CAST(' . $extract . ' AS REAL) ' . $w['condition'] . ' CAST(:' . $valueKey . ' AS REAL))', $data, ++$idx];
            }

            if (is_bool($value)) {
                $data[$valueKey] = $value ? 1 : 0;

                return ['(CAST(' . $extract . ' AS INTEGER) ' . $w['condition'] . ' :' . $valueKey . ')', $data, ++$idx];
            }
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
            throw new UnsupportedQueryException('whereRegex', self::DRIVER);
        }

        if ($domain === 'json') {
            return $this->_whereSqliteJsonOperatorPart($w, $data, $idx, $col);
        }

        if ($domain === 'array' || $domain === 'network' || $domain === 'range' || $domain === 'tsquery') {
            throw new UnsupportedQueryException('whereOperator(' . ($domain ?? $operator) . ')', self::DRIVER);
        }

        if ($domain === 'tsvector' && $operator === '@@') {
            $key = 'where_' . $this->whereBaseIndex . $idx . '_query';
            $data[$key] = $w['right'];

            return ['(' . $col . " LIKE ('%' || :$key || '%'))", $data, ++$idx];
        }

        if ($domain === 'concat') {
            $compare   = $opts['compare'] ?? '=';
            $appendKey = 'where_' . $this->whereBaseIndex . $idx . '_append';
            $equalsKey = 'where_' . $this->whereBaseIndex . $idx . '_equals';
            $data[$appendKey] = $w['right'];
            $data[$equalsKey] = $opts['compareValue'] ?? null;

            return ['((' . $col . ' || :' . $appendKey . ') ' . $compare . ' :' . $equalsKey . ')', $data, ++$idx];
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

    protected function _whereSqliteJsonOperatorPart(array $w, array $data, int $idx, string $col): array
    {
        $operator = $w['compare'];
        $opts     = $w['options'] ?? [];

        if (in_array($operator, ['#>', '#>>'], true)) {
            $path = $opts['path'] ?? '';
            $path = str_starts_with((string) $path, '$') ? $path : '$.' . ltrim((string) $path, '.');
            $pathLit = "'" . str_replace("'", "''", (string) $path) . "'";
            $extract = 'json_extract(' . $col . ', ' . $pathLit . ')';
            $compare = $opts['compare'] ?? '=';
            $key = 'where_' . $this->whereBaseIndex . $idx . '_value';
            $data[$key] = $w['right'];

            return ['(' . $extract . ' ' . $compare . ' :' . $key . ')', $data, ++$idx];
        }

        if ($operator === '?') {
            $path = '$.' . ltrim((string) $w['right'], '.');
            $pathLit = "'" . str_replace("'", "''", $path) . "'";

            return ['(json_type(json_extract(' . $col . ', ' . $pathLit . ')) IS NOT NULL)', $data, ++$idx];
        }

        if (in_array($operator, ['?&', '?|'], true)) {
            throw new UnsupportedQueryException('whereJsonHasAllKeys/whereJsonHasAnyKey', self::DRIVER);
        }

        if (in_array($operator, ['@>', '<@'], true)) {
            $key = 'where_' . $this->whereBaseIndex . $idx . '_doc';
            $data[$key] = is_string($w['right']) ? $w['right'] : json_encode($w['right'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return ['(json(' . $col . ') = json(:' . $key . '))', $data, ++$idx];
        }

        throw new UnsupportedQueryException('whereOperator(json,' . $operator . ')', self::DRIVER);
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
                $columnClauses[] = $this->_qualifiedColumn($column) . " LIKE ('%' || :$key || '%')";
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

    protected function _tableColumns(): array
    {
        if (isset(self::$columnCache[$this->table])) {
            return self::$columnCache[$this->table];
        }

        if ($this->mode === 'table') {
            $cols = array_column(
                $this->connection->query('PRAGMA table_info(`' . $this->table . '`)')->fetchAll(),
                'name',
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
                $parts[] = '`' . $this->table . '`.`' . $col->name . "` AS '" . $this->table . '.' . $col->name . "'";
            }

            return implode(",\r\n", $parts);
        }

        return self::$selectSqlCache[$this->table] ??= implode(",\r\n", array_map(
            fn ($col) => '`' . $this->table . '`.`' . $col . "` AS '" . $this->table . '.' . $col . "'",
            $this->_tableColumns(),
        ));
    }

    protected function _sqlValueExpression(string $key): string
    {
        return ':' . $key;
    }
}
