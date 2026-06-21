<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Orm\Database\Cast\Support\ScalarValue;

class Decimal
{
    public static function pre($column, $value): string|null
    {
        return ScalarValue::toDecimalString($column, $value);
    }

    public static function pos($column, $value): string|float|null
    {
        if (ScalarValue::isNull($column, $value)) {
            return null;
        }

        $string = (string) $value;

        return match ($column->phpType ?? null) {
            'string' => $string,
            'float'  => (float) $string,
            'int'    => (int) $string,
            default  => $string,
        };
    }
}
