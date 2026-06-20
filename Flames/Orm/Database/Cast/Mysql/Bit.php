<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Mysql;

use Flames\Orm\Database\Cast\Default\Bit as DefaultBit;
use Flames\Orm\Database\Cast\Default\Boolean;

class Bit
{
    public static function pre($column, $value): int|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $bits = max(1, (int) ($column->size ?? 1));

        if ($bits === 1) {
            return Boolean::pre($column, $value);
        }

        $packed = DefaultBit::pre($column, $value);

        return $packed === null ? null : DefaultBit::pos($column, $packed);
    }

    public static function pos($column, $value): bool|int|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $bits = max(1, (int) ($column->size ?? 1));

        if ($bits === 1) {
            return Boolean::pos($column, $value);
        }

        return DefaultBit::pos($column, $value);
    }
}
