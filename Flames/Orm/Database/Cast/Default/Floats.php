<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Cast\Default;

class Floats
{
    public static function pre($column, $value): float|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        return (float) $value;
    }

    public static function pos($column, $value): float|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        return (float) $value;
    }
}
