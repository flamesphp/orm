<?php
declare(strict_types=1);

namespace Flames\Orm\Database\RawConnection;

/**
 * @internal
 */
class Mongodb
{
    private readonly \MongoDB\Driver\Manager $manager;

    public function __construct(
        string $uri,
        private readonly string $database,
        private readonly mixed $config = null,
    ) {
        if (extension_loaded('mongodb') === false) {
            throw new \RuntimeException('PHP extension "mongodb" is required for the MongoDB driver.');
        }

        $this->manager = new \MongoDB\Driver\Manager($uri);
    }

    public function getManager(): \MongoDB\Driver\Manager
    {
        return $this->manager;
    }

    public function getDatabase(): string
    {
        return $this->database;
    }

    public function getConfig(): mixed
    {
        return $this->config;
    }

    public function getNamespace(string $collection): string
    {
        return $this->database . '.' . $collection;
    }
}
