<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Date\Interval as DateInterval;
use Flames\Orm\Database\Cast\Support\PgValue;

class Interval
{
    public static function pre($column, $value): string|null
    {
        return PgValue::pre($column, $value, static function (mixed $input): string {
            if ($input instanceof DateInterval) {
                return (string) $input;
            }

            if ($input instanceof \DateInterval) {
                return self::__fromDateInterval($input);
            }

            return (string) $input;
        });
    }

    public static function pos($column, $value, $fromDb = false): DateInterval|string|null
    {
        return PgValue::pos($column, $value, static function (mixed $input): DateInterval {
            if ($input instanceof DateInterval) {
                return $input;
            }

            return DateInterval::fromString((string) $input);
        });
    }

    private static function __fromDateInterval(\DateInterval $interval): string
    {
        $parts = [];

        if ($interval->y > 0) {
            $parts[] = $interval->y . ' year' . ($interval->y === 1 ? '' : 's');
        }
        if ($interval->m > 0) {
            $parts[] = $interval->m . ' mon' . ($interval->m === 1 ? '' : 's');
        }
        if ($interval->d > 0) {
            $parts[] = $interval->d . ' day' . ($interval->d === 1 ? '' : 's');
        }
        if ($interval->h > 0) {
            $parts[] = $interval->h . ' hour' . ($interval->h === 1 ? '' : 's');
        }
        if ($interval->i > 0) {
            $parts[] = $interval->i . ' min' . ($interval->i === 1 ? '' : 's');
        }
        if ($interval->s > 0 || $interval->f > 0) {
            $seconds = $interval->s + $interval->f;
            $parts[] = rtrim(rtrim((string) $seconds, '0'), '.') . ' sec' . ($seconds == 1 ? '' : 's');
        }

        return $parts !== [] ? implode(' ', $parts) : '0 seconds';
    }
}
