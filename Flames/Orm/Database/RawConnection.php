<?php
declare(strict_types=1);


namespace Flames\Orm\Database;

use Flames\Orm\Database\Driver\MariaDB;
use Flames\Orm\Database\Driver\MySQL;
use Http\Client;
use PDOException;

/**
 * @internal
 */
class RawConnection
{
    protected static $connections = [];

    public static function getByConfigAndDatabase($config, string $database = null): mixed
    {
        $database ??= sha1($config);

        if (isset(self::$connections[$database])) {
            return self::$connections[$database];
        }

        if ($config->type === 'mariadb' || $config->type === 'mysql') {
            try {
                $connectionUri = 'mysql:host=' . $config->host . ';dbname=' . $config->name . ';port=' . $config->port . ';charset=utf8mb4';
                self::$connections[$database] = new RawConnection\Pdo($connectionUri, $config->user, $config->password, null, $config, $database);
            } catch (PDOException $e) {
                throw new \Error($e->getMessage());
            }
        } elseif ($config->type === 'postgresql') {
            try {
                $connectionUri = 'pgsql:host=' . $config->host . ';dbname=' . $config->name . ';port=' . $config->port;
                self::$connections[$database] = new RawConnection\Pdo($connectionUri, $config->user, $config->password, null, $config, $database);
            } catch (PDOException $e) {
                throw new \Error($e->getMessage());
            }
        } elseif ($config->type === 'meilisearch') {
            self::$connections[$database] = new RawConnection\Meilisearch(
                'http://' . $config->host . ':' . $config->port . '/',
                $config->masterKey,
                $config
            );
        } else {
            $connection = (string) ($config->database ?? $database);
            $driver     = (string) ($config->type ?? '');

            throw new \RuntimeException(
                'Database connection "' . $connection . '" uses unsupported driver "' . $driver . '".',
            );
        }

        return self::$connections[$database];
    }
}
