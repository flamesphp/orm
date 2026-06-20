<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Cast\Meilisearch;

use Flames\Date\TimeZone;

class Datetime extends \Flames\Orm\Database\Cast\Default\Datetime
{
    public static function pre($column, $value): string|null
    {
        $formatted = parent::pre($column, $value);

        if ($formatted === null) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface(
            \Flames\Orm\Database\Cast\Default\Support\Temporal::parseValue($column, $formatted, 'Y-m-d H:i:s.u'),
        )
            ->setTimezone(TimeZone::getUtc())
            ->format('Y-m-d\TH:i:s.u\Z');
    }
}
