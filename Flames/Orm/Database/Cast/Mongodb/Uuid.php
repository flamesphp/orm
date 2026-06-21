<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Mongodb;

use Flames\Orm\Database\Cast\Default\Uuid as DefaultUuid;
use Flames\Orm\Database\Cast\Support\BsonValue;

class Uuid extends DefaultUuid
{
    public static function pre($column, $value): string|null
    {
        if (BsonValue::isObjectId($value)) {
            return BsonValue::fromObjectId($value);
        }

        return parent::pre($column, $value);
    }
}
