<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Mysql;

use Flames\Orm\Database\Cast\Default\Varbit as DefaultVarbit;

class Varbit
{
    public static function pre($column, $value): int|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $bits      = max(1, (int) ($column->size ?? 64));
        $bitString = (string) DefaultVarbit::pre($column, $value);
        $bitString = str_pad(substr($bitString, 0, $bits), $bits, '0', STR_PAD_LEFT);

        return (int) bindec($bitString);
    }

    public static function pos($column, $value, $fromDb = false): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $bits = max(1, (int) ($column->size ?? 64));

        if (is_string($value) && preg_match('/^[01]+$/', $value) === 1) {
            return str_pad(substr($value, 0, $bits), $bits, '0', STR_PAD_LEFT);
        }

        return str_pad(decbin((int) $value), $bits, '0', STR_PAD_LEFT);
    }
}
