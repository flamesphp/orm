<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Cast\Default;

class Boolean
{
    public static function pre($column, $value): int|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        return $value === true ? 1 : 0;
    }

    public static function pos($column, $value): mixed
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        return match (true) {
            $value === 1    || $value === 1.0   || $value === '1'  || $value === 'true'  => true,
            $value === 0    || $value === 0.0   || $value === '0'  || $value === 'false' => false,
            $value === -1   || $value === -1.0  || $value === '-1'                       =>
                $column->nullable === false ? $column->default : null,
            default => (bool) $value,
        };
    }
}