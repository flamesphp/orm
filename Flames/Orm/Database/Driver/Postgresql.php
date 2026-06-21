<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Driver;

use Flames\Collection\Arr;
use Flames\Orm\Database\Type\Kinds;
use PDO;

/**
 * @internal
 */
class Postgresql extends DefaultEx
{
    protected const __VERSION__ = 2;

    protected $connection         = null;
    protected array $tableUpdated      = [];
    protected array $tablesMigrations  = [];
    protected array $allTables         = [];
    private ?array $availableExtensions = null;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    public function getQueryBuilder($model): \Flames\Orm\Database\QueryBuilder\Postgresql
    {
        return new \Flames\Orm\Database\QueryBuilder\Postgresql($this->connection);
    }

    public function migrate($data): bool
    {
        if (isset($this->tableUpdated[$data->class])) {
            return true;
        }

        $hash = $this->__migrationHash($data);

        if (empty($this->tablesMigrations)) {
            try {
                $rows = $this->connection
                    ->query(
                        'SELECT '
                        . self::_q('class') . ', '
                        . self::_q('hash') . ', '
                        . self::_q('version')
                        . ' FROM ' . self::_q('flames_migration') . ';'
                    )
                    ->fetchAll();
            } catch (\PDOException $e) {
                $this->__mountMigration();
                $rows = $this->connection
                    ->query(
                        'SELECT '
                        . self::_q('class') . ', '
                        . self::_q('hash') . ', '
                        . self::_q('version')
                        . ' FROM ' . self::_q('flames_migration') . ';'
                    )
                    ->fetchAll();
            }

            if (empty($rows)) {
                $this->__mountTable($data, $hash);
                $this->tableUpdated[$data->class] = true;
                return true;
            }

            foreach ($rows as $row) {
                if ((int)$row['version'] === self::__VERSION__) {
                    $this->tablesMigrations[$row['class']] = $row['hash'];
                }
            }

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
            $this->connection
                ->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public';")
                ->fetchAll(PDO::FETCH_NUM),
            0
        );
    }

    protected function __mountTable($data, string $hash): void
    {
        $this->__ensureExtensionsForModel($data);

        if (empty($this->allTables)) {
            $this->_updateTables();
        }

        if (array_any($this->allTables, fn($t) => $t === $data->table)) {
            $this->__updateTable($data, $hash);
            return;
        }

        $primaryCols = [];
        $colDefs     = [];

        foreach ((array) $data->column as $column) {
            $column->base = static::__createColumnBase($column);
            $colDefs[]    = "\t" . self::_q($column->name) . ' ' . $column->base;

            if ($column->primary) {
                $primaryCols[] = self::_q($column->name);
            }
        }

        $pkSql = $primaryCols !== []
            ? ",\n\tPRIMARY KEY (" . implode(', ', $primaryCols) . ')'
            : '';

        $this->connection->query(
            'CREATE TABLE ' . self::_q($data->table) . " (\n"
            . implode(",\n", $colDefs)
            . $pkSql
            . "\n);"
        );

        $queries = [];

        foreach ($data->column as $column) {
            if ($column->index) {
                $queries[] = self::__createIndexQuery($data->table, [$column->name], false);
            }
            if ($column->unique) {
                $queries[] = self::__createIndexQuery($data->table, [$column->name], true);
            }
        }

        array_push(
            $queries,
            ...self::__syncCompositeIndexes($data->table, (array) $data->column, (array) ($data->index ?? []), [])
        );

        array_walk($queries, fn($q) => $this->connection->query($q));

        $escaped = str_replace('\\', '\\\\', $data->class);
        $this->connection->query(
            'INSERT INTO ' . self::_q('flames_migration') . ' ('
            . self::_q('class') . ', '
            . self::_q('hash') . ', '
            . self::_q('version')
            . ") VALUES ('{$escaped}', '{$hash}', " . self::__VERSION__ . ');'
        );

        $this->allTables[] = $data->table;
    }

