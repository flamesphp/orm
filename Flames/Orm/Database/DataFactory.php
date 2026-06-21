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

        if (($data->type === null || $data->type === '') && $database === 'elasticsearch') {
            $data->type = 'elasticsearch';
        }

        if (($data->type === null || $data->type === '') && $database === 'opensearch') {
            $data->type = 'opensearch';
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
        elseif ($data->type === 'sqlite') {
            $data->path = self::_resolveSqlitePath((string) Env::get('DATABASE_' . $databaseUpper . '_PATH'));
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
        elseif ($data->type === 'elasticsearch' || $data->type === 'opensearch') {
            $data->host = Env::get('DATABASE_' . $databaseUpper . '_HOST');
            $data->port = ConnectionPort::resolve(
                (string) $data->host,
                $data->type,
                Env::get('DATABASE_' . $databaseUpper . '_PORT'),
            );
            $data->user = Env::get('DATABASE_' . $databaseUpper . '_USER');
            $data->password = Env::get('DATABASE_' . $databaseUpper . '_PASSWORD');
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

        $supported = ['mysql', 'mariadb', 'postgresql', 'meilisearch', 'elasticsearch', 'opensearch', 'mongodb', 'sqlite'];
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

        if ($driver === 'sqlite') {
            if (($data->path ?? '') === '') {
                throw new \RuntimeException(
                    'Database connection "' . $database . '" (sqlite) is incomplete. '
                    . 'Configure DATABASE_' . $envPrefix . '_PATH in .env.',
                );
            }

            return;
        }

        if ($driver === 'meilisearch') {
            if ($data->host === null || $data->host === '' || $data->masterKey === null || $data->masterKey === '') {
                throw new \RuntimeException(
                    'Database connection "' . $database . '" (meilisearch) is incomplete. '
                    . 'Configure DATABASE_' . $envPrefix . '_HOST and DATABASE_' . $envPrefix . '_KEY in .env.',
                );
            }

            return;
        }

        if (in_array($driver, ['elasticsearch', 'opensearch'], true)) {
            if ($data->host === null || $data->host === '') {
                throw new \RuntimeException(
                    'Database connection "' . $database . '" (' . $driver . ') is incomplete. '
                    . 'Configure DATABASE_' . $envPrefix . '_HOST in .env.',
                );
            }

            return;
        }
    }

    private static function _resolveEnvPrefix(string $database): string
    {
        $databaseUpper = strtoupper($database);

        if ($database === 'meilisearch' && Env::get('DATABASE_MEILISEARCH_DRIVER') === null) {
            return 'SEARCH_MEILISEARCH';
        }

        if ($database === 'elasticsearch' && Env::get('DATABASE_ELASTICSEARCH_DRIVER') === null) {
            return 'SEARCH_ELASTICSEARCH';
        }

        if ($database === 'opensearch' && Env::get('DATABASE_OPENSEARCH_DRIVER') === null) {
            return 'SEARCH_OPENSEARCH';
        }

        return $databaseUpper;
    }

    private static function _resolveSqlitePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if ($path[0] !== '/' && preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) !== 1) {
            $path = ROOT_PATH . ltrim($path, '/');
        }

        $directory = dirname($path);
        if (is_dir($directory) === false && mkdir($directory, 0775, true) === false) {
            throw new \RuntimeException('Unable to create SQLite directory: ' . $directory);
        }

        return $path;
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