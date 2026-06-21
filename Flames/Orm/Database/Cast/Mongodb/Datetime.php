<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Mongodb;

use Flames\Orm\Database\Cast\Default\Datetime as DefaultDatetime;
use Flames\Orm\Database\Cast\Support\BsonValue;

class Datetime
{
    public static function pre($column, $value): \MongoDB\BSON\UTCDateTime|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if (BsonValue::isUtcDateTime($value)) {
            return $value;
        }

        $formatted = DefaultDatetime::pre($column, $value);
        if ($formatted === null) {
            return null;
        }

        return BsonValue::toUtcDateTime($formatted);
    }

    public static function pos($column, $value, $fromDb = false): mixed
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $fromUtc = BsonValue::fromUtcDateTime($value);
        if ($fromUtc !== null) {
            return DefaultDatetime::pos($column, $fromUtc->format('Y-m-d H:i:s.u'), $fromDb);
        }

        return DefaultDatetime::pos($column, $value, $fromDb);
    }
}
