<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Meilisearch;

use Flames\Orm\Database\Cast\Default\Json as DefaultJson;
use Flames\Orm\Database\Cast\Support\DocumentValue;

class Json
{
    public static function pre($column, $value): array|null
    {
        return DocumentValue::toObject($column, $value);
    }

    public static function pos($column, $value, $fromDb = false)
    {
        return DefaultJson::pos($column, $value, $fromDb);
    }
}
