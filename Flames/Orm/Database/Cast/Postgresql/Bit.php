<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Postgresql;

class Bit
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        return Boolean::pre($column, $value);
    }

    public static function pos($column, $value, $fromDb = false): bool|null
    {
        return Boolean::pos($column, $value, $fromDb);
    }
}