    protected function __updateTable($data, string $hash): void
    {
        $this->__ensureExtensionsForModel($data);

        try {
            $dbColumns = $this->__fetchDbColumns($data->table);
        } catch (\PDOException $e) {
            $this->__mountTable($data, $hash);
            return;
        }

        if ($dbColumns === []) {
            $this->__mountTable($data, $hash);
            return;
        }

        $queries           = [];
        $columns           = [];
        $missingDb         = [];
        $dbPrimaryColumns  = $this->__fetchDbPrimaryKeyColumns($data->table);

        foreach ($data->column as $column) {
            $columns[] = $column;

            if (!isset($dbColumns[$column->name])) {
                $missingDb[] = $column;
                continue;
            }

            array_push($queries, ...self::__columnAlterQueries($data->table, $column));
        }

        foreach ($missingDb as $column) {
            $base      = static::__createColumnBase($column);
            $queries[] = 'ALTER TABLE ' . self::_q($data->table)
                . ' ADD COLUMN ' . self::_q($column->name) . ' ' . $base . ';';
        }

        $dbIndexes = $this->__fetchDbIndexes($data->table);

        foreach ($columns as $column) {
            if ($column->primary && in_array($column->name, $dbPrimaryColumns, true) === false) {
                $queries[] = 'ALTER TABLE ' . self::_q($data->table)
                    . ' ADD PRIMARY KEY (' . self::_q($column->name) . ');';
            }
        }

        array_push($queries, ...self::__syncColumnIndexes($data->table, $columns, $dbIndexes));
        array_push(
            $queries,
            ...self::__syncCompositeIndexes($data->table, $columns, (array) ($data->index ?? []), $dbIndexes)
        );

        array_walk($queries, fn($q) => $this->connection->query($q));

        $currentCols = array_keys($this->__fetchDbColumns($data->table));
        $modelCols   = array_column($columns, 'name');
        $extraCols   = array_diff($currentCols, $modelCols);

        array_walk(
            $extraCols,
            fn($col) => $this->connection->query(
                'ALTER TABLE ' . self::_q($data->table) . ' DROP COLUMN ' . self::_q($col) . ';'
            )
        );

        $this->__ensureColumnOrder($data);

        $escaped = str_replace('\\', '\\\\', $data->class);
        $exists  = !empty(
            $this->connection->query(
                'SELECT ' . self::_q('id')
                . ' FROM ' . self::_q('flames_migration')
                . ' WHERE ' . self::_q('class') . " = '{$escaped}';"
            )->fetchAll()
        );

        if ($exists) {
            $this->connection->query(
                'UPDATE ' . self::_q('flames_migration')
                . ' SET ' . self::_q('hash') . " = '{$hash}', "
                . self::_q('version') . " = '" . self::__VERSION__ . "'"
                . ' WHERE ' . self::_q('class') . " = '{$escaped}';"
            );
        } else {
            $this->connection->query(
                'INSERT INTO ' . self::_q('flames_migration') . ' ('
                . self::_q('class') . ', '
                . self::_q('hash') . ', '
                . self::_q('version')
                . ") VALUES ('{$escaped}', '{$hash}', " . self::__VERSION__ . ');'
            );
        }
    }

    protected function __fetchDbColumns(string $table): array
    {
        $escaped = str_replace("'", "''", $table);
        $stmt    = $this->connection->query(
            'SELECT column_name, is_nullable, column_default, data_type'
            . ' FROM information_schema.columns'
            . " WHERE table_schema = 'public' AND table_name = '{$escaped}'"
            . ' ORDER BY ordinal_position;'
        );

        $dbColumns = [];
        foreach ($stmt->fetchAll() as $row) {
            $dbColumns[$row['column_name']] = $row;
        }

        return $dbColumns;
    }

    protected function __fetchDbPrimaryKeyColumns(string $table): array
    {
        $escaped = str_replace("'", "''", $table);
        $stmt    = $this->connection->query(
            'SELECT kcu.column_name'
            . ' FROM information_schema.table_constraints tc'
            . ' JOIN information_schema.key_column_usage kcu'
            . ' ON tc.constraint_name = kcu.constraint_name'
            . ' AND tc.table_schema = kcu.table_schema'
            . " WHERE tc.constraint_type = 'PRIMARY KEY'"
            . " AND tc.table_schema = 'public'"
            . " AND tc.table_name = '{$escaped}'"
            . ' ORDER BY kcu.ordinal_position;'
        );

        return array_column($stmt->fetchAll(), 'column_name');
    }

    protected function __fetchDbIndexes(string $table): array
    {
        $escaped = str_replace("'", "''", $table);
        $stmt    = $this->connection->query(
            'SELECT'
            . ' i.relname AS "Key_name",'
            . ' CASE WHEN ix.indisunique THEN 0 ELSE 1 END AS "Non_unique",'
            . ' a.attname AS "Column_name",'
            . ' k.ord AS "Seq_in_index"'
            . ' FROM pg_class t'
            . ' JOIN pg_namespace n ON n.oid = t.relnamespace'
            . ' JOIN pg_index ix ON t.oid = ix.indrelid'
            . ' JOIN pg_class i ON i.oid = ix.indexrelid'
            . ' JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY AS k(attnum, ord) ON true'
            . ' JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum'
            . " WHERE n.nspname = 'public'"
            . " AND t.relname = '{$escaped}'"
            . ' AND ix.indisprimary = false'
            . ' ORDER BY i.relname, k.ord;'
        );

        return $stmt->fetchAll();
    }

