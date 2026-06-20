<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Postgresql;

use Flames\Collection\Range as RangeValue;
use Flames\Orm\Database\Cast\Default\Range as DefaultRange;
use Flames\Orm\Database\Cast\Support\PgRangeLiteral;
use Flames\Orm\Database\Cast\Support\PgValue;

class Range extends DefaultRange
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if (is_array($value)) {
            return PgRangeLiteral::format(RangeValue::fromArray($value));
        }

        if ($value instanceof RangeValue) {
            return PgRangeLiteral::format($value);
        }

        if (is_string($value) && str_starts_with(trim($value), '{')) {
            return PgRangeLiteral::format(RangeValue::parse($value));
        }

        return PgRangeLiteral::format(RangeValue::parse($value));
    }

    public static function pos($column, $value, $fromDb = false): RangeValue|string|null
    {
        return PgValue::pos($column, $value, static function (mixed $input): RangeValue {
            if ($input instanceof RangeValue) {
                return $input;
            }

            if (is_array($input)) {
                return RangeValue::fromArray($input);
            }

            $string = trim((string) $input);

            if (str_starts_with($string, '[') || str_starts_with($string, '(')) {
                return PgRangeLiteral::parse($string);
            }

            return RangeValue::parse($input);
        });
    }
}
