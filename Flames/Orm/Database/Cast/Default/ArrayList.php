<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Orm\Database\Cast\Support\ArrValue;
use Flames\Orm\Database\Cast\Support\ScalarValue;

class ArrayList
{
    public static function pre($column, $value): string|null
    {
        if (ScalarValue::isNull($column, $value)) {
            return null;
        }

        $list = array_values(ScalarValue::normalizeListItems($value));

        return json_encode($list, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function pos($column, $value, $fromDb = false): array|null
    {
        if (ScalarValue::isNull($column, $value)) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? array_values($decoded) : [];
        }

        if (ArrValue::wantsArray($column)) {
            return array_values(ScalarValue::normalizeListItems($value));
        }

        return array_values(ScalarValue::normalizeListItems($value));
    }
}
