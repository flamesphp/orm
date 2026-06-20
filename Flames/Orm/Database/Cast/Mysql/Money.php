<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Mysql;

use Flames\Orm\Database\Cast\Default\StringValue;

class Money
{
    public static function pre($column, $value): string|null
    {
        return StringValue::pre($column, $value);
    }

    public static function pos($column, $value, $fromDb = false): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $string = (string) $value;

        if (str_contains($string, '.')) {
            $string = rtrim(rtrim($string, '0'), '.');
        }

        return $string === '' ? '0' : $string;
    }
}
