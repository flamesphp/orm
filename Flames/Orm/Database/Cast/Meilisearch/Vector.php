<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Meilisearch;

use Flames\Orm\Database\Cast\Default\Vector as DefaultVector;

class Vector
{
    public static function pre($column, $value): array|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $encoded = DefaultVector::pre($column, $value);
        if ($encoded === null) {
            return null;
        }

        return json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
    }

    public static function pos($column, $value, $fromDb = false)
    {
        return DefaultVector::pos($column, $value, $fromDb);
    }
}
