<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Cast\Default;

class Bigint
{
    public static function pre($column, $value): int|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        return (int) $value;
    }

    public static function pos($column, $value): int|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        return (int) $value;
    }
}