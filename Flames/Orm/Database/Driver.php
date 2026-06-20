<?php
declare(strict_types=1);


namespace Flames\Orm\Database;

use Flames\Orm\Database\Driver\MariaDb;
use Flames\Orm\Database\Driver\Meilisearch;
use Flames\Orm\Database\Driver\MySql;
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

        return self::$drivers[$database] = match ($config->type) {
            'mariadb'     => new MariaDb($rawConnection),
            'mysql'       => new MySql($rawConnection),
            'meilisearch' => new Meilisearch($rawConnection),
            default       => throw new Exception('Database driver ' . $config->type . ' not implemented yet.'),
        };
    }
}
