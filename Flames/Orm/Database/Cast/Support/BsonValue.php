<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Support;

/**
 * BSON value normalization for the MongoDB driver.
 *
 * @internal
 */
final class BsonValue
{
    public static function isObjectId(mixed $value): bool
    {
        return class_exists(\MongoDB\BSON\ObjectId::class)
            && $value instanceof \MongoDB\BSON\ObjectId;
    }

    public static function toObjectId(mixed $value): ?\MongoDB\BSON\ObjectId
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (self::isObjectId($value)) {
            return $value;
        }

        if (is_string($value)) {
            return new \MongoDB\BSON\ObjectId($value);
        }

        return null;
    }

    public static function fromObjectId(mixed $value): ?string
    {
        if (self::isObjectId($value)) {
            return (string) $value;
        }

        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return (string) $value;
    }

    public static function isUtcDateTime(mixed $value): bool
    {
        return class_exists(\MongoDB\BSON\UTCDateTime::class)
            && $value instanceof \MongoDB\BSON\UTCDateTime;
    }

    public static function toUtcDateTime(mixed $value): ?\MongoDB\BSON\UTCDateTime
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (self::isUtcDateTime($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return new \MongoDB\BSON\UTCDateTime($value);
        }

        if (is_int($value)) {
            return new \MongoDB\BSON\UTCDateTime($value * 1000);
        }

        if (is_float($value)) {
            return new \MongoDB\BSON\UTCDateTime((int) round($value * 1000));
        }

        if (is_string($value)) {
            $dateTime = new \DateTimeImmutable($value);

            return new \MongoDB\BSON\UTCDateTime($dateTime);
        }

        return null;
    }

    public static function fromUtcDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (self::isUtcDateTime($value)) {
            return \DateTimeImmutable::createFromInterface($value->toDateTime());
        }

        return null;
    }
}
