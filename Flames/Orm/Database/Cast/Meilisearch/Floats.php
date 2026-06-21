<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Meilisearch;

use Flames\Orm\Database\Cast\Default\Floats as DefaultFloats;
use Flames\Orm\Database\Cast\Support\DocumentValue;

class Floats
{
    public static function pre($column, $value): float|null
    {
        $number = DocumentValue::toNumber($column, $value);

        return is_int($number) ? (float) $number : ($number === null ? null : (float) $number);
    }

    public static function pos($column, $value): float|null
    {
        return DefaultFloats::pos($column, $value);
    }
}
