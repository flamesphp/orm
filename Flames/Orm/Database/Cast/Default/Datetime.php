<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Cast\Default;

use Flames\Date\TimeZone;

class Datetime
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $dateTime = self::__parseValue($column, $value);

        return \DateTimeImmutable::createFromInterface($dateTime)
            ->setTimezone(TimeZone::getUtc())
            ->format('Y-m-d H:i:s.u');
    }

    public static function pos($column, $value, $fromDb = false): mixed
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $targetType = self::__resolveTargetType($column);

        if (is_object($value) && $value instanceof $targetType) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return self::__convertDateTimeInterface($value, $targetType);
        }

        if (is_string($value)) {
            return self::__fromString($value, $targetType, $fromDb);
        }

        if (is_int($value)) {
            return self::__fromTimestamp($value, $targetType, $fromDb);
        }

        return self::__instantiate($targetType);
    }

    private static function __parseValue(object $column, mixed $value): \DateTimeInterface
    {
        if ($value === null) {
            return new \Flames\Date\DateTimeImmutable();
        }

        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        $targetType = self::__resolveTargetType($column);

        if (is_int($value)) {
            return self::__instantiate($targetType, '@' . $value);
        }

        if (is_float($value)) {
            return self::__instantiate($targetType, '@' . (int) $value);
        }

        if (is_string($value)) {
            return self::__instantiate($targetType, $value);
        }

        if (is_numeric($value)) {
            return self::__instantiate($targetType, '@' . (int) $value);
        }

        return self::__instantiate($targetType, (string) $value);
    }

    private static function __resolveTargetType(object $column): string
    {
        return match ($column->phpType ?? null) {
            \Flames\Date\DateTime::class           => \Flames\Date\DateTime::class,
            \DateTime::class                       => \DateTime::class,
            \Flames\Date\DateTimeImmutable::class  => \Flames\Date\DateTimeImmutable::class,
            \DateTimeImmutable::class              => \DateTimeImmutable::class,
            default                                => \Flames\Date\DateTimeImmutable::class,
        };
    }

    private static function __convertDateTimeInterface(\DateTimeInterface $value, string $targetType): object
    {
        return self::__instantiate(
            $targetType,
            $value->format('Y-m-d H:i:s.u'),
            $value->getTimezone()
        );
    }

    private static function __fromString(string $value, string $targetType, bool $fromDb): object
    {
        $instance = self::__instantiate(
            $targetType,
            $value,
            $fromDb ? TimeZone::getUtc() : null
        );

        if ($fromDb) {
            return self::__applyDefaultTimezone($instance);
        }

        return $instance;
    }

    private static function __fromTimestamp(int $timestamp, string $targetType, bool $fromDb): object
    {
        $instance = self::__instantiate(
            $targetType,
            '@' . $timestamp,
            $fromDb ? TimeZone::getUtc() : null
        );

        if ($fromDb) {
            return self::__applyDefaultTimezone($instance);
        }

        return $instance;
    }

    private static function __instantiate(
        string $targetType,
        \DateTimeInterface|string|int|null $dateTime = 'now',
        \DateTimeZone|null $timezone = null
    ): object {
        return match ($targetType) {
            \Flames\Date\DateTime::class           => new \Flames\Date\DateTime($dateTime, $timezone),
            \DateTime::class                         => new \DateTime($dateTime, $timezone),
            \Flames\Date\DateTimeImmutable::class  => new \Flames\Date\DateTimeImmutable($dateTime, $timezone),
            \DateTimeImmutable::class                => new \DateTimeImmutable($dateTime, $timezone),
            default                                  => new \Flames\Date\DateTimeImmutable($dateTime, $timezone),
        };
    }

    private static function __applyDefaultTimezone(object $dateTime): object
    {
        return $dateTime->setTimezone(TimeZone::getDefault());
    }
}
