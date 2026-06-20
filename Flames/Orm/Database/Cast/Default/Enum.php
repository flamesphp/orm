<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Orm\Database\Type\EnumValues;

class Enum
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if ($value instanceof \UnitEnum) {
            $value = $value instanceof \BackedEnum ? $value->value : $value->name;
        }

        $value = (string) $value;
        self::__assertAllowed($column, $value);

        return $value;
    }

    public static function pos($column, $value): string|\UnitEnum|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if ($value instanceof \UnitEnum) {
            $stringValue = $value instanceof \BackedEnum ? (string) $value->value : $value->name;
            self::__assertAllowed($column, $stringValue);

            return $value;
        }

        $value = (string) $value;
        self::__assertAllowed($column, $value);

        $enumClass = $column->enumClass ?? null;
        if ($enumClass !== null && enum_exists($enumClass)) {
            return EnumValues::toEnum($enumClass, $value);
        }

        $phpType = $column->phpType ?? null;
        if ($phpType !== null && enum_exists($phpType)) {
            return EnumValues::toEnum($phpType, $value);
        }

        return $value;
    }

    private static function __assertAllowed(object $column, string $value): void
    {
        $values = $column->values ?? null;
        if (!is_array($values) || $values === []) {
            return;
        }

        if (in_array($value, $values, true) === false) {
            throw new \InvalidArgumentException(sprintf(
                'Value "%s" is not allowed for enum column %s.',
                $value,
                $column->name ?? 'unknown',
            ));
        }
    }
}
