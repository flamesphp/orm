<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Meilisearch;

use Flames\Orm\Database\Cast\Default\Strings as DefaultStrings;
use Flames\Orm\Database\Cast\Support\DocumentValue;

class Strings
{
    public static function pre($column, $value): ?string
    {
        return DocumentValue::toString($column, $value);
    }

    public static function pos($column, $value): ?string
    {
        return DefaultStrings::pos($column, $value);
    }
}
