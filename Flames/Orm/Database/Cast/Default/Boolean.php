<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Orm\Database\Cast\Support\ScalarValue;

class Boolean
{
    public static function pre($column, $value): int|null
    {
        if (ScalarValue::isNull($column, $value)) {
            return null;
        }

        return ScalarValue::toBoolean($column, $value) === true ? 1 : 0;
    }

    public static function pos($column, $value): mixed
    {
        if (ScalarValue::isNull($column, $value)) {
            return null;
        }

        return match (true) {
            $value === 1    || $value === 1.0   || $value === '1'  || $value === 'true'  => true,
            $value === 0    || $value === 0.0   || $value === '0'  || $value === 'false' => false,
            $value === -1   || $value === -1.0  || $value === '-1'                       =>
                $column->nullable === false ? $column->default : null,
            default => ScalarValue::toBoolean($column, $value),
        };
    }
}
