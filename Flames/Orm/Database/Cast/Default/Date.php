<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Orm\Database\Cast\Default\Support\Temporal;

class Date
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Temporal::formatUtc($value, 'Y-m-d');
        }

        $dateTime = Temporal::parseValue($column, $value, 'Y-m-d');

        return Temporal::formatUtc($dateTime, 'Y-m-d');
    }

    public static function pos($column, $value, $fromDb = false): mixed
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }

        $targetType = match ($column->phpType ?? null) {
            \Flames\Date\Date::class          => \Flames\Date\Date::class,
            \Flames\Date\DateImmutable::class => \Flames\Date\DateImmutable::class,
            \DateTime::class                  => \DateTime::class,
            \DateTimeImmutable::class         => \DateTimeImmutable::class,
            default                           => \Flames\Date\Date::class,
        };

        if (is_object($value) && $value instanceof $targetType) {
            return $value;
        }

        return Temporal::instantiate($targetType, 'Y-m-d', (string) $value, $fromDb);
    }
}
