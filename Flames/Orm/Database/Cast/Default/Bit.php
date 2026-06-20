<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

class Bit
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $bits     = max(1, (int) ($column->size ?? 1));
        $intValue = self::__toInt($value, $bits);

        return self::__pack($intValue, $bits);
    }

    public static function pos($column, $value): bool|int|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $bits = max(1, (int) ($column->size ?? 1));

        if (is_bool($value)) {
            return $bits === 1 ? $value : ($value ? 1 : 0);
        }

        if (is_string($value)) {
            return $bits === 1
                ? (ord($value[0] ?? "\0") & 1) === 1
                : self::__unpack($value, $bits);
        }

        if ($bits === 1) {
            return ((int) $value) === 1;
        }

        return (int) $value;
    }

    private static function __toInt(mixed $value, int $bits): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_string($value)) {
            if ($value === '' || strlen($value) === 1) {
                return $bits === 1
                    ? (ord($value[0] ?? "\0") & 1)
                    : self::__unpack($value, $bits);
            }

            return self::__unpack($value, $bits);
        }

        return (int) $value;
    }

    private static function __pack(int $value, int $bits): string
    {
        $bytes = (int) max(1, ceil($bits / 8));
        $mask  = $bits >= 64 ? PHP_INT_MAX : ((1 << $bits) - 1);
        $value &= $mask;

        $binary = '';
        for ($index = $bytes - 1; $index >= 0; $index--) {
            $binary .= chr(($value >> ($index * 8)) & 0xFF);
        }

        return $binary;
    }

    private static function __unpack(string $value, int $bits): int
    {
        $bytes = (int) max(1, ceil($bits / 8));
        $value = str_pad(substr($value, 0, $bytes), $bytes, "\0");

        $int = 0;
        for ($index = 0; $index < $bytes; $index++) {
            $int = ($int << 8) | ord($value[$index]);
        }

        $mask = $bits >= 64 ? PHP_INT_MAX : ((1 << $bits) - 1);

        return $int & $mask;
    }
}
