<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Driver;

use Flames\Collection\Arr;
use Flames\Orm\Database\Type\Kinds;
use PDO;

/**
 * @internal
 */
class Sqlite extends MySql
{
    protected static function ddlDriverName(): string
    {
        return 'sqlite';
    }

    public function getQueryBuilder($model): \Flames\Orm\Database\QueryBuilder\Sqlite
    {
        return new \Flames\Orm\Database\QueryBuilder\Sqlite($this->connection);
    }

    protected function _updateTables(): void
    {
        $this->allTables = array_column(
            $this->connection
                ->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%';")
                ->fetchAll(PDO::FETCH_NUM),
            0,
        );
    }

    protected function __mountTable($data, string $hash): void
    {
        if ($this->allTables === []) {
            $this->_updateTables();
        }

        if (in_array($data->table, $this->allTables, true)) {
            $this->__updateTable($data, $hash);

            return;
        }

        $definitions   = [];
        $primaryKeys   = [];
        $inlinePrimary = null;

        foreach ((array) $data->column as $column) {
            if (
                $column->primary === true
                && $column->autoIncrement === true
                && Kinds::isNumericAutoIncrementType($column)
            ) {
                $inlinePrimary = $column->name;
                $definitions[] = "\t`{$column->name}` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL";
                continue;
            }

            $definitions[] = "\t`{$column->name}` " . static::__createColumnBase($column);

            if ($column->primary === true) {
                $primaryKeys[] = $column->name;
            }
        }

        $primaryKeys = array_values(array_filter(
            $primaryKeys,
            static fn (string $name): bool => $name !== $inlinePrimary,
        ));

        if ($primaryKeys !== []) {
            $definitions[] = "\tPRIMARY KEY (`" . implode('`, `', $primaryKeys) . '`)';
        }

        $this->connection->exec(
            "CREATE TABLE `{$data->table}` (\n" . implode(",\n", $definitions) . "\n);",
        );

        $queries = [];
        foreach ($data->column as $column) {
            if ($column->index === true) {
                $queries[] = "CREATE INDEX IF NOT EXISTS `idx_{$data->table}_{$column->name}` ON `{$data->table}` (`{$column->name}`);";
            }
            if ($column->unique === true) {
                $queries[] = "CREATE UNIQUE INDEX IF NOT EXISTS `uniq_{$data->table}_{$column->name}` ON `{$data->table}` (`{$column->name}`);";
            }
        }

        array_push(
            $queries,
            ...self::__syncCompositeIndexes($data->table, (array) $data->column, (array) ($data->index ?? []), []),
        );

        foreach ($queries as $query) {
            $this->connection->exec($query);
        }

        $this->_syncMigrationRecord($data->class, $hash);

        $this->allTables[] = $data->table;
    }

    protected function __updateTable($data, string $hash): void
    {
        if ($this->allTables === []) {
            $this->_updateTables();
        }

        if (in_array($data->table, $this->allTables, true) === false) {
            $this->__mountTable($data, $hash);

            return;
        }

        $stmt = $this->connection->query('PRAGMA table_info(`' . $data->table . '`);');

        $dbColumns = [];
        foreach ($stmt->fetchAll() as $row) {
            $dbColumns[(string) $row['name']] = $row;
        }

        $queries = [];
        $columns = [];

        foreach ($data->column as $column) {
            $columns[] = $column;

            if (isset($dbColumns[$column->name]) === false) {
                $queries[] = "ALTER TABLE `{$data->table}` ADD COLUMN `{$column->name}` " . static::__createColumnBase($column) . ';';
            }
        }

        $dbIndexes = $this->_loadTableIndexes($data->table);

        array_push($queries, ...self::__syncColumnIndexes($data->table, $columns, $dbIndexes));
        array_push(
            $queries,
            ...self::__syncCompositeIndexes($data->table, $columns, (array) ($data->index ?? []), $dbIndexes),
        );

        foreach ($queries as $query) {
            $this->connection->exec($query);
        }

        $currentCols = array_column(
            $this->connection->query('PRAGMA table_info(`' . $data->table . '`);')->fetchAll(),
            'name',
        );
        $modelCols = array_column($columns, 'name');
        $extraCols = array_diff($currentCols, $modelCols);

        foreach ($extraCols as $col) {
            $this->connection->exec("ALTER TABLE `{$data->table}` DROP COLUMN `{$col}`;");
        }

        $this->__ensureColumnOrder($data);

        $this->_syncMigrationRecord($data->class, $hash);
    }

