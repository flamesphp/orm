<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Cast;

use Flames\Orm\Database\Type\Kinds;

/**
 * @internal
 */
class DefaultEx
{
    private static array $classCache = [];

    public static function pre($column, $value, $fromDb = false)
    {
        return self::_getClass($column)::pre($column, $value, $fromDb);
    }

    public static function pos($column, $value, $fromDb = false)
    {
        return self::_getClass($column)::pos($column, $value, $fromDb);
    }

    private static function _getClass(object $column): string
    {
        $cacheKey = Kinds::resolveCastType($column) . ':' . ($column->size ?? '');

        if (isset(self::$classCache[$cacheKey])) {
            return self::$classCache[$cacheKey];
        }

        return self::$classCache[$cacheKey] = Kinds::castClassForColumn($column);
    }
}
