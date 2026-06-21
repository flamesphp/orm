<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Support;

use Flames\Collection\Arr;
use Flames\Collection\ArrImmutable;
use Flames\Orm\Database\Type\Kinds;

/**
 * Cross-driver normalization for logical scalar/document types.
 *
 * @internal
 */
final class ScalarValue
{
    public static function isNull(object $column, mixed $value): bool
    {
        return ($column->nullable ?? false) === true && ($value === null || $value === '');
    }

    public static function columnType(object $column): string
    {
        return Kinds::normalize((string) ($column->type ?? 'string'));
    }

    public static function isIntegerType(object $column): bool
    {
        return in_array(self::columnType($column), [
            'smallint', 'int', 'mediumint', 'tinyint', 'bigint', 'year',
            'serial', 'smallserial', 'bigserial',
        ], true);
    }

    public static function isFloatType(object $column): bool
    {
        return in_array(self::columnType($column), ['float', 'real', 'double'], true);
    }

    public static function isDecimalType(object $column): bool
    {
        return in_array(self::columnType($column), ['decimal', 'numeric', 'money'], true);
    }

    public static function isBooleanType(object $column): bool
    {
        $type = self::columnType($column);

        return in_array($type, ['bool', 'boolean'], true)
            || ($type === 'bit' && max(1, (int) ($column->size ?? 1)) === 1);
    }

    public static function toString(object $column, mixed $value): ?string
    {
        if (self::isNull($column, $value)) {
            return null;
        }

        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? (string) $value->value : $value->name;
        }

        if ($value instanceof ArrImmutable || $value instanceof Arr) {
            return json_encode(ArrValue::toPlain($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        if (is_array($value) || is_object($value)) {
            return json_encode(ArrValue::toPlain($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    public static function toBoolean(object $column, mixed $value): ?bool
    {
        if (self::isNull($column, $value)) {
            return null;
        }

        return match (true) {
            $value === true, $value === 1, $value === 1.0, $value === '1', $value === 'true'  => true,
            $value === false, $value === 0, $value === 0.0, $value === '0', $value === 'false' => false,
            default => (bool) $value,
        };
    }

    public static function toInteger(object $column, mixed $value): ?int
    {
        if ($value === null || $value === false || $value === '') {
            return ($column->nullable ?? false) ? null : 0;
        }

        return (int) $value;
    }

    public static function toFloat(object $column, mixed $value): ?float
    {
        if (self::isNull($column, $value)) {
            return null;
        }

        return (float) $value;
    }

    public static function toDecimalString(object $column, mixed $value): ?string
    {
        if (self::isNull($column, $value)) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return (string) $value;
    }

    /**
     * @return list<string>
     */
    public static function normalizeListItems(mixed $value): array
    {
        if ($value instanceof ArrImmutable) {
            return self::normalizeListItems($value->toArray());
        }

        if ($value instanceof Arr) {
            return self::normalizeListItems($value->toArray());
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    return self::normalizeListItems($decoded);
                }
            }

            if ($trimmed !== '' && str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
                $items = str_getcsv(trim($trimmed, '{}'));
                return array_values(array_map('trim', $items));
            }

            return array_values(array_filter(
                array_map('trim', explode(',', $value)),
                static fn (string $item): bool => $item !== '',
            ));
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $item): string => self::stringify($item), array_values($value));
        }

        return [self::stringify($value)];
    }

    /**
     * @return array<mixed>
     */
    public static function normalizeJson(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        }

        $plain = ArrValue::toPlain($value);

        return is_array($plain) ? $plain : [];
    }

    public static function isListArray(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    public static function stringify(mixed $value): string
    {
        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? (string) $value->value : $value->name;
        }

        return (string) $value;
    }
}
