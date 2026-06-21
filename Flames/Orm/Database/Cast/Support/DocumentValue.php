<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Support;

/**
 * Native Meilisearch document field values (string, number, bool, array, object, null).
 *
 * @internal
 */
final class DocumentValue
{
    public static function toString(object $column, mixed $value): ?string
    {
        return ScalarValue::toString($column, $value);
    }

    public static function toNumber(object $column, mixed $value): int|float|string|null
    {
        if (ScalarValue::isNull($column, $value)) {
            return null;
        }

        if (ScalarValue::isIntegerType($column)) {
            $numeric = ScalarValue::toInteger($column, $value);
            if ($numeric === null) {
                return null;
            }

            if (($column->unsigned ?? false) === true && $numeric > 9007199254740991) {
                return (string) $numeric;
            }

            return $numeric;
        }

        return ScalarValue::toFloat($column, $value);
    }

    public static function toBoolean(object $column, mixed $value): ?bool
    {
        return ScalarValue::toBoolean($column, $value);
    }

    /**
     * @return list<mixed>|null
     */
    public static function toArray(object $column, mixed $value): ?array
    {
        if (ScalarValue::isNull($column, $value)) {
            return null;
        }

        return ScalarValue::normalizeListItems($value);
    }

    /**
     * @return array<mixed>|null
     */
    public static function toObject(object $column, mixed $value): ?array
    {
        if (ScalarValue::isNull($column, $value)) {
            return null;
        }

        return ScalarValue::normalizeJson($value);
    }
}
