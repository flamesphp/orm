<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Collection\Uuid as UuidValue;
use Flames\Orm\Database\Cast\Support\PgValue;

class Uuid
{
    public static function pre($column, $value): string|null
    {
        return PgValue::pre($column, $value, static fn (mixed $input): string => (string) UuidValue::parse($input));
    }

    public static function pos($column, $value, $fromDb = false): UuidValue|string|null
    {
        return PgValue::pos($column, $value, static fn (mixed $input): UuidValue => UuidValue::parse($input));
    }
}
