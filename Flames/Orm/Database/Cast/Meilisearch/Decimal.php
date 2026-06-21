<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Meilisearch;

use Flames\Orm\Database\Cast\Default\Decimal as DefaultDecimal;
use Flames\Orm\Database\Cast\Support\DocumentValue;

class Decimal
{
    public static function pre($column, $value): float|null
    {
        return DocumentValue::toNumber($column, $value);
    }

    public static function pos($column, $value, $fromDb = false)
    {
        return DefaultDecimal::pos($column, $value);
    }
}
