<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Driver;

use Flames\Collection\Arr;
use PDO;

/**
 * @internal
 */
class Mysql extends DefaultEx
{
    protected const __VERSION__ = 4;

    protected $connection         = null;
    protected array $tableUpdated      = [];
    protected array $tablesMigrations  = [];
    protected array $allTables         = [];

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    public function getQueryBuilder($model): \Flames\Orm\Database\QueryBuilder\MySql
    {
        return new \Flames\Orm\Database\QueryBuilder\MySql($this->connection);
    }

    public function migrate($data): bool
    {
        if (isset($this->tableUpdated[$data->class])) {
            return true;
        }

        // Include metadata version so trait / column mapping changes trigger ALTER.
        $hash = $this->__migrationHash($data);

        if (empty($this->tablesMigrations)) {
            try {
                $rows = $this->connection
                    ->query('SELECT `class`, `hash`, `version` FROM `flames_migration`;')
                    ->fetchAll();
            } catch (\PDOException $e) {
                $this->__mountMigration();
                $rows = $this->connection
                    ->query('SELECT `class`, `hash`, `version` FROM `flames_migration`;')
                    ->fetchAll();
            }

            if (empty($rows)) {
                $this->__mountTable($data, $hash);
                $this->tableUpdated[$data->class] = true;
                return true;
            }

            // PHP 8.4: build migration map in one pass; skip outdated version rows
            foreach ($rows as $row) {
                if ((int)$row['version'] === static::__VERSION__) {
                    $this->tablesMigrations[$row['class']] = $row['hash'];
                }
            }

            // PHP 8.4 array_find: check if this model is already registered
            if (!isset($this->tablesMigrations[$data->class])) {
                $this->__mountTable($data, $hash);
                $this->tableUpdated[$data->class] = true;
                return true;
            }
        }

        if (isset($this->tablesMigrations[$data->class]) && $this->tablesMigrations[$data->class] === $hash) {
            $this->__ensureColumnOrder($data);
            $this->tableUpdated[$data->class] = true;
            return true;
        }

        $this->__updateTable($data, $hash);
        $this->tablesMigrations[$data->class] = $hash;
        $this->tableUpdated[$data->class]     = true;
        return true;
    }

    protected function _updateTables(): void
    {
        $this->allTables = array_column(
            $this->connection->query('SHOW TABLES;')->fetchAll(PDO::FETCH_NUM),
            0
        );
    }

    protected function __mountTable($data, string $hash): void
    {
        if (empty($this->allTables)) {
            $this->_updateTables();
        }

        // PHP 8.4 array_any: check table existence
        if (array_any($this->allTables, fn($t) => $t === $data->table)) {
            $this->__updateTable($data, $hash);
            return;
        }

        $cols = implode(",\n", array_map(function ($column) {
            $column->base = static::__createColumnBase($column);
            return "\t`{$column->name}` {$column->base}";
        }, (array)$data->column));

        $this->connection->query(
            "CREATE TABLE `{$data->table}` (\n{$cols}\n) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;"
        );

        $queries = [];
        foreach ($data->column as $column) {
            if ($column->primary)       { $queries[] = "ALTER TABLE `{$data->table}` ADD PRIMARY KEY (`{$column->name}`);"; }
            if ($column->index)         { $queries[] = "ALTER TABLE `{$data->table}` ADD INDEX (`{$column->name}`);"; }
            if ($column->unique)        { $queries[] = "ALTER TABLE `{$data->table}` ADD UNIQUE (`{$column->name}`);"; }
            if ($column->autoIncrement && \Flames\Orm\Database\Type\Kinds::isNumericAutoIncrementType($column)) {
                $queries[] = "ALTER TABLE `{$data->table}` MODIFY `{$column->name}` {$column->base} AUTO_INCREMENT;";
            }
        }

        array_push(
            $queries,
            ...self::__syncCompositeIndexes($data->table, (array) $data->column, (array) ($data->index ?? []), [])
        );

        array_walk($queries, fn($q) => $this->connection->query($q));

        $escaped = str_replace('\\', '\\\\', $data->class);
        $this->connection->query(
            "INSERT INTO `flames_migration` (`id`, `class`, `hash`, `version`) VALUES (NULL, '{$escaped}', '{$hash}', " . static::__VERSION__ . ");"
        );

        $this->allTables[] = $data->table;
    }

