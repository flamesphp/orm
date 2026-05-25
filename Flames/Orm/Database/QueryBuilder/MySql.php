<?php

namespace Flames\Orm\Database\QueryBuilder;

use Flames\Collection\Arr;
use PDO;
use Exception;

/**
 * @internal
 */
class MySql extends DefaultEx
{
    // Per-table column list (survives across requests in long-running processes)
    private static array $columnCache = [];

    // Cached SELECT column SQL string per table  (avoids rebuilding string on each get())
    private static array $selectSqlCache = [];

    // Prepared statement cache keyed by connection-id + query hash
    private static array $stmtCache = [];

    // ── WHERE clause ─────────────────────────────────────────────────────────

    protected function _nativeWhere(array $data): array
    {
        if (empty($this->wheres)) {
            return ['data' => $data, 'query' => ''];
        }

        $fragments  = [];
        $whereIndex = 0;

        foreach ($this->wheres as $where) {
            [$fragment, $data, $whereIndex] = match ($where['type']) {
                WhereType::Simple   => $this->_whereSimplePart($where, $data, $whereIndex),
                WhereType::Raw      => $this->_whereRawPart($where, $data, $whereIndex),
                WhereType::Delegate => $this->_whereDelegatePart($where, $data, $whereIndex),
            };
            $fragments[] = [$where['operator']->value, $fragment];
        }

        $sql = array_reduce(
            $fragments,
            fn($carry, $item) => $carry === '' ? $item[1] : "$carry {$item[0]} {$item[1]}",
            ''
        );

        return ['data' => $data, 'query' => $sql . "\r\n"];
    }

    private function _whereSimplePart(array $w, array $data, int $idx): array
    {
        $base = 'where_' . $this->whereBaseIndex . $idx . '_' . $w['key'];
        $col  = '`' . $this->table . '`.`' . $w['key'] . '`';

        if ($w['condition'] === 'IN') {
            $params = [];
            foreach ($w['value'] as $v) {
                $k = $base . '_' . $idx++;
                $params[]  = $k;
                $data[$k]  = $v;
            }
            return ["$col IN (:" . implode(', :', $params) . ')', $data, $idx];
        }

        if ($w['condition'] === 'LIKE') {
            $data[$base] = $w['value'];
            return ["$col LIKE CONCAT('%', :$base, '%')", $data, ++$idx];
        }

        $data[$base] = $w['value'];
        return ["$col {$w['condition']} :$base", $data, ++$idx];
    }

    private function _whereRawPart(array $w, array $data, int $idx): array
    {
        if ($w['condition'] === '' || $w['value'] === null) {
            return ['(' . $w['condition'] . ')', $data, $idx];
        }

        $condition = $w['condition'];
        foreach ($w['value'] as $key => $value) {
            $pKey        = 'where_' . $this->whereBaseIndex . $idx++ . '_' . $key;
            $condition   = str_replace('{' . $key . '}', ':' . $pKey, $condition);
            $data[$pKey] = $value;
        }
        return ['(' . $condition . ')', $data, $idx];
    }

    private function _whereDelegatePart(array $w, array $data, int $idx): array
    {
        $sub = new static($this->connection);
        $sub->_setBaseIndex($this->whereBaseIndex . $idx . '_');
        $this->mode === 'model' ? $sub->setModel($this->model) : $sub->setTable($this->table);

        ($w['value'])($sub);

        $result = $sub->_nativeWhere([]);
        return ['(' . rtrim($result['query']) . ')', array_merge($data, $result['data']), $idx + 1];
    }

    // ── ORDER / GROUP (unified helper) ────────────────────────────────────────

    private function _clauseList(array $items, bool $withDirection = false): string
    {
        return empty($items) ? '' : implode(",\r\n", array_map(
            fn($i) => '`' . $this->table . '`.`' . $i['key'] . '`' . ($withDirection ? ' ' . $i['direction'] : ''),
            $items
        )) . "\r\n";
    }

    protected function _nativeOrder(): string { return $this->_clauseList($this->orders, true); }
    protected function _nativeGroup(): string { return $this->_clauseList($this->groups); }
    protected function _nativeJoin():  string { return ''; }
    public    function join(string $model, string $name, callable $delegate): static { return $this; }

    // ── Column resolution ─────────────────────────────────────────────────────

    private function _tableColumns(): array
    {
        if (isset(self::$columnCache[$this->table])) {
            return self::$columnCache[$this->table];
        }

        if ($this->mode === 'table') {
            $cols = array_column(
                $this->connection->query('SHOW COLUMNS FROM `' . $this->table . '`;')->fetchAll(),
                'Field'
            );
        } else {
            $cols = [];
            foreach ($this->modelData->column as $col) {
                $cols[] = $col->name;
            }
        }

        return self::$columnCache[$this->table] = $cols;
    }

