<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Collection\Range as RangeValue;
use Flames\Orm\Database\Cast\Support\PgValue;

class Range
{
    public static function pre($column, $value): string|null
    {
        return PgValue::pre($column, $value, static function (mixed $input): string {
            if (is_array($input)) {
                return RangeValue::fromArray($input)->toJson();
            }

            if (is_string($input) && str_starts_with(trim($input), '{')) {
                json_decode($input, flags: JSON_THROW_ON_ERROR);

                return $input;
            }

            if ($input instanceof RangeValue) {
                return $input->toJson();
            }

            return RangeValue::parse($input)->toJson();
        });
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

            return RangeValue::parse($input);
        });
    }
}