    protected function __ensureColumnOrder(object $data): void
    {
        try {
            $currentCols = array_column(
                $this->connection->query('PRAGMA table_info(`' . $data->table . '`);')->fetchAll(),
                'name',
            );
        } catch (\PDOException) {
            return;
        }

        $modelCols = $this->__modelColumnNames($data);

        if ($currentCols === $modelCols || $this->__sameColumnSet($currentCols, $modelCols) === false) {
            return;
        }

        $this->__reorderTableColumns($data);
    }

    protected function __reorderTableColumns(object $data): void
    {
        $table   = $data->table;
        $temp    = $table . '_flames_reorder';
        $columns = (array) $data->column;
        $defs    = [];
        $inlinePrimary = null;
        $primaryKeys   = [];

        foreach ($columns as $column) {
            if (
                $column->primary === true
                && $column->autoIncrement === true
                && Kinds::isNumericAutoIncrementType($column)
            ) {
                $inlinePrimary = $column->name;
                $defs[] = "\t`{$column->name}` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL";
                continue;
            }

            $defs[] = "\t`{$column->name}` " . static::__createColumnBase($column);

            if ($column->primary === true) {
                $primaryKeys[] = $column->name;
            }
        }

        $primaryKeys = array_values(array_filter(
            $primaryKeys,
            static fn (string $name): bool => $name !== $inlinePrimary,
        ));

        if ($primaryKeys !== []) {
            $defs[] = "\tPRIMARY KEY (`" . implode('`, `', $primaryKeys) . '`)';
        }

        $colList = implode(', ', array_map(static fn ($column) => "`{$column->name}`", $columns));

        $this->connection->exec('BEGIN;');
        $this->connection->exec('DROP TABLE IF EXISTS `' . $temp . '`;');
        $this->connection->exec('CREATE TABLE `' . $temp . "` (\n" . implode(",\n", $defs) . "\n);");
        $this->connection->exec(
            'INSERT INTO `' . $temp . '` (' . $colList . ') SELECT ' . $colList . ' FROM `' . $table . '`;',
        );
        $this->connection->exec('DROP TABLE `' . $table . '`;');
        $this->connection->exec('ALTER TABLE `' . $temp . '` RENAME TO `' . $table . '`;');

        $indexQueries = [];
        array_push(
            $indexQueries,
            ...self::__syncColumnIndexes($table, $columns, []),
            ...self::__syncCompositeIndexes($table, $columns, (array) ($data->index ?? []), []),
        );

        foreach ($indexQueries as $query) {
            $this->connection->exec($query);
        }

        $this->connection->exec('COMMIT;');
    }

    private function _syncMigrationRecord(string $class, string $hash): void
    {
        $escaped = str_replace('\\', '\\\\', $class);
        $exists  = $this->connection
            ->query("SELECT `id` FROM `flames_migration` WHERE `class` = '{$escaped}';")
            ->fetch(PDO::FETCH_ASSOC);

        if ($exists !== false) {
            $this->connection->exec(
                "UPDATE `flames_migration` SET `hash` = '{$hash}', `version` = '" . static::__VERSION__ . "' WHERE `class` = '{$escaped}';",
            );

            return;
        }

        $this->connection->exec(
            "INSERT INTO `flames_migration` (`class`, `hash`, `version`) VALUES ('{$escaped}', '{$hash}', " . static::__VERSION__ . ');',
        );
    }

    protected static function __syncColumnIndexes(string $table, array $columns, array $dbIndexes): array
    {
        $queries = [];

        $expectedByColumn = [];
        foreach ($columns as $column) {
            $expectedByColumn[$column->name] = [
                'index'  => (bool) $column->index,
                'unique' => (bool) $column->unique,
            ];
        }

        $indexGroups = self::__groupDbIndexes($dbIndexes);

        foreach ($indexGroups as $keyName => $index) {
            if (count($index['columns']) !== 1) {
                continue;
            }

            $columnName = reset($index['columns']);
            if (isset($expectedByColumn[$columnName]) === false) {
                continue;
            }

            $expected = $expectedByColumn[$columnName];
            $isUnique = $index['non_unique'] === 0;
            $shouldKeep = ($isUnique && $expected['unique']) || ($isUnique === false && $expected['index']);

            if ($shouldKeep === false) {
                $queries[] = "DROP INDEX IF EXISTS `{$keyName}`;";
            }
        }

        foreach ($columns as $column) {
            $expected = $expectedByColumn[$column->name];

            if ($expected['index']) {
                $exists = array_any(
                    $dbIndexes,
                    static fn ($idx) => $idx['Key_name'] !== 'PRIMARY'
                        && $idx['Column_name'] === $column->name
                        && (int) $idx['Non_unique'] === 1,
                );

                if ($exists === false) {
                    $queries[] = "CREATE INDEX IF NOT EXISTS `idx_{$table}_{$column->name}` ON `{$table}` (`{$column->name}`);";
                }
            }

            if ($expected['unique']) {
                $exists = array_any(
                    $dbIndexes,
                    static fn ($idx) => $idx['Key_name'] !== 'PRIMARY'
                        && $idx['Column_name'] === $column->name
                        && (int) $idx['Non_unique'] === 0,
                );

                if ($exists === false) {
                    $queries[] = "CREATE UNIQUE INDEX IF NOT EXISTS `uniq_{$table}_{$column->name}` ON `{$table}` (`{$column->name}`);";
                }
            }
        }

        return $queries;
    }