    protected function __updateTable($data, string $hash): void
    {
        try {
            $stmt = $this->connection->query('SHOW COLUMNS FROM `' . $data->table . '`;');
        } catch (\PDOException $e) {
            $this->__mountTable($data, $hash);
            return;
        }

        $dbColumns = [];
        foreach ($stmt->fetchAll() as $row) {
            $dbColumns[$row['Field']] = $row;
        }

        $queries   = [];
        $columns   = [];
        $missingDb = [];

        foreach ($data->column as $column) {
            $columns[] = $column;
            $base      = static::__createColumnBase($column);

            if (!isset($dbColumns[$column->name])) {
                $missingDb[] = $column;
                continue;
            }

            $alter = "ALTER TABLE `{$data->table}` MODIFY `{$column->name}` {$base}";
            $queries[] = $alter . (
                $column->autoIncrement && \Flames\Orm\Database\Type\Kinds::isNumericAutoIncrementType($column)
                    ? ' AUTO_INCREMENT'
                    : ''
            ) . ';';
        }

        foreach ($missingDb as $column) {
            $base = static::__createColumnBase($column);

            // PHP 8.4 array_find: find the column just before this one
            $prev   = null;
            $found  = false;
            foreach ($columns as $c) {
                if ($c->name === $column->name) { $found = true; break; }
                $prev = $c;
            }
            $after     = ($prev !== null) ? " AFTER `{$prev->name}`" : '';
            $queries[] = "ALTER TABLE `{$data->table}` ADD `{$column->name}` {$base}{$after};";
            if ($column->autoIncrement && \Flames\Orm\Database\Type\Kinds::isNumericAutoIncrementType($column)) {
                $queries[] = "ALTER TABLE `{$data->table}` MODIFY `{$column->name}` {$base} AUTO_INCREMENT;";
            }
        }

        $dbIndexes = $this->connection->query('SHOW INDEX FROM `' . $data->table . '`;')->fetchAll();

        foreach ($columns as $column) {
            $dbCol = $dbColumns[$column->name] ?? null;

            if ($column->primary && ($dbCol === null || $dbCol['Key'] !== 'PRI')) {
                $queries[] = "ALTER TABLE `{$data->table}` ADD PRIMARY KEY (`{$column->name}`);";
            }
        }

        array_push($queries, ...self::__syncColumnIndexes($data->table, $columns, $dbIndexes));
        array_push(
            $queries,
            ...self::__syncCompositeIndexes($data->table, $columns, (array) ($data->index ?? []), $dbIndexes)
        );

        array_walk($queries, fn($q) => $this->connection->query($q));

        $currentCols = array_column(
            $this->connection->query('SHOW COLUMNS FROM `' . $data->table . '`;')->fetchAll(),
            'Field'
        );
        $modelCols = array_column($columns, 'name');
        $extraCols = array_diff($currentCols, $modelCols);

        array_walk($extraCols, fn($col) => $this->connection->query("ALTER TABLE `{$data->table}` DROP COLUMN `{$col}`;"));

        $this->__ensureColumnOrder($data);

        $escaped = str_replace('\\', '\\\\', $data->class);
        $exists  = !empty(
            $this->connection->query("SELECT `id` FROM `flames_migration` WHERE `class` = '{$escaped}';")->fetchAll()
        );

        if ($exists) {
            $this->connection->query(
                "UPDATE `flames_migration` SET `hash` = '{$hash}', `version` = '" . static::__VERSION__ . "' WHERE `class` = '{$escaped}';"
            );
        } else {
            $this->connection->query(
                "INSERT INTO `flames_migration` (`id`, `class`, `hash`, `version`) VALUES (NULL, '{$escaped}', '{$hash}', " . static::__VERSION__ . ");"
            );
        }
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
                $queries[] = "ALTER TABLE `{$table}` DROP INDEX `{$keyName}`;";
            }
        }

        foreach ($columns as $column) {
            $expected = $expectedByColumn[$column->name];

            if ($expected['index']) {
                $exists = array_any(
                    $dbIndexes,
                    fn($idx) => $idx['Key_name'] !== 'PRIMARY'
                        && $idx['Column_name'] === $column->name
                        && (int) $idx['Non_unique'] === 1
                );

                if ($exists === false) {
                    $queries[] = "ALTER TABLE `{$table}` ADD INDEX (`{$column->name}`);";
                }
            }

            if ($expected['unique']) {
                $exists = array_any(
                    $dbIndexes,
                    fn($idx) => $idx['Key_name'] !== 'PRIMARY'
                        && $idx['Column_name'] === $column->name
                        && (int) $idx['Non_unique'] === 0
                );

                if ($exists === false) {
                    $queries[] = "ALTER TABLE `{$table}` ADD UNIQUE (`{$column->name}`);";
                }
            }
        }

        return $queries;
    }

    protected static function __syncCompositeIndexes(string $table, array $columns, array $modelIndexes, array $dbIndexes): array
    {
        $queries = [];
        $modelColumnNames = array_map(static fn($column) => $column->name, $columns);

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
            if (array_any($indexColumns, static fn($column) => in_array($column, $modelColumnNames, true) === false)) {
                continue;
            }

            $existingComposites[$keyName] = $indexColumns;
        }

        foreach ($existingComposites as $keyName => $indexColumns) {
            if (self::__indexColumnsInList($indexColumns, $expected) === false) {
                $queries[] = "ALTER TABLE `{$table}` DROP INDEX `{$keyName}`;";
            }
        }

        foreach ($expected as $indexColumns) {
            $exists = array_any(
                $existingComposites,
                static fn($existingColumns) => $existingColumns === $indexColumns
            );

            if ($exists === false) {
                $queries[] = self::__addCompositeIndexQuery($table, $indexColumns);
            }
        }

        return $queries;
    }

    protected static function __groupDbIndexes(array $dbIndexes): array
    {
        $indexGroups = [];

        foreach ($dbIndexes as $idx) {
            if ($idx['Key_name'] === 'PRIMARY') {
                continue;
            }

            $keyName = $idx['Key_name'];
            if (isset($indexGroups[$keyName]) === false) {
                $indexGroups[$keyName] = [
                    'non_unique' => (int) $idx['Non_unique'],
                    'columns'    => [],
                ];
            }

            $indexGroups[$keyName]['columns'][(int) $idx['Seq_in_index']] = $idx['Column_name'];
        }

        foreach ($indexGroups as $keyName => $index) {
            ksort($indexGroups[$keyName]['columns']);
        }

        return $indexGroups;
    }

    protected static function __indexColumnsInList(array $columns, array $list): bool
    {
        foreach ($list as $expected) {
            if ($expected === $columns) {
                return true;
            }
        }

        return false;
    }

    protected static function __addCompositeIndexQuery(string $table, array $columns): string
    {
        $columnSql = implode('`, `', $columns);
        $name      = self::__compositeIndexName($columns);

        return "ALTER TABLE `{$table}` ADD INDEX `{$name}` (`{$columnSql}`);";
    }

    protected static function __compositeIndexName(array $columns): string
    {
        $name = 'idx_' . implode('_', $columns);

        if (strlen($name) <= 64) {
            return $name;
        }

        return 'idx_' . substr(sha1(implode("\0", $columns)), 0, 59);
    }

    protected static function ddlDriverName(): string
    {
        return 'mysql';
    }

    protected static function __createColumnBase(Arr $column): string
    {
        $q = \Flames\Orm\Database\Type\Kinds::ddlType($column, static::ddlDriverName());

        if ($column->primary === true) {
            return $q . ' NOT NULL';
        }

        return $q . match (true) {
            $column->nullable === false          => ' NOT NULL',
            $column->default  === null           => ' DEFAULT NULL',
            default                              => \Flames\Orm\Database\Type\Kinds::ddlDefault($column),
        };
    }

    protected function __mountMigration(): void
    {
        $this->connection->query('
            CREATE TABLE `flames_migration` (
                `id`      bigint(20)    NOT NULL,
                `class`   varchar(1024) NOT NULL,
                `hash`    varchar(40)   NOT NULL,
                `version` int(11)       NOT NULL
            ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
        ');
        $this->connection->query('ALTER TABLE `flames_migration` ADD PRIMARY KEY (`id`);');
        $this->connection->query('ALTER TABLE `flames_migration` MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;');
    }

    protected function __ensureColumnOrder(object $data): void
    {
        try {
            $stmt = $this->connection->query('SHOW COLUMNS FROM `' . $data->table . '`;');
        } catch (\PDOException) {
            return;
        }

        $currentCols = array_column($stmt->fetchAll(), 'Field');
        $modelCols   = $this->__modelColumnNames($data);

        if ($currentCols === $modelCols || $this->__sameColumnSet($currentCols, $modelCols) === false) {
            return;
        }

        $this->__syncColumnOrder($data->table, (array) $data->column, $modelCols);
    }

    /** @param list<\Flames\Collection\Arr> $columns @param list<string> $modelCols */
    protected function __syncColumnOrder(string $table, array $columns, array $modelCols): void
    {
        $columnsByName = [];
        foreach ($columns as $column) {
            $columnsByName[$column->name] = $column;
        }

        $currentCols = array_column(
            $this->connection->query('SHOW COLUMNS FROM `' . $table . '`;')->fetchAll(),
            'Field',
        );

        foreach ($modelCols as $i => $colName) {
            if (($currentCols[$i] ?? null) === $colName) {
                continue;
            }

            $column = $columnsByName[$colName];
            $base   = static::__createColumnBase($column);
            $prev   = $i > 0 ? $modelCols[$i - 1] : null;
            $after  = $prev !== null ? " AFTER `{$prev}`" : '';
            $alter  = "ALTER TABLE `{$table}` MODIFY `{$colName}` {$base}{$after}";

            if ($column->autoIncrement && \Flames\Orm\Database\Type\Kinds::isNumericAutoIncrementType($column)) {
                $alter .= ' AUTO_INCREMENT';
            }

            $this->connection->query($alter . ';');

            $currentCols = array_column(
                $this->connection->query('SHOW COLUMNS FROM `' . $table . '`;')->fetchAll(),
                'Field',
            );
        }
    }

    public function migrateQueue(string $table): void
    {
        if (empty($this->allTables)) {
            $this->_updateTables();
        }

        // PHP 8.4 array_any
        if (array_any($this->allTables, fn($t) => $t === $table)) {
            return;
        }

        $this->connection->query("
            CREATE TABLE `{$table}` (
                `id`         bigint(20)  NOT NULL,
                `process_id` varchar(40) DEFAULT NULL,
                `data`       longtext    NOT NULL,
                `date`       datetime    DEFAULT NULL
            ) DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
        ");
        $this->connection->query("ALTER TABLE `{$table}` ADD PRIMARY KEY (`id`);");
        $this->connection->query("ALTER TABLE `{$table}` MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;");

        $this->allTables[] = $table;
    }
}
