<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Meilisearch;

use Flames\Orm\Database\Cast\Default\Bigint as DefaultBigint;
use Flames\Orm\Database\Cast\Support\DocumentValue;

class Bigint
{
    public static function pre($column, $value): int|string|null
    {
        $number = DocumentValue::toNumber($column, $value);

        return is_float($number) ? (int) $number : $number;
    }

    public static function pos($column, $value, $fromDb = false): int|null
    {
        return DefaultBigint::pos($column, $value, $fromDb);
    }
}
