<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Collection\Arr;
use Flames\Collection\ArrImmutable;
use Flames\Orm\Database\Type\EnumValues;
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

        foreach ($items as $item) {
            self::__assertAllowed($column, $item);
        }

        return implode(',', $items);
    }

    public static function pos($column, $value): Arr|ArrImmutable|array|string|null
    {
        if (ScalarValue::isNull($column, $value)) {
            return null;
        }

        $items = ScalarValue::normalizeListItems($value);

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
