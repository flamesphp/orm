<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Collection\Arr;
use Flames\Collection\ArrImmutable;
use Flames\Orm\Database\Type\EnumValues;
use Flames\Orm\Database\Cast\Support\ArrValue;

class Set
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $items = self::__normalizeItems($value);

        foreach ($items as $item) {
            self::__assertAllowed($column, $item);
        }

        return implode(',', $items);
    }

    public static function pos($column, $value): Arr|ArrImmutable|array|string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $items = self::__normalizeItems($value);

        foreach ($items as $item) {
            self::__assertAllowed($column, $item);
        }

        if (($column->phpType ?? null) === 'string') {
            return implode(',', $items);
        }

        $enumClass = $column->enumClass ?? null;
        if ($enumClass !== null && enum_exists($enumClass)) {
            $mapped = array_map(
                static fn (string $item): \UnitEnum => EnumValues::toEnum($enumClass, $item),
                $items,
            );

            return ArrValue::wantsArray($column) ? $mapped : ArrValue::wrap($column, $mapped);
        }

        return ArrValue::wrap($column, $items);
    }

    /**
     * @return list<string>
     */
    private static function __normalizeItems(mixed $value): array
    {
        if ($value instanceof ArrImmutable) {
            return self::__normalizeItems($value->toArray());
        }

        if ($value instanceof Arr) {
            return self::__normalizeItems($value->toArray());
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    return self::__normalizeItems($decoded);
                }
            }

            return array_values(array_filter(
                array_map('trim', explode(',', $value)),
                static fn (string $item): bool => $item !== '',
            ));
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $item): string => self::__stringify($item), $value);
        }

        return [self::__stringify($value)];
    }

    private static function __stringify(mixed $value): string
    {
        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? (string) $value->value : $value->name;
        }

        return (string) $value;
    }

    private static function __assertAllowed(object $column, string $value): void
    {
        $values = $column->values ?? null;
        if (!is_array($values) || $values === []) {
            return;
        }

        if (in_array($value, $values, true) === false) {
            throw new \InvalidArgumentException(sprintf(
                'Value "%s" is not allowed for set column %s.',
                $value,
                $column->name ?? 'unknown',
            ));
        }
    }
}
