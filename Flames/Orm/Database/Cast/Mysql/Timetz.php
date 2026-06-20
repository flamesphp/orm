<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Mysql;

use Flames\Date\Time;
use Flames\Date\TimeImmutable;
use Flames\Date\TimeZone;
use Flames\Orm\Database\Cast\Default\Time as DefaultTime;

class Timetz
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $wallClock = self::__wallClock($value);

        return $wallClock ?? DefaultTime::pre($column, $value);
    }

    public static function pos($column, $value, $fromDb = false): mixed
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $string = self::__wallClock($value) ?? (string) $value;

        return match ($column->phpType ?? null) {
            TimeImmutable::class => new TimeImmutable($string, TimeZone::getUtc()),
            Time::class          => new Time($string, TimeZone::getUtc()),
            default              => $string,
        };
    }

    private static function __wallClock(mixed $value): string|null
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }

        if (!is_string($value)) {
            return null;
        }

        if (preg_match('/^(\d{1,2}:\d{2}(?::\d{2})?)/', trim($value), $matches) !== 1) {
            return null;
        }

        $time = $matches[1];

        return strlen($time) === 5 ? $time . ':00' : $time;
    }
}
