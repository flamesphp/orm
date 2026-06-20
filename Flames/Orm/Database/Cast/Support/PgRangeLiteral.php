<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Support;

use Flames\Collection\Range;

/**
 * PostgreSQL range literal serialization (`[10,100)`, etc.).
 *
 * @internal
 */
final class PgRangeLiteral
{
    public static function parse(string $value): Range
    {
        $value = trim($value);
        $lowerInclusive = str_starts_with($value, '[');
        $upperInclusive = str_ends_with($value, ']');
        $value          = trim($value, '[()]');

        [$lower, $upper] = array_map('trim', explode(',', $value, 2) + [null, null]);

        return new Range(
            $lower === '' || strtolower((string) $lower) === 'null' ? null : trim((string) $lower, '"'),
            $upper === '' || strtolower((string) $upper) === 'null' ? null : trim((string) $upper, '"'),
            $lowerInclusive,
            $upperInclusive,
        );
    }

    public static function format(Range $range): string
    {
        $left  = ($range->lowerInclusive ? '[' : '(') . self::__quoteBound($range->lower) . ',';
        $right = self::__quoteBound($range->upper) . ($range->upperInclusive ? ']' : ')');

        return $left . $right;
    }

    private static function __quoteBound(mixed $bound): string
    {
        if ($bound === null) {
            return '';
        }

        if (is_numeric($bound)) {
            return (string) $bound;
        }

        return '"' . str_replace('"', '\\"', (string) $bound) . '"';
    }
}
