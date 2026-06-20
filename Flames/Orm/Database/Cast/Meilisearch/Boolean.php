<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Cast\Meilisearch;

use Flames\Orm\Database\Cast\Default\Boolean as DefaultBoolean;

class Boolean
{
    public static function pre($column, $value): bool|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        return (bool) DefaultBoolean::pos($column, $value);
    }

    public static function pos($column, $value): mixed
    {
        return DefaultBoolean::pos($column, $value);
    }
}
