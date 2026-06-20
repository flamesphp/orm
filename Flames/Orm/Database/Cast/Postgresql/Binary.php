<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Postgresql;

class Binary
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException('Binary column value must be a string.');
        }

        return bin2hex($value);
    }

    public static function pos($column, $value, $fromDb = false): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
            if ($value === false) {
                return null;
            }
        }

        if (!is_string($value)) {
            return (string) $value;
        }

        if ($fromDb) {
            if ($value === '') {
                return '';
            }

            if (str_starts_with($value, '\\x')) {
                $decoded = hex2bin(substr($value, 2));

                return $decoded === false ? $value : $decoded;
            }

            $decoded = hex2bin($value);

            return $decoded === false ? $value : $decoded;
        }

        if (str_starts_with($value, '\\x')) {
            $decoded = hex2bin(substr($value, 2));

            return $decoded === false ? $value : $decoded;
        }

        if (str_starts_with($value, '\x')) {
            $decoded = hex2bin(substr($value, 2));

            return $decoded === false ? $value : $decoded;
        }

        return $value;
    }
}
