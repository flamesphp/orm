<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

class StringValue
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        return (string) $value;
    }

    public static function pos($column, $value, $fromDb = false): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
            if ($value === false) {
                return null;
            }
        }

        return (string) $value;
    }
}
