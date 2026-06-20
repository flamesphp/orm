<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Cast;

use Flames\Orm\Database\Type\Kinds;

/**
 * @internal
 */
class Meilisearch
{
    private static array $classCache = [];

    public static function pre($column, $value, $fromDb = false)
    {
        return self::_getClass($column->type)::pre($column, $value, $fromDb);
    }

    public static function pos($column, $value, $fromDb = false)
    {
        return self::_getClass($column->type)::pos($column, $value, $fromDb);
    }

    private static function _getClass(string $type): string
    {
        if (isset(self::$classCache[$type])) {
            return self::$classCache[$type];
        }

        $defaultClass = Kinds::castClass($type);
        $shortName    = substr($defaultClass, strrpos($defaultClass, '\\') + 1);
        $class        = 'Flames\\Orm\\Database\\Cast\\Meilisearch\\' . $shortName;

        if (!class_exists($class)) {
            $class = $defaultClass;
        }

        return self::$classCache[$type] = $class;
    }
}
