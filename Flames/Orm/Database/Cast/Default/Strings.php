<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Orm\Database\Cast\Support\ScalarValue;

class Strings
{
    public static function pre($column, $value): string|null
    {
        return ScalarValue::toString($column, $value);
    }

    public static function pos($column, $value): string|null
    {
        if (ScalarValue::isNull($column, $value)) {
            return null;
        }

        return ScalarValue::stringify($value);
    }
}
