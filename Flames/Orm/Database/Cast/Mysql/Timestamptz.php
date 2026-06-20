<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Mysql;

use Flames\Orm\Database\Cast\Default\Datetime;
use Flames\Orm\Database\Cast\Default\Timestamp;

class Timestamptz
{
    public static function pre($column, $value): string|null
    {
        return Timestamp::pre($column, $value);
    }

    public static function pos($column, $value, $fromDb = false): mixed
    {
        return Datetime::pos($column, $value, $fromDb);
    }
}
