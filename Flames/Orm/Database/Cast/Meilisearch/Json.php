<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Cast\Meilisearch;

use Flames\Orm\Database\Cast\Default\Json as DefaultJson;
use Flames\Orm\Database\Cast\Support\ArrValue;

class Json
{
    public static function pre($column, $value): array|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if (is_string($value)) {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }

        return ArrValue::toPlain($value);
    }

    public static function pos($column, $value, $fromDb = false)
    {
        return DefaultJson::pos($column, $value, $fromDb);
    }
}
