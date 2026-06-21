<?php
declare(strict_types=1);


namespace Flames\Orm\Database;

/**
 * Resolves PDO/API connection ports (internal docker vs host-exposed).
 *
 * @internal
 */
final class ConnectionPort
{
    /** @var array<string, array<string, int>> */
    private const DOCKER_SERVICE_PORTS = [
        'mysql'       => ['mysql' => 3306, 'mariadb' => 3306],
        'mariadb'     => ['mysql' => 3306, 'mariadb' => 3306],
        'postgresql'  => ['postgresql' => 5432, 'postgres' => 5432],
        'mongodb'     => ['mongodb' => 27017],
        'meilisearch'   => ['meilisearch' => 7700],
        'elasticsearch' => ['elasticsearch' => 9200],
        'opensearch'    => ['opensearch' => 9200],
    ];

    public static function resolve(string $host, string $driverType, mixed $configuredPort): int
    {
        $host         = strtolower(trim($host));
        $driverType   = strtolower(trim($driverType));
        $configured   = (int) ($configuredPort ?? 0);
        $servicePorts = self::DOCKER_SERVICE_PORTS[$driverType] ?? null;

        if ($servicePorts !== null && isset($servicePorts[$host])) {
            return $servicePorts[$host];
        }

        if ($configured > 0) {
            return $configured;
        }

        return match ($driverType) {
            'mysql', 'mariadb'           => 3306,
            'postgresql', 'postgres'     => 5432,
            'mongodb'                    => 27017,
            'meilisearch'                => 7700,
            'elasticsearch'              => 9200,
            'opensearch'                 => 9200,
            default                      => 0,
        };
    }
}
