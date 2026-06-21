<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Mongodb;

use Flames\Orm\Database\Cast\Default\Strings as DefaultStrings;
use Flames\Orm\Database\Cast\Support\BsonValue;
use Flames\Orm\Database\Cast\Support\DocumentValue;

class Strings
{
    public static function pre($column, $value): mixed
    {
        if (BsonValue::isObjectId($value)) {
            return $value;
        }

        if (
            ($column->primary ?? false) === true
            && is_string($value)
            && preg_match('/^[a-f\d]{24}$/i', $value) === 1
        ) {
            return BsonValue::toObjectId($value);
        }

        return DocumentValue::toString($column, $value);
    }

    public static function pos($column, $value, $fromDb = false): ?string
    {
        if (BsonValue::isObjectId($value)) {
            return BsonValue::fromObjectId($value);
        }

        return DefaultStrings::pos($column, $value, $fromDb);
    }
}
