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
        } elseif ($config->type === 'mongodb') {
            self::$connections[$database] = self::_connectMongodb($config, $database);
        } elseif ($config->type === 'sqlite') {
            try {
                $path = (string) ($config->path ?? '');
                $connectionUri = 'sqlite:' . $path;
                self::$connections[$database] = new RawConnection\Pdo($connectionUri, null, null, null, $config, $database);
                self::$connections[$database]->exec('PRAGMA foreign_keys = ON;');
                self::$connections[$database]->exec('PRAGMA journal_mode = WAL;');
            } catch (PDOException $e) {
                throw new \Error($e->getMessage());
            }
        } else {
            $connection = (string) ($config->database ?? $database);
            $driver     = (string) ($config->type ?? '');

            throw new \RuntimeException(
                'Database connection "' . $connection . '" uses unsupported driver "' . $driver . '".',
            );
        }

        return self::$connections[$database];
    }

    private static function _connectMongodb(mixed $config, string $database): RawConnection\Mongodb
    {
        $user = trim((string) ($config->user ?? ''));
        $pass = (string) ($config->password ?? '');
        $auth = $user !== '' ? rawurlencode($user) . ':' . rawurlencode($pass) . '@' : '';
        $uri  = 'mongodb://' . $auth . $config->host . ':' . $config->port . '/' . $config->name;

        if ($user !== '') {
            $uri .= '?authSource=admin';
        }

        return new RawConnection\Mongodb($uri, (string) $config->name, $config);
    }
}
