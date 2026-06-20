<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

class Timetz extends Time
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if ($value instanceof \Flames\Date\Time || $value instanceof \Flames\Date\TimeImmutable) {
            return $value->format('H:i:sP');
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:sP');
        }

        return (string) $value;
    }

    public static function pos($column, $value, $fromDb = false): mixed
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $string = (string) $value;

        return match ($column->phpType ?? null) {
            \Flames\Date\Time::class          => new \Flames\Date\Time($string),
            \Flames\Date\TimeImmutable::class => new \Flames\Date\TimeImmutable($string),
            default                           => $string,
        };
    }
}
