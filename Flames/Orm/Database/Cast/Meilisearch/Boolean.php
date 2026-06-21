<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Meilisearch;

use Flames\Orm\Database\Cast\Default\Boolean as DefaultBoolean;
use Flames\Orm\Database\Cast\Support\DocumentValue;

class Boolean
{
    public static function pre($column, $value): bool|null
    {
        return DocumentValue::toBoolean($column, $value);
    }

    public static function pos($column, $value): mixed
    {
        return DefaultBoolean::pos($column, $value);
    }
}
