<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Postgresql;

use Flames\Date\Interval as DateInterval;
use Flames\Orm\Database\Cast\Default\Interval as DefaultInterval;
use Flames\Orm\Database\Cast\Support\PgValue;

class Interval extends DefaultInterval
{
    public static function pos($column, $value, $fromDb = false): DateInterval|string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if ($value instanceof DateInterval) {
            return PgValue::pos($column, $value, static fn (mixed $input): DateInterval => $input);
        }

        $normalized = self::__normalizePgOutput((string) $value);

        return PgValue::pos($column, $normalized, static fn (mixed $input): DateInterval => DateInterval::fromString((string) $input));
    }

    private static function __normalizePgOutput(string $value): string
    {
        $value = trim($value);

        if (str_contains(strtolower($value), 'hour')) {
            return $value;
        }

        if (preg_match('/^(.+?)\s+(\d{1,2}):(\d{2}):(\d{2}(?:\.\d+)?)\s*$/', $value, $matches) !== 1) {
            return $value;
        }

        $parts = trim($matches[1]) !== '' ? [trim($matches[1])] : [];
        $hours = (int) $matches[2];
        $mins  = (int) $matches[3];
        $secs  = (float) $matches[4];

        if ($hours > 0) {
            $parts[] = $hours . ' hour' . ($hours === 1 ? '' : 's');
        }
        if ($mins > 0) {
            $parts[] = $mins . ' min' . ($mins === 1 ? '' : 's');
        }
        if ($secs > 0) {
            $parts[] = rtrim(rtrim((string) $secs, '0'), '.') . ' sec' . ($secs == 1 ? '' : 's');
        }

        return $parts !== [] ? implode(' ', $parts) : '0 seconds';
    }
}
