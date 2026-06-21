<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Collection\Arr;
use Flames\Collection\ArrImmutable;
use Flames\Orm\Database\Cast\Support\ArrValue;
use Flames\Orm\Database\Cast\Support\ScalarValue;

class Json
{
    public static function pre($column, $value): string|null
    {
        if (ScalarValue::isNull($column, $value)) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

            return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        return json_encode(
            ScalarValue::normalizeJson($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
    }

    public static function pos($column, $value, $fromDb = false): Arr|ArrImmutable|array|null
    {
        if (ScalarValue::isNull($column, $value)) {
            return null;
        }

        if ($value instanceof ArrImmutable) {
            return ArrValue::fit($column, $value);
        }

        if ($value instanceof Arr) {
            if (ArrValue::wantsArray($column)) {
                return ArrValue::toPlain($value);
            }

            return ArrValue::fit($column, $value);
        }

        if (is_array($value)) {
            return ArrValue::wrap($column, $value);
        }

        if (is_object($value)) {
            return ArrValue::wrap($column, (array) $value);
        }

        $decoded = json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);

        return ArrValue::wrap($column, $decoded);
    }
}
