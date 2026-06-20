<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Orm\Database\Cast\Default\Support\Temporal;

class Time
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Temporal::formatUtc($value, 'H:i:s');
        }

        $dateTime = Temporal::parseValue($column, $value, 'H:i:s');

        return Temporal::formatUtc($dateTime, 'H:i:s');
    }

    public static function pos($column, $value, $fromDb = false): mixed
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('H:i:s');
        }

        $targetType = match ($column->phpType ?? null) {
            \Flames\Date\Time::class          => \Flames\Date\Time::class,
            \Flames\Date\TimeImmutable::class => \Flames\Date\TimeImmutable::class,
            \DateTime::class                  => \DateTime::class,
            \DateTimeImmutable::class         => \DateTimeImmutable::class,
            default                           => \Flames\Date\Time::class,
        };

        if (is_object($value) && $value instanceof $targetType) {
            return $value;
        }

        return Temporal::instantiate($targetType, 'H:i:s', (string) $value, $fromDb);
    }
}
