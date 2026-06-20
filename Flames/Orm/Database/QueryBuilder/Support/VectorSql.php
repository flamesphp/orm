<?php
declare(strict_types=1);

namespace Flames\Orm\Database\QueryBuilder\Support;

use PDO;
use RuntimeException;

/**
 * @internal
 */
final class VectorSql
{
    /** @var array<int, array{from: string, to: string}> */
    private static array $cache = [];

    /**
     * @return array{from: string, to: string}
     */
    public static function resolve(PDO $connection): array
    {
        $key = spl_object_id($connection);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $version = (string) $connection->query('SELECT VERSION()')->fetchColumn();

        if (stripos($version, 'maria') !== false) {
            return self::$cache[$key] = self::_resolveMariaDb($connection, $version);
        }

        return self::$cache[$key] = self::_resolveMySql($connection, $version);
    }

    /**
     * @return array{from: string, to: string}
     */
    private static function _resolveMySql(PDO $connection, string $version): array
    {
        if (self::_mysqlMajorVersion($version) >= 9) {
            return ['from' => 'STRING_TO_VECTOR', 'to' => 'VECTOR_TO_STRING'];
        }

        $fromFns = ['STRING_TO_VECTOR', 'TO_VECTOR'];
        $toFns   = ['VECTOR_TO_STRING', 'FROM_VECTOR'];

        foreach ($fromFns as $fromFn) {
            foreach ($toFns as $toFn) {
                if (self::_works($connection, $fromFn, $toFn)) {
                    return ['from' => $fromFn, 'to' => $toFn];
                }
            }
        }

        throw new RuntimeException(
            'Vector SQL functions require MySQL 9.0.0 or newer. Server: ' . $version,
        );
    }

    private static function _mysqlMajorVersion(string $version): int
    {
        if (preg_match('/(\d+)/', $version, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }

    /**
     * @return array{from: string, to: string}
     */
    private static function _resolveMariaDb(PDO $connection, string $version): array
    {
        foreach (['VEC_FromText', 'VEC_FROMTEXT'] as $fromFn) {
            foreach (['VEC_ToText', 'VEC_TOTEXT'] as $toFn) {
                if (self::_works($connection, $fromFn, $toFn)) {
                    return ['from' => $fromFn, 'to' => $toFn];
                }
            }
        }

        throw new RuntimeException(
            'Vector SQL functions are unavailable on MariaDB server: ' . $version,
        );
    }

    private static function _works(PDO $connection, string $fromFn, string $toFn): bool
    {
        try {
            $connection->exec('CREATE TEMPORARY TABLE __flames_vec_fn_probe (v VECTOR(1))');
            $connection->exec("INSERT INTO __flames_vec_fn_probe VALUES ({$fromFn}('[1]'))");
            $row = $connection
                ->query("SELECT {$toFn}(v) AS vec_out FROM __flames_vec_fn_probe LIMIT 1")
                ->fetch();

            return is_array($row) && array_key_exists('vec_out', $row) && $row['vec_out'] !== null;
        } catch (\Throwable) {
            return false;
        } finally {
            try {
                $connection->exec('DROP TEMPORARY TABLE IF EXISTS __flames_vec_fn_probe');
            } catch (\Throwable) {
            }
        }
    }
}
