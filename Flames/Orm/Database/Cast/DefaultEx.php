<?php

namespace Flames\Orm\Database\Cast;

/**
 * @internal
 */
class DefaultEx
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

        $normalized = match ($type) {
            'bool'  => 'BoolEx',
            'int'   => 'IntEx',
            'float' => 'FloatEx',
            default => ucfirst($type),
        };

        $class = 'Flames\\Orm\\Database\\Cast\\Default\\' . $normalized;

        if (!class_exists($class)) {
            $class = 'Flames\\Orm\\Database\\Cast\\Default\\StringEx';
        }

        return self::$classCache[$type] = $class;
    }
}
