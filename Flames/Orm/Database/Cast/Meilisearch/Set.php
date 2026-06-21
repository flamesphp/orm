<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Meilisearch;

use Flames\Orm\Database\Cast\Default\Set as DefaultSet;
use Flames\Orm\Database\Cast\Support\DocumentValue;

class Set
{
    public static function pre($column, $value): array|string|null
    {
        if (($column->phpType ?? null) === 'string') {
            return DefaultSet::pre($column, $value);
        }

        return DocumentValue::toArray($column, $value);
    }

    public static function pos($column, $value)
    {
        return DefaultSet::pos($column, $value);
    }
}
