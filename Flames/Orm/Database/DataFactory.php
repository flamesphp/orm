<?php
declare(strict_types=1);


namespace Flames\Orm\Database;

use Flames\Collection\Arr;
use Flames\Collection\Strings;
use Flames\Env\Env;

/**
 * @internal
 */
class DataFactory
{
    protected static $databases = [];

    public static function getConfigByDatabase($database = null)
    {
        if ($database === null) {
            $database = Env::get('DATABASE_DEFAULT');
        }

        if (isset(self::$databases[$database]) === true) {
            return self::$databases[$database];
        }


        $envPrefix     = self::_resolveEnvPrefix($database);
        $databaseUpper = strtoupper($envPrefix);

        $data = Arr();
        $data->database = $database;
        $data->envPrefix = $envPrefix;
        $data->type = Strings::toLower(Env::get('DATABASE_' . $databaseUpper . '_DRIVER'));

        if (($data->type === null || $data->type === '') && $database === 'meilisearch') {
            $data->type = 'meilisearch';
        }

        if ($data->type === 'mariadb' || $data->type === 'mysql' || $data->type === 'postgresql' || $data->type === 'mongodb') {
            $data->name = Env::get('DATABASE_' . $databaseUpper . '_NAME');
            $data->host = Env::get('DATABASE_' . $databaseUpper . '_HOST');
            $data->port = ConnectionPort::resolve(
                (string) $data->host,
                $data->type,
                Env::get('DATABASE_' . $databaseUpper . '_PORT'),
            );
            $data->user = Env::get('DATABASE_' . $databaseUpper . '_USER');
            $data->password = Env::get('DATABASE_' . $databaseUpper . '_PASSWORD');
        }
        elseif ($data->type === 'meilisearch') {
            $data->host = Env::get('DATABASE_' . $databaseUpper . '_HOST');
            $data->port = ConnectionPort::resolve(
                (string) $data->host,
                $data->type,
                Env::get('DATABASE_' . $databaseUpper . '_PORT'),
            );
            $data->masterKey = Env::get('DATABASE_' . $databaseUpper . '_MASTER_KEY')
                ?? Env::get('DATABASE_' . $databaseUpper . '_KEY');
        }

        self::_validateConfig($data);

        self::$databases[$database] = $data;
        return self::$databases[$database];
    }

    private static function _validateConfig(Arr $data): void
    {
        $database  = (string) $data->database;
        $envPrefix = strtoupper((string) $data->envPrefix);
        $driver    = (string) ($data->type ?? '');

        if ($driver === '') {
            throw new \RuntimeException(
                'Database connection "' . $database . '" is not configured. '
                . 'Define DATABASE_' . $envPrefix . '_DRIVER in .env or fix the #[Database("' . $database . '")] attribute.',
            );
        }

        $supported = ['mysql', 'mariadb', 'postgresql', 'meilisearch', 'mongodb'];
        if (in_array($driver, $supported, true) === false) {
            throw new \RuntimeException(
                'Database driver "' . $driver . '" for connection "' . $database . '" is not supported. '
                . 'Supported drivers: ' . implode(', ', $supported) . '.',
            );
        }

        if ($driver === 'mysql' || $driver === 'mariadb' || $driver === 'postgresql' || $driver === 'mongodb') {
            if ($data->host === null || $data->host === '' || $data->name === null || $data->name === '') {
                throw new \RuntimeException(
                    'Database connection "' . $database . '" (' . $driver . ') is incomplete. '
                    . 'Configure DATABASE_' . $envPrefix . '_HOST and DATABASE_' . $envPrefix . '_NAME in .env.',
                );
            }

            return;
        }

        if ($data->host === null || $data->host === '' || $data->masterKey === null || $data->masterKey === '') {
            throw new \RuntimeException(
                'Database connection "' . $database . '" (meilisearch) is incomplete. '
                . 'Configure DATABASE_' . $envPrefix . '_HOST and DATABASE_' . $envPrefix . '_KEY in .env.',
            );
        }
    }

    private static function _resolveEnvPrefix(string $database): string
    {
        $databaseUpper = strtoupper($database);

        if ($database === 'meilisearch' && Env::get('DATABASE_MEILISEARCH_DRIVER') === null) {
            return 'SEARCH_MEILISEARCH';
        }

        return $databaseUpper;
    }

    /**
     * Removes the cached config for the given database key so that the next
     * call to getConfigByDatabase() re-reads the environment variables.
     * Useful after changing DATABASE_*_NAME at runtime via Env::set().
     */
    public static function invalidate(string $database): void
    {
        unset(self::$databases[$database]);
    }
}