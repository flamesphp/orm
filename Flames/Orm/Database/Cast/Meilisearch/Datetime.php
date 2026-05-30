<?php

namespace Flames\Orm\Database\Cast\Meilisearch;

use Flames\Date\TimeZone;

class Datetime
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if ($value === null) {
            $value = new \Flames\Date\DateTimeImmutable();
        }

        return $value->setTimezone(TimeZone::getUtc())->format('Y-m-d H:i:s.u');
    }

    public static function pos($column, $value, $fromDb = false): mixed
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if ($value instanceof \Flames\Date\DateTimeImmutable || $value instanceof \Flames\Date\DateTime) {
            return $value;
        }
        if ($value instanceof \DateTimeImmutable || $value instanceof \DateTime) {
            return new \Flames\Date\DateTimeImmutable($value->format('Y-m-d H:i:s.u'), $value->getTimezone());
        }
        if (is_string($value)) {
            if ($fromDb) {
                $value = (new \Flames\Date\DateTimeImmutable($value, TimeZone::getUtc()))->setTimezone(TimeZone::getDefault());
            }
            return new \Flames\Date\DateTimeImmutable($value);
        }
        if (is_int($value)) {
            if ($fromDb) {
                $value = (new \Flames\Date\DateTimeImmutable($value, TimeZone::getUtc()))->setTimezone(TimeZone::getDefault());
            }
            return (new \Flames\Date\DateTimeImmutable($value))->setTimestamp($value);
        }

        return new \Flames\Date\DateTimeImmutable();
    }
}
