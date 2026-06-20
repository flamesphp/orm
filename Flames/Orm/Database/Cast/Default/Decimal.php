<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

class Decimal
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return (string) $value;
    }

    public static function pos($column, $value): string|float|null
    {
        if ($column->nullable === true && $value === null) {
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