    protected static function __columnAlterQueries(string $table, Arr $column): array
    {
        $queries = [];
        $qTable  = self::_q($table);
        $qCol    = self::_q($column->name);
        $type    = self::__alterColumnType($column);

        $queries[] = "ALTER TABLE {$qTable} ALTER COLUMN {$qCol} TYPE {$type};";

        if ($column->primary === true || $column->nullable === false) {
            $queries[] = "ALTER TABLE {$qTable} ALTER COLUMN {$qCol} SET NOT NULL;";
        } else {
            $queries[] = "ALTER TABLE {$qTable} ALTER COLUMN {$qCol} DROP NOT NULL;";
        }

        if ($column->default === null && $column->primary === false) {
            $queries[] = "ALTER TABLE {$qTable} ALTER COLUMN {$qCol} DROP DEFAULT;";
        } elseif ($column->default !== null) {
            $default   = trim(\Flames\Orm\Database\Type\Kinds::ddlDefault($column, 'postgresql'));
            $queries[] = "ALTER TABLE {$qTable} ALTER COLUMN {$qCol} SET {$default};";
        }

        return $queries;
    }

    protected static function __alterColumnType(Arr $column): string
    {
        $type = \Flames\Orm\Database\Type\Kinds::ddlType($column, 'postgresql');

        return match ($type) {
            'bigserial'   => 'bigint',
            'serial'      => 'integer',
            'smallserial' => 'smallint',
            default       => $type,
        };
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

            $expected   = $expectedByColumn[$columnName];
            $isUnique   = $index['non_unique'] === 0;
            $shouldKeep = ($isUnique && $expected['unique']) || ($isUnique === false && $expected['index']);

            if ($shouldKeep === false) {
                $queries[] = 'DROP INDEX IF EXISTS ' . self::_q($keyName) . ';';
            }
        }

