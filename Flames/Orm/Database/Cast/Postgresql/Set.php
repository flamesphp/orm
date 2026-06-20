<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Postgresql;

use Flames\Collection\Arr;
use Flames\Collection\ArrImmutable;
use Flames\Orm\Database\Cast\Default\Set as DefaultSet;
use Flames\Orm\Database\Cast\Support\ArrValue;

class Set
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $items = self::__itemsFromValue($value);

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
        if ($column->nullable === true && $value === null) {
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
    private static function __itemsFromValue(mixed $value): array
    {
        if ($value instanceof ArrImmutable) {
            $value = $value->toArray();
        }

        if ($value instanceof Arr) {
            $value = $value->toArray();
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && str_starts_with($trimmed, '{')) {
                return self::__parsePgArray($trimmed);
            }

            return array_values(array_filter(
                array_map('trim', explode(',', $value)),
                static fn (string $item): bool => $item !== '',
            ));
        }

        if (!is_array($value)) {
            if ($value instanceof \UnitEnum) {
                return [$value instanceof \BackedEnum ? (string) $value->value : $value->name];
            }

            return [(string) $value];
        }

        return array_map(static function (mixed $item): string {
            if ($item instanceof \UnitEnum) {
                return $item instanceof \BackedEnum ? (string) $item->value : $item->name;
            }

            return (string) $item;
        }, array_values($value));
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
