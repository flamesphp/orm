<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Date\TimeZone;
use Flames\Orm\Database\Cast\Default\Support\Temporal;

class Timestamptz extends Timestamp
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $dateTime = Temporal::parseValue($column, $value, 'Y-m-d H:i:sP');

        return \DateTimeImmutable::createFromInterface($dateTime)
            ->setTimezone(TimeZone::getUtc())
            ->format('Y-m-d H:i:sP');
    }
}
