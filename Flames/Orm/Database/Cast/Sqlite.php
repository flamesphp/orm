<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast;

use Flames\Orm\Database\Type\Kinds;

/**
 * @internal
 */
class Sqlite
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
        $cacheKey = Kinds::resolveCastType($column, 'sqlite') . ':' . ($column->size ?? '');

        if (isset(self::$classCache[$cacheKey])) {
            return self::$classCache[$cacheKey];
        }

        $defaultClass = Kinds::castClassForColumn($column, 'sqlite');
        $shortName    = substr($defaultClass, strrpos($defaultClass, '\\') + 1);
        $class        = 'Flames\\Orm\\Database\\Cast\\Sqlite\\' . $shortName;

        if (class_exists($class)) {
            return self::$classCache[$cacheKey] = $class;
        }

        $mysqlClass = 'Flames\\Orm\\Database\\Cast\\Mysql\\' . $shortName;
        if (class_exists($mysqlClass)) {
            return self::$classCache[$cacheKey] = $mysqlClass;
        }

        return self::$classCache[$cacheKey] = $defaultClass;
    }
}
