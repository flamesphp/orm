<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Meilisearch;

use Flames\Orm\Database\Cast\Default\ArrayList as DefaultArrayList;
use Flames\Orm\Database\Cast\Support\DocumentValue;

class ArrayList
{
    public static function pre($column, $value): array|null
    {
        return DocumentValue::toArray($column, $value);
    }

    public static function pos($column, $value, $fromDb = false): array|null
    {
        return DefaultArrayList::pos($column, $value, $fromDb);
    }
}
