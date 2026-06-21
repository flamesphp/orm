<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Postgresql;

use Flames\Collection\Arr;
use Flames\Collection\ArrImmutable;
use Flames\Orm\Database\Cast\Default\Set as DefaultSet;
use Flames\Orm\Database\Cast\Support\ArrValue;
use Flames\Orm\Database\Cast\Support\ScalarValue;

class Set
{
    public static function pre($column, $value): string|null
    {
        if (ScalarValue::isNull($column, $value)) {
            return null;
        }

        $items = ScalarValue::normalizeListItems($value);

        if ($items === []) {
            return '{}';
        }

        $quoted = array_map(
            static fn (string $item): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $item) . '"',
            $items,
        );

        return '{' . implode(',', $quoted) . '}';
    }

    public static function pos($column, $value, $fromDb = false)
    {
        if (ScalarValue::isNull($column, $value)) {
            return null;
        }

        if (is_array($value)) {
            return DefaultSet::pos($column, $value, $fromDb);
        }

        if ($value instanceof Arr || $value instanceof ArrImmutable) {
            return DefaultSet::pos($column, $value, $fromDb);
        }

        if (is_string($value) && str_starts_with(trim($value), '{')) {
            $items = self::__parsePgArray($value);

            return ArrValue::wrap($column, $items);
        }

        return DefaultSet::pos($column, $value, $fromDb);
    }

    /**
     * @return list<string>
     */
    private static function __parsePgArray(string $value): array
    {
        $trimmed = trim($value, "{} \t\n\r");
        if ($trimmed === '') {
            return [];
        }

        $items = str_getcsv($trimmed);

        return array_values(array_map(static fn (string $item): string => trim($item), $items));
    }
}