        foreach ($columns as $column) {
            $expected = $expectedByColumn[$column->name];

            if ($expected['index']) {
                $exists = array_any(
                    $dbIndexes,
                    fn($idx) => $idx['Column_name'] === $column->name
                        && (int) $idx['Non_unique'] === 1
                );

                if ($exists === false) {
                    $queries[] = self::__createIndexQuery($table, [$column->name], false);
                }
            }

            if ($expected['unique']) {
                $exists = array_any(
                    $dbIndexes,
                    fn($idx) => $idx['Column_name'] === $column->name
                        && (int) $idx['Non_unique'] === 0
                );

                if ($exists === false) {
                    $queries[] = self::__createIndexQuery($table, [$column->name], true);
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
                $queries[] = 'DROP INDEX IF EXISTS ' . self::_q($keyName) . ';';
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

    protected static function __createIndexQuery(string $table, array $columns, bool $unique): string
    {
        $name = count($columns) === 1
            ? self::__singleColumnIndexName($table, $columns[0], $unique)
            : self::__compositeIndexName($columns);

        $columnSql = implode(', ', array_map(static fn(string $column): string => self::_q($column), $columns));
        $uniqueSql = $unique ? 'UNIQUE ' : '';

        return 'CREATE ' . $uniqueSql . 'INDEX IF NOT EXISTS '
            . self::_q($name)
            . ' ON ' . self::_q($table)
            . ' (' . $columnSql . ');';
    }

    protected static function __addCompositeIndexQuery(string $table, array $columns): string
    {
        return self::__createIndexQuery($table, $columns, false);
    }

    protected static function __singleColumnIndexName(string $table, string $column, bool $unique): string
    {
        $prefix = $unique ? 'uniq_' : 'idx_';
        $name   = $prefix . $table . '_' . $column;

        if (strlen($name) <= 63) {
            return $name;
        }

        return $prefix . substr(sha1($table . "\0" . $column), 0, 58);
    }

    protected static function __compositeIndexName(array $columns): string
    {
        $name = 'idx_' . implode('_', $columns);

        if (strlen($name) <= 63) {
            return $name;
        }

        return 'idx_' . substr(sha1(implode("\0", $columns)), 0, 58);
    }

    protected static function ddlDriverName(): string
    {
        return 'postgresql';
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
            default                              => \Flames\Orm\Database\Type\Kinds::ddlDefault($column, 'postgresql'),
        };
    }

    protected static function _q(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    protected function __mountMigration(): void
    {
        $this->connection->query('
            CREATE TABLE ' . self::_q('flames_migration') . ' (
                ' . self::_q('id') . ' BIGSERIAL PRIMARY KEY,
                ' . self::_q('class') . ' varchar(1024) NOT NULL,
                ' . self::_q('hash') . ' varchar(40) NOT NULL,
                ' . self::_q('version') . ' int NOT NULL
            );
        ');
    }

    protected function __ensureExtensionsForModel(object $data): void
    {
        $needsPostgis = false;
        $needsVector  = false;

        foreach ((array) $data->column as $column) {
            $type = Kinds::normalize($column->type);

            if (Kinds::isSpatial($type)) {
                $needsPostgis = true;
            }

            if (Kinds::isVector($type)) {
                $needsVector = true;
            }
        }

        if ($needsPostgis) {
            $this->__ensureExtension('postgis');
        }

        if ($needsVector) {
            $this->__ensureExtension('vector');
        }
    }

    protected function __isExtensionAvailable(string $name): bool
    {
        $this->__loadAvailableExtensions();

        return isset($this->availableExtensions[$name]);
    }

    protected function __isExtensionInstalled(string $name): bool
    {
        self::__assertExtensionName($name);

        $row = $this->connection->query(
            "SELECT 1 FROM pg_extension WHERE extname = '" . str_replace("'", "''", $name) . "' LIMIT 1;"
        )->fetch();

        return $row !== false;
    }

    protected function __ensureExtension(string $name): void
    {
        self::__assertExtensionName($name);

        if (!$this->__isExtensionAvailable($name)) {
            throw new \RuntimeException(
                'PostgreSQL extension "' . $name . '" is not available on this server.'
                . ' Install it or remove columns that require it.'
            );
        }

        if ($this->__isExtensionInstalled($name)) {
            return;
        }

        $this->connection->query('CREATE EXTENSION IF NOT EXISTS ' . $name . ';');
    }

    protected function __loadAvailableExtensions(): void
    {
        if ($this->availableExtensions !== null) {
            return;
        }

        $this->availableExtensions = [];

        foreach ($this->connection->query('SELECT name FROM pg_available_extensions;')->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $this->availableExtensions[(string) $name] = true;
        }
    }

    protected static function __assertExtensionName(string $name): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('Invalid PostgreSQL extension name: ' . $name);
        }
    }

    protected function __ensureColumnOrder(object $data): void
    {
        try {
            $currentCols = array_keys($this->__fetchDbColumns($data->table));
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
        $colDefs = [];
        $pkCols  = [];

        foreach ($columns as $column) {
            $column->base = static::__createColumnBase($column);
            $colDefs[]    = self::_q($column->name) . ' ' . $column->base;

            if ($column->primary) {
                $pkCols[] = self::_q($column->name);
            }
        }

        $pkSql = $pkCols !== []
            ? ', PRIMARY KEY (' . implode(', ', $pkCols) . ')'
            : '';

        $colList = implode(', ', array_map(static fn($column) => self::_q($column->name), $columns));

        $this->connection->beginTransaction();

        try {
            $this->connection->query('DROP TABLE IF EXISTS ' . self::_q($temp) . ';');
            $this->connection->query(
                'CREATE TABLE ' . self::_q($temp) . ' (' . implode(', ', $colDefs) . $pkSql . ');'
            );
            $this->connection->query(
                'INSERT INTO ' . self::_q($temp) . ' (' . $colList . ') '
                . 'SELECT ' . $colList . ' FROM ' . self::_q($table) . ';'
            );
            $this->connection->query('DROP TABLE ' . self::_q($table) . ';');
            $this->connection->query(
                'ALTER TABLE ' . self::_q($temp) . ' RENAME TO ' . self::_q($table) . ';'
            );

            foreach ($columns as $column) {
                if ($column->autoIncrement === false) {
                    continue;
                }

                $escapedTable  = str_replace("'", "''", $table);
                $escapedColumn = str_replace("'", "''", $column->name);
                $qTable        = self::_q($table);
                $qCol          = self::_q($column->name);
                $this->connection->query(
                    'SELECT setval('
                    . "pg_get_serial_sequence('{$escapedTable}', '{$escapedColumn}'), "
                    . "COALESCE((SELECT MAX({$qCol}) FROM {$qTable}), 1), true);"
                );
            }

            $indexQueries = [];
            array_push(
                $indexQueries,
                ...self::__syncColumnIndexes($table, $columns, []),
                ...self::__syncCompositeIndexes($table, $columns, (array) ($data->index ?? []), []),
            );
            array_walk($indexQueries, fn($q) => $this->connection->query($q));

            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $e;
        }
    }

    public function migrateQueue(string $table): void
    {
        if (empty($this->allTables)) {
            $this->_updateTables();
        }

        if (array_any($this->allTables, fn($t) => $t === $table)) {
            return;
        }

        $this->connection->query('
            CREATE TABLE ' . self::_q($table) . ' (
                ' . self::_q('id') . ' bigserial PRIMARY KEY,
                ' . self::_q('process_id') . ' varchar(40) DEFAULT NULL,
                ' . self::_q('data') . ' text NOT NULL,
                ' . self::_q('date') . ' timestamp DEFAULT NULL
            );
        ');

        $this->allTables[] = $table;
    }
}
