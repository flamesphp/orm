<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default\Support;

use Flames\Date\TimeZone;

/**
 * @internal
 */
final class Temporal
{
    public static function formatUtc(\DateTimeInterface $dateTime, string $format): string
    {
        return \DateTimeImmutable::createFromInterface($dateTime)
            ->setTimezone(TimeZone::getUtc())
            ->format($format);
    }

    public static function instantiate(
        string $targetType,
        string $format,
        string $value,
        bool $fromDb,
    ): object {
        $timezone = $fromDb ? TimeZone::getUtc() : null;
        $instance = match ($targetType) {
            \Flames\Date\Date::class,
            \Flames\Date\DateImmutable::class => new $targetType($value, $timezone),
            \Flames\Date\Time::class,
            \Flames\Date\TimeImmutable::class => new $targetType($value, $timezone),
            \Flames\Date\DateTime::class,
            \Flames\Date\DateTimeImmutable::class => new $targetType($value, $timezone),
            \DateTime::class,
            \DateTimeImmutable::class => new $targetType($value, $timezone),
            default => new \Flames\Date\DateTimeImmutable($value, $timezone),
        };

        if ($fromDb && method_exists($instance, 'setTimezone')) {
            return $instance->setTimezone(TimeZone::getDefault());
        }

        return $instance;
    }

    public static function parseValue(object $column, mixed $value, string $defaultFormat): \DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return new \DateTimeImmutable('@' . (int) $value);
        }

        if (is_string($value)) {
            return new \DateTimeImmutable($value);
        }

        return new \DateTimeImmutable('now');
    }
}
