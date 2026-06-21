<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Meilisearch;

use Flames\Orm\Database\Cast\Default\Enum as DefaultEnum;

class Enum
{
    public static function pre($column, $value): string|null
    {
        return DefaultEnum::pre($column, $value);
    }

    public static function pos($column, $value, $fromDb = false): string|\UnitEnum|null
    {
        return DefaultEnum::pos($column, $value);
    }
}
