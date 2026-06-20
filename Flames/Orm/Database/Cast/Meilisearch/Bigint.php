<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Cast\Meilisearch;

class Bigint
{
    public static function pre($column, $value): int|string|null
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        if ($column->unsigned === true) {
            $numeric = is_numeric($value) ? $value : 0;

            // Meilisearch range filters compare lexicographically on strings ("10" >= 5 fails).
            if ((float) $numeric > 9007199254740991) {
                return (string) $numeric;
            }

            return (int) $numeric;
        }

        return (int) $value;
    }

    public static function pos($column, $value, $fromDb = false): int|null
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
