<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Support;

/**
 * @internal
 */
final class PgValue
{
    public static function wantsString(object $column): bool
    {
        return ($column->phpType ?? null) === 'string';
    }

    public static function pos(object $column, mixed $value, callable $parse): mixed
    {
        if (($column->nullable ?? false) === true && $value === null) {
            return null;
        }

        $parsed = $parse($value);

        if (self::wantsString($column) && is_object($parsed)) {
            return (string) $parsed;
        }

        return $parsed;
    }

    public static function pre(object $column, mixed $value, callable $serialize): string|null
    {
        if (($column->nullable ?? false) === true && $value === null) {
            return null;
        }

        return $serialize($value);
    }
}