    protected static function __syncCompositeIndexes(string $table, array $columns, array $modelIndexes, array $dbIndexes): array
    {
        $queries = [];
        $modelColumnNames = array_map(static fn ($column) => $column->name, $columns);

        $expected = [];
        foreach ($modelIndexes as $index) {
            $expected[] = array_values((array) $index->columns);
        }

        $indexGroups = self::__groupDbIndexes($dbIndexes);
        $existingComposites = [];

        foreach ($indexGroups as $keyName => $index) {
            if (count($index['columns']) < 2 || $index['non_unique'] !== 1) {
                continue;
            }

            $indexColumns = array_values($index['columns']);
            if (array_any($indexColumns, static fn ($column) => in_array($column, $modelColumnNames, true) === false)) {
                continue;
            }

            $existingComposites[$keyName] = $indexColumns;
        }

        foreach ($existingComposites as $keyName => $indexColumns) {
            if (self::__indexColumnsInList($indexColumns, $expected) === false) {
                $queries[] = "DROP INDEX IF EXISTS `{$keyName}`;";
            }
        }

        foreach ($expected as $indexColumns) {
            $exists = array_any(
                $existingComposites,
                static fn ($existingColumns) => $existingColumns === $indexColumns,
            );

            if ($exists === false) {
                $queries[] = self::__addCompositeIndexQuery($table, $indexColumns);
            }
        }

        return $queries;
    }

    protected static function __addCompositeIndexQuery(string $table, array $columns): string
    {
        $columnSql = implode('`, `', $columns);
        $name      = self::__compositeIndexName($columns);

        return "CREATE INDEX IF NOT EXISTS `{$name}` ON `{$table}` (`{$columnSql}`);";
    }

    /**
     * @return list<array{Key_name: string, Column_name: string, Non_unique: int, Seq_in_index: int}>
     */
    private function _loadTableIndexes(string $table): array
    {
        $indexes = [];
        $list    = $this->connection->query('PRAGMA index_list(`' . $table . '`);')->fetchAll();

        foreach ($list as $index) {
            if (($index['origin'] ?? '') === 'pk') {
                continue;
            }

            $keyName   = (string) $index['name'];
            $nonUnique = ((int) ($index['unique'] ?? 0)) === 1 ? 0 : 1;
            $infoRows  = $this->connection->query('PRAGMA index_info(`' . $keyName . '`);')->fetchAll();

            foreach ($infoRows as $info) {
                $indexes[] = [
                    'Key_name'     => $keyName,
                    'Column_name'  => (string) $info['name'],
                    'Non_unique'   => $nonUnique,
                    'Seq_in_index' => ((int) ($info['seqno'] ?? 0)) + 1,
                ];
            }
        }

        return $indexes;
    }

    protected function __mountMigration(): void
    {
        $this->connection->exec('
            CREATE TABLE IF NOT EXISTS `flames_migration` (
                `id`      INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                `class`   TEXT NOT NULL,
                `hash`    TEXT NOT NULL,
                `version` INTEGER NOT NULL
            );
        ');
        $this->connection->exec('CREATE UNIQUE INDEX IF NOT EXISTS `uniq_flames_migration_class` ON `flames_migration` (`class`);');
    }

    public function migrateQueue(string $table): void
    {
        if ($this->allTables === []) {
            $this->_updateTables();
        }

        if (in_array($table, $this->allTables, true)) {
            return;
        }

        $this->connection->exec("
            CREATE TABLE `{$table}` (
                `id`         INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                `process_id` TEXT DEFAULT NULL,
                `data`       TEXT NOT NULL,
                `date`       TEXT DEFAULT NULL
            );
        ");

        $this->allTables[] = $table;
    }
}
