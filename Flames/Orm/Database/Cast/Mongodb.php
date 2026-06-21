<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast;

use Flames\Orm\Database\Type\Kinds;

/**
 * Document-store casts (same semantics as Meilisearch).
 *
 * @internal
 */
class Mongodb
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
        $type     = Kinds::resolveCastType($column, 'mongodb');
        $cacheKey = $type . ':' . ($column->size ?? '');

        if (isset(self::$classCache[$cacheKey])) {
            return self::$classCache[$cacheKey];
        }

        $defaultClass = Kinds::castClassForColumn($column, 'mongodb');
        $shortName    = substr($defaultClass, strrpos($defaultClass, '\\') + 1);

        foreach (['Mongodb', 'Meilisearch'] as $namespace) {
            $class = 'Flames\\Orm\\Database\\Cast\\' . $namespace . '\\' . $shortName;
            if (class_exists($class)) {
                return self::$classCache[$cacheKey] = $class;
            }
        }

        return self::$classCache[$cacheKey] = $defaultClass;
    }
}
