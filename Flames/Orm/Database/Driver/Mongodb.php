<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Driver;

use MongoDB\Driver\Command;

/**
 * @internal
 */
class Mongodb extends DefaultEx
{
    protected const __VERSION__ = 1;

    protected \Flames\Orm\Database\RawConnection\Mongodb $connection;

    /** @var array<string, true> */
    protected array $collectionsSynced = [];

    /** @var array<string, string> */
    protected array $migrationHashes = [];

    public function __construct(\Flames\Orm\Database\RawConnection\Mongodb $connection)
    {
        $this->connection = $connection;
    }

    public function getConnection(): \Flames\Orm\Database\RawConnection\Mongodb
    {
        return $this->connection;
    }

    public function getQueryBuilder($model): \Flames\Orm\Database\QueryBuilder\Mongodb
    {
        return new \Flames\Orm\Database\QueryBuilder\Mongodb($this->connection);
    }

    public function migrate($data): bool
    {
        if (isset($this->collectionsSynced[$data->class])) {
            return true;
        }

        $hash = sha1((string) filemtime(ROOT_PATH . str_replace('\\', '/', $data->class) . '.php'));

        if ($this->migrationHashes === []) {
            $this->_loadMigrations();
        }

        if (isset($this->migrationHashes[$data->class]) === false || $this->migrationHashes[$data->class] !== $hash) {
            $this->_syncCollection($data, $hash);
        }

        $this->collectionsSynced[$data->class] = true;

        return true;
    }

    private function _loadMigrations(): void
    {
        $manager  = $this->connection->getManager();
        $database = $this->connection->getDatabase();
        $query    = new \MongoDB\Driver\Query([]);

        try {
            $cursor = $manager->executeQuery($database . '.flames_migration', $query);
        } catch (\Throwable) {
            $this->_ensureMigrationCollection();

            return;
        }

        foreach ($cursor as $document) {
            $row = (array) $document;
            if ((int) ($row['version'] ?? 0) !== self::__VERSION__) {
                continue;
            }

            $this->migrationHashes[(string) ($row['class'] ?? '')] = (string) ($row['hash'] ?? '');
        }
    }

    private function _ensureMigrationCollection(): void
    {
        $manager  = $this->connection->getManager();
        $database = $this->connection->getDatabase();

        $manager->executeCommand($database, new Command([
            'createIndexes' => 'flames_migration',
            'indexes'       => [[
                'key'    => ['class' => 1],
                'name'   => 'uniq_class',
                'unique' => true,
            ]],
        ]));
    }

    private function _syncCollection(object $data, string $hash): void
    {
        $manager     = $this->connection->getManager();
        $database    = $this->connection->getDatabase();
        $collection  = $data->table;
        $indexes     = $this->_buildIndexes($data);

        if ($indexes !== []) {
            $manager->executeCommand($database, new Command([
                'createIndexes' => $collection,
                'indexes'       => $indexes,
            ]));
        }

        $this->_ensureMigrationCollection();

        $bulk = new \MongoDB\Driver\BulkWrite(['ordered' => true]);
        $bulk->update(
            ['class' => $data->class],
            ['$set' => [
                'class'   => $data->class,
                'hash'    => $hash,
                'version' => self::__VERSION__,
            ]],
            ['upsert' => true],
        );
        $manager->executeBulkWrite($database . '.flames_migration', $bulk);
        $this->migrationHashes[$data->class] = $hash;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function _buildIndexes(object $data): array
    {
        $indexes = [];

        foreach ($data->column as $column) {
            if ($column->primary === true) {
                if ($column->name !== '_id') {
                    $indexes[] = [
                        'key'    => [$column->name => 1],
                        'name'   => 'uniq_' . $column->name,
                        'unique' => true,
                    ];
                }
                continue;
            }

            if ($column->unique === true) {
                $indexes[] = [
                    'key'    => [$column->name => 1],
                    'name'   => 'uniq_' . $column->name,
                    'unique' => true,
                ];
                continue;
            }

            if ($column->index === true) {
                $indexes[] = [
                    'key'  => [$column->name => 1],
                    'name' => 'idx_' . $column->name,
                ];
            }
        }

        foreach ((array) ($data->index ?? []) as $index) {
            $keys = [];
            foreach ((array) $index->columns as $columnName) {
                $keys[(string) $columnName] = 1;
            }

            if ($keys === []) {
                continue;
            }

            $indexes[] = [
                'key'  => $keys,
                'name' => 'idx_' . implode('_', array_keys($keys)),
            ];
        }

        return $indexes;
    }
}
