<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

class Binary
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException('Binary column value must be a string.');
        }

        return $value;
    }

    public static function pos($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        return is_string($value) ? $value : (string) $value;
    }
}