    private function _selectColumnsSql(): string
    {
        return self::$selectSqlCache[$this->table] ??= implode(",\r\n", array_map(
            fn($col) => '`' . $this->table . '`.`' . $col . "` AS '" . $this->table . '.' . $col . "'",
            $this->_tableColumns()
        ));
    }

    // ── Prepared statement cache ──────────────────────────────────────────────

    private function _prepare(string $sql): \PDOStatement
    {
        $key = spl_object_id($this->connection) . ':' . $sql;
        return self::$stmtCache[$key] ??= $this->connection->prepare($sql);
    }

    // ── Query builders ────────────────────────────────────────────────────────

    private function _buildGetSql(array &$data): string
    {
        $sql = 'SELECT ' . $this->_selectColumnsSql() . "\r\nFROM `{$this->table}` " . $this->_nativeJoin();

        $where = $this->_nativeWhere($data);
        $data  = $where['data'];
        if ($where['query'] !== '') { $sql .= "\r\nWHERE\r\n"    . $where['query'];      }

        $group = $this->_nativeGroup();
        if ($group !== '')          { $sql .= "\r\nGROUP BY\r\n" . $group;               }

        $order = $this->_nativeOrder();
        if ($order !== '')          { $sql .= "\r\nORDER BY\r\n" . $order;               }

        if ($this->limit  !== null) { $sql .= "\r\nLIMIT "  . $this->limit;              }
        if ($this->offset !== null) { $sql .= "\r\nOFFSET " . $this->offset;             }

        return $sql;
    }

    private function _colName(string $key): string
    {
        return $this->mode === 'model' ? $this->modelData->column[$key]->name : $key;
    }

    // ── Result hydration ──────────────────────────────────────────────────────

    private function _hydrateModels(\PDOStatement $stmt): Arr
    {
        $cast   = $this->modelCast;
        $class  = $this->modelData->class;
        $models = Arr();

        // Pre-build alias→col map once — avoids string concat inside the row loop
        $aliasMap = [];
        foreach ($this->modelData->column as $col) {
            $aliasMap[$this->table . '.' . $col->name] = $col;
        }

        while ($row = $stmt->fetch()) {
            $modelData = [];
            foreach ($aliasMap as $alias => $col) {
                if (isset($row[$alias])) {
                    $modelData[$col->property] = $cast::pos($col, $row[$alias], true);
                }
            }
            $models[] = new $class($modelData, true);
        }
        return $models;
    }

    // ── Prepare model data (cast pos → pre) ───────────────────────────────────

    private function _prepareModelData(array $data): array
    {
        return $data |> $this->_castDataPos(...) |> $this->_castDataPre(...);
    }

    // ── Public API ────────────────────────────────────────────────────────────

    #[\NoDiscard('get() returns the result collection')]
    public function get(): Arr
    {
        $data = [];
        $stmt = $this->_prepare($this->_buildGetSql($data));
        $stmt->execute($data);

        return $this->mode === 'model' ? $this->_hydrateModels($stmt) : Arr($stmt->fetchAll());
    }

    #[\NoDiscard('update() returns true on success')]
    public function update(Arr|array $data): bool
    {
        $data = $this->mode === 'model' ? $this->_prepareModelData((array)$data) : (array)$data;

        if (empty($data)) {
            throw new Exception("Update payload in table {$this->table} can't be empty.");
        }

        $set   = implode(', ', array_map(fn($k) => '`' . $this->_colName($k) . '` = :' . $k, array_keys($data)));
        $sql   = "UPDATE `{$this->table}` " . $this->_nativeJoin() . " SET $set\r\n";

        $where = $this->_nativeWhere($data);
        $data  = $where['data'];
        if ($where['query'] !== '') { $sql .= "\r\nWHERE\r\n" . $where['query']; }

        $order = $this->_nativeOrder();
        if ($order !== '')          { $sql .= "\r\nORDER BY\r\n" . $order;        }
        if ($this->limit !== null)  { $sql .= "\r\nLIMIT " . $this->limit;        }

        $this->_prepare($sql)->execute($data);
        return true;
    }

    #[\NoDiscard('insert() returns the new ID or primary key')]
    public function insert(Arr|array $data): mixed
    {
        $data = $this->mode === 'model' ? $this->_prepareModelData((array)$data) : (array)$data;

        if (empty($data)) {
            throw new Exception("Insert payload in table {$this->table} can't be empty.");
        }

        $cols = implode(', ', array_map(fn($k) => '`' . $this->_colName($k) . '`', array_keys($data)));
        $vals = implode(', ', array_map(fn($k) => ':' . $k, array_keys($data)));

        $this->_prepare("INSERT INTO `{$this->table}` ($cols) VALUES ($vals);")->execute($data);

        $id = $this->connection->lastInsertId();

        if ($this->mode === 'model') {
            $cast = $this->modelCast;
            foreach ($this->modelData->column as $col) {
                if ($col->autoIncrement) {
                    return Arr([$col->property => $cast::pos($col, $id)]);
                }
            }
        }

        return $id;
    }
}
