<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Cast;

/**
 * @internal
 */
class Factory
{
    private static array $cache = [];

    public static function getByDatabaseType(string $type): string
    {
        if (isset(self::$cache[$type])) {
            return self::$cache[$type];
        }

        $class = 'Flames\\Orm\\Database\\Cast\\' . ucfirst($type);

        if (!class_exists($class)) {
            $class = 'Flames\\Orm\\Database\\Cast\\DefaultEx';
        }

        return self::$cache[$type] = $class;
    }
}
