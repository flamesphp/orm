<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Date\TimeZone;
use Flames\Orm\Database\Cast\Default\Support\Temporal;

class Date
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $dateTime = $value instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($value)
            : Temporal::parseValue($column, $value, 'Y-m-d');

        return $dateTime
            ->setTimezone(TimeZone::getDefault())
            ->setTime(0, 0, 0)
            ->setTimezone(TimeZone::getUtc())
            ->format('Y-m-d');
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

        $instance = self::__instantiate($targetType, (string) $value, $fromDb ? TimeZone::getUtc() : null);

        if ($fromDb) {
            return self::__applyDefaultTimezone($instance);
        }

        return $instance;
    }

    private static function __instantiate(
        string $targetType,
        string $value,
        \DateTimeZone|null $timezone = null,
    ): object {
        return match ($targetType) {
            \Flames\Date\Date::class          => new \Flames\Date\Date($value, $timezone),
            \Flames\Date\DateImmutable::class => new \Flames\Date\DateImmutable($value, $timezone),
            \DateTime::class                  => new \DateTime($value, $timezone),
            \DateTimeImmutable::class         => new \DateTimeImmutable($value, $timezone),
            default                           => new \Flames\Date\Date($value, $timezone),
        };
    }

    private static function __applyDefaultTimezone(object $dateTime): object
    {
        if (method_exists($dateTime, 'shiftTimezone')) {
            return $dateTime->shiftTimezone(TimeZone::getDefault());
        }

        return $dateTime->setTimezone(TimeZone::getDefault());
    }
}
