<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Orm\Database\Cast\Default\Support\Temporal;

class Timestamp extends Datetime
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $dateTime = Temporal::parseValue($column, $value, 'Y-m-d H:i:s');

        return Temporal::formatUtc($dateTime, 'Y-m-d H:i:s');
    }
}
