<?php
declare(strict_types=1);

namespace Flames\Orm\Database\QueryBuilder\Support;

/**
 * SQL fragments for extended WHERE operators.
 *
 * @internal
 */
final class OperatorSql
{
    public static function mysqlDistinctFrom(string $col, string $param, bool $not): string
    {
        return $not
            ? "($col <=> $param)"
            : "(NOT ($col <=> $param))";
    }

    public static function mysqlIlike(string $col, string $param, bool $not, bool $wrap): string
    {
        $op = $not ? 'NOT LIKE' : 'LIKE';
        $expr = $wrap
            ? "LOWER($col) $op CONCAT('%', LOWER($param), '%')"
            : "LOWER($col) $op LOWER($param)";

        return $expr;
    }

    public static function mysqlRegex(string $col, string $param, string $operator): string
    {
        $not = str_starts_with($operator, '!');
        $ci  = str_contains($operator, '*');
        $regexpOp = $not ? 'NOT REGEXP' : 'REGEXP';

        return "$col $regexpOp $param";
    }

    /** @param list<mixed> $values */
    public static function pgArrayLiteral(array $values): string
    {
        if ($values === []) {
            return 'ARRAY[]::text[]';
        }

        $parts = [];
        foreach ($values as $value) {
            if ($value === null) {
                $parts[] = 'NULL';
                continue;
            }
            if (is_bool($value)) {
                $parts[] = $value ? 'true' : 'false';
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $parts[] = (string) $value;
                continue;
            }
            $parts[] = "'" . str_replace("'", "''", (string) $value) . "'";
        }

        return 'ARRAY[' . implode(', ', $parts) . ']';
    }

    /** @param list<string> $segments */
    public static function pgJsonPathLiteral(array|string $path): string
    {
        if (is_string($path)) {
            $path = str_starts_with($path, '$') ? $path : '$.' . ltrim($path, '.');
            $segments = array_values(array_filter(explode('.', ltrim($path, '$.'))));
        } else {
            $segments = array_values($path);
        }

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

    /** @param list<string> $keys */
    public static function pgJsonKeysLiteral(array $keys): string
    {
        $parts = array_map(
            static fn (string $key): string => "'" . str_replace("'", "''", $key) . "'",
            $keys,
        );

        return 'ARRAY[' . implode(', ', $parts) . ']';
    }

    /** PDO-safe alternative to the jsonb `?` operator. */
    public static function pgJsonHasKey(string $col, string $param): string
    {
        return 'jsonb_exists(CAST(' . $col . ' AS jsonb), ' . $param . ')';
    }

    /** PDO-safe alternative to the jsonb `?&` operator. */
    public static function pgJsonHasAllKeys(string $col, string $keysArrayLiteral): string
    {
        return 'jsonb_exists_all(CAST(' . $col . ' AS jsonb), ' . $keysArrayLiteral . ')';
    }

    /** PDO-safe alternative to the jsonb `?|` operator. */
    public static function pgJsonHasAnyKey(string $col, string $keysArrayLiteral): string
    {
        return 'jsonb_exists_any(CAST(' . $col . ' AS jsonb), ' . $keysArrayLiteral . ')';
    }

    public static function mysqlJsonContains(string $col, string $param): string
    {
        return "JSON_CONTAINS($col, $param)";
    }

    public static function mysqlJsonContainedBy(string $col, string $param): string
    {
        return "JSON_CONTAINS($param, $col)";
    }

    public static function mysqlJsonHasKey(string $col, string $param): string
    {
        return "JSON_CONTAINS_PATH($col, 'one', CONCAT('$.', $param))";
    }
}
