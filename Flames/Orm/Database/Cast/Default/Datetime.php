<?php

namespace Flames\Orm\Database\Cast\Default;

use Flames\Date\TimeZone;

class Datetime
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if ($value === null) {
            $value = new \Flames\DateTimeImmutable();
        }

        return $value->setTimezone(TimeZone::getUtc())->format('Y-m-d H:i:s.u');
    }

    public static function pos($column, $value, $fromDb = false): mixed
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if ($value instanceof \Flames\DateTimeImmutable || $value instanceof \Flames\DateTime) {
            return $value;
        }
        if ($value instanceof \DateTimeImmutable || $value instanceof \DateTime) {
            return new \Flames\DateTimeImmutable($value->format('Y-m-d H:i:s.u'), $value->getTimezone());
        }
        if (is_string($value)) {
            if ($fromDb) {
                $value = (new \Flames\DateTimeImmutable($value, TimeZone::getUtc()))->setTimezone(TimeZone::getDefault());
            }
            return new \Flames\DateTimeImmutable($value);
        }
        if (is_int($value)) {
            if ($fromDb) {
                $value = (new \Flames\DateTimeImmutable($value, TimeZone::getUtc()))->setTimezone(TimeZone::getDefault());
            }
            return (new \Flames\DateTimeImmutable($value))->setTimestamp($value);
        }

        return new \Flames\DateTimeImmutable();
    }
}