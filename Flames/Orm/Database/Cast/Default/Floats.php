<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Orm\Database\Cast\Support\ScalarValue;

class Floats
{
    public static function pre($column, $value): float|null
    {
        return ScalarValue::toFloat($column, $value);
    }

    public static function pos($column, $value): float|null
    {
        if (ScalarValue::isNull($column, $value)) {
            return null;
        }

        return (float) $value;
    }
}
