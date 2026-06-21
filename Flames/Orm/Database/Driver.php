<?php
declare(strict_types=1);


namespace Flames\Orm\Database;

use Flames\Orm\Database\Driver\MariaDb;
use Flames\Orm\Database\Driver\Meilisearch;
use Flames\Orm\Database\Driver\Mongodb;
use Flames\Orm\Database\Driver\MySql;
use Flames\Orm\Database\Driver\Postgresql;
use PDO;
use Exception;

/**
 * @internal
 */
class Driver
{
    protected static $drivers = [];

    public static function getByConfigAndDatabase($config, string $database = null): mixed
    {
        $database ??= sha1($config);

        if (isset(self::$drivers[$database])) {
            return self::$drivers[$database];
        }

        $rawConnection = RawConnection::getByConfigAndDatabase($config, $database);

        $driver = match ($config->type) {
            'mariadb'     => new MariaDb($rawConnection),
            'mysql'       => new MySql($rawConnection),
            'postgresql'  => new Postgresql($rawConnection),
            'meilisearch' => new Meilisearch($rawConnection),
            'mongodb'     => new Mongodb($rawConnection),
            default       => throw new Exception(
                'Database driver "' . ($config->type ?? '') . '" for connection "'
                . ($config->database ?? $database ?? 'unknown') . '" is not implemented.',
            ),
        };

        $driver->name     = (string) $config->type;
        $driver->database = (string) ($config->database ?? $database ?? '');

        return self::$drivers[$database] = $driver;
    }
}
