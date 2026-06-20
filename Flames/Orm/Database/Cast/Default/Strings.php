<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Cast\Default;

class Strings
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        return (string) $value;
    }

    public static function pos($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        return (string) $value;
    }
}
