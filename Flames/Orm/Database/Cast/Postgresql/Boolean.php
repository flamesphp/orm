<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Postgresql;

class Boolean
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        return self::__toPgLiteral($value) ? 'true' : 'false';
    }

    public static function pos($column, $value, $fromDb = false): bool|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        return match (true) {
            $value === true, $value === 't', $value === 'true', $value === 1, $value === '1' => true,
            $value === false, $value === 'f', $value === 'false', $value === 0, $value === '0', $value === '' => false,
            default => (bool) $value,
        };
    }

    private static function __toPgLiteral(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 't';
    }
}
