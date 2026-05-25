<?php

namespace Flames\Orm\Database\Driver;

use Flames\Collection\Arr;
use PDO;

/**
 * @internal
 */
class Mysql extends DefaultEx
{
    protected const __VERSION__ = 2;

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

        // PHP 8.5 pipe operator: build path → get mtime → hash
        $hash = ROOT_PATH . str_replace('\\', '/', $data->class) . '.php'
            |> filemtime(...)
            |> strval(...)
            |> sha1(...);

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
                if ((int)$row['version'] === self::__VERSION__) {
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
            $column->base = self::__createColumnBase($column);
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
            if ($column->autoIncrement) { $queries[] = "ALTER TABLE `{$data->table}` MODIFY `{$column->name}` {$column->base} AUTO_INCREMENT;"; }
        }

        array_walk($queries, fn($q) => $this->connection->query($q));

        $escaped = str_replace('\\', '\\\\', $data->class);
        $this->connection->query(
            "INSERT INTO `flames_migration` (`id`, `class`, `hash`, `version`) VALUES (NULL, '{$escaped}', '{$hash}', " . self::__VERSION__ . ");"
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
            $base      = self::__createColumnBase($column);

            if (!isset($dbColumns[$column->name])) {
                $missingDb[] = $column;
                continue;
            }

            $alter = "ALTER TABLE `{$data->table}` MODIFY `{$column->name}` {$base}";
            $queries[] = $alter . ($column->autoIncrement ? ' AUTO_INCREMENT' : '') . ';';
        }

        foreach ($missingDb as $column) {
            $base = self::__createColumnBase($column);

            // PHP 8.4 array_find: find the column just before this one
            $prev   = null;
            $found  = false;
            foreach ($columns as $c) {
                if ($c->name === $column->name) { $found = true; break; }
                $prev = $c;
            }
            $after     = ($prev !== null) ? " AFTER `{$prev->name}`" : '';
            $queries[] = "ALTER TABLE `{$data->table}` ADD `{$column->name}` {$base}{$after};";
            if ($column->autoIncrement) {
                $queries[] = "ALTER TABLE `{$data->table}` MODIFY `{$column->name}` {$base} AUTO_INCREMENT;";
            }
        }

        $dbIndexes = $this->connection->query('SHOW INDEX FROM `' . $data->table . '`;')->fetchAll();

        foreach ($columns as $column) {
            $dbCol = $dbColumns[$column->name] ?? null;

            if ($column->primary && ($dbCol === null || $dbCol['Key'] !== 'PRI')) {
                $queries[] = "ALTER TABLE `{$data->table}` ADD PRIMARY KEY (`{$column->name}`);";
            }

            if ($column->index) {
                // PHP 8.4 array_any
                $exists = array_any(
                    $dbIndexes,
                    fn($idx) => $idx['Key_name'] !== 'PRIMARY' && $idx['Column_name'] === $column->name
                );
                if (!$exists) {
                    $queries[] = "ALTER TABLE `{$data->table}` ADD INDEX (`{$column->name}`);";
                }
            }

            if ($column->unique) {
                $exists = array_any(
                    $dbIndexes,
                    fn($idx) => $idx['Key_name'] !== 'PRIMARY'
                             && $idx['Non_unique'] == 0
                             && $idx['Column_name'] === $column->name
                );
                if (!$exists) {
                    $queries[] = "ALTER TABLE `{$data->table}` ADD UNIQUE (`{$column->name}`);";
                }
            }
        }

        array_walk($queries, fn($q) => $this->connection->query($q));

        $currentCols = array_column(
            $this->connection->query('SHOW COLUMNS FROM `' . $data->table . '`;')->fetchAll(),
            'Field'
        );
        $modelCols = array_column($columns, 'name');
        $extraCols = array_diff($currentCols, $modelCols);

        array_walk($extraCols, fn($col) => $this->connection->query("ALTER TABLE `{$data->table}` DROP COLUMN `{$col}`;"));

        $escaped = str_replace('\\', '\\\\', $data->class);
        $exists  = !empty(
            $this->connection->query("SELECT `id` FROM `flames_migration` WHERE `class` = '{$escaped}';")->fetchAll()
        );

        if ($exists) {
            $this->connection->query(
                "UPDATE `flames_migration` SET `hash` = '{$hash}', `version` = '" . self::__VERSION__ . "' WHERE `class` = '{$escaped}';"
            );
        } else {
            $this->connection->query(
                "INSERT INTO `flames_migration` (`id`, `class`, `hash`, `version`) VALUES (NULL, '{$escaped}', '{$hash}', " . self::__VERSION__ . ");"
            );
        }
    }

    protected static function __createColumnBase(Arr $column): string
    {
        $q = $column->type;

        if ($column->size !== null && in_array($column->type, ['bigint', 'int', 'varchar', 'tinyint'], true)) {
            $q .= '(' . $column->size . ')';
        }

        return $q . match (true) {
            $column->nullable === false          => ' NOT NULL',
            $column->default  === null           => ' DEFAULT NULL',
            default                              => " DEFAULT '" . $column->default . "'",
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
