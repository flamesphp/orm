<?php
declare(strict_types=1);

namespace Flames\Orm\Database\QueryBuilder;

use Flames\Collection\Arr;
use Flames\Orm\Database\QueryBuilder\Support\MongoFilter;
use Flames\Orm\Exception\UnsupportedQueryException;
use Exception;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Query;

/**
 * @internal
 */
class Mongodb extends DefaultEx
{
    private const DRIVER = 'mongodb';

    public function __construct(mixed $connection)
    {
        parent::__construct($connection);
    }

    public function group(string $key): static
    {
        throw new UnsupportedQueryException('group', self::DRIVER);
    }

    protected function _pushWhere(WhereOperator $operator, array $where): static
    {
        match ($where['type']) {
            WhereType::Expression => throw new UnsupportedQueryException('whereExpression', self::DRIVER),
            WhereType::Column     => throw new UnsupportedQueryException('whereColumn', self::DRIVER),
            WhereType::Bitwise    => throw new UnsupportedQueryException('whereBitwise', self::DRIVER),
            WhereType::Strcmp     => throw new UnsupportedQueryException('whereStrcmp', self::DRIVER),
            WhereType::RegexpLike => throw new UnsupportedQueryException('whereRegexpLike', self::DRIVER),
            default               => null,
        };

        return parent::_pushWhere($operator, $where);
    }

    protected function _pushSimpleWhere(
        WhereOperator $operator,
        string $key,
        string $condition,
        mixed $value,
        array $options = [],
    ): static {
        if (in_array($condition, ['REGEXP', 'RLIKE', 'NOT REGEXP', 'NOT RLIKE'], true)) {
            throw new UnsupportedQueryException('whereRegexp', self::DRIVER);
        }

        if (in_array($condition, ['LIKE_PATTERN', 'NOT_LIKE_PATTERN'], true)) {
            throw new UnsupportedQueryException('whereLikePattern', self::DRIVER);
        }

        if (in_array($condition, ['IS TRUE', 'IS FALSE', 'IS UNKNOWN', 'IS NOT TRUE', 'IS NOT FALSE', 'IS NOT UNKNOWN'], true)) {
            throw new UnsupportedQueryException('whereIsTrue', self::DRIVER);
        }

        if ($this->mode === 'model' && isset($this->modelData->column[$key])) {
            $column = $this->modelData->column[$key];
            $value  = match ($condition) {
                'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN' => array_map(
                    fn (mixed $item): mixed => $this->modelCast::pre($column, $item),
                    (array) $value,
                ),
                default => $this->modelCast::pre($column, $value),
            };
        }

        return parent::_pushSimpleWhere($operator, $key, $condition, $value, $options);
    }

    protected function _whereExpression(
        WhereOperator $operator,
        string $expression,
        string $compareOperator,
        mixed $value,
        Arr|array $bindings,
    ): static {
        throw new UnsupportedQueryException('whereExpression', self::DRIVER);
    }

    protected function _whereColumn(WhereOperator $operator, string $left, string $compareOperator, string $right): static
    {
        throw new UnsupportedQueryException('whereColumn', self::DRIVER);
    }

    protected function _whereBitwise(
        WhereOperator $operator,
        string $key,
        string $bitOperator,
        mixed $operand,
        string $compareOperator,
        mixed $compareValue,
    ): static {
        throw new UnsupportedQueryException('whereBitwise', self::DRIVER);
    }

    protected function _whereRegexp(WhereOperator $operator, string $key, mixed $pattern, string $condition): static
    {
        throw new UnsupportedQueryException('whereRegexp', self::DRIVER);
    }

    /**
     * @return array<string, mixed>
     */
    protected function _buildFilter(array $wheres): array
    {
        if ($wheres === []) {
            return [];
        }

        $compiled = [];

        foreach ($wheres as $where) {
            $filter = match ($where['type']) {
                WhereType::Delegate    => $this->_buildFilterDelegate($where, false),
                WhereType::NotDelegate => $this->_buildFilterDelegate($where, true),
                default                => MongoFilter::build([$where]),
            };

            if ($filter === []) {
                continue;
            }

            $compiled[] = [
                'filter'   => $filter,
                'operator' => $where['operator'],
            ];
        }

        if ($compiled === []) {
            return [];
        }

        $result = $compiled[0]['filter'];

        for ($index = 1, $count = count($compiled); $index < $count; $index++) {
            $result = match ($compiled[$index]['operator']) {
                WhereOperator::And => ['$and' => [$result, $compiled[$index]['filter']]],
                WhereOperator::Or  => ['$or' => [$result, $compiled[$index]['filter']]],
                WhereOperator::Xor => [
                    '$and' => [
                        ['$or' => [$result, $compiled[$index]['filter']]],
                        ['$nor' => [['$and' => [$result, $compiled[$index]['filter']]]]],
                    ],
                ],
            };
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function _buildFilterDelegate(array $where, bool $negated): array
    {
        $sub = new static($this->connection);
        if ($this->mode === 'model') {
            $sub->setModel($this->model);
        } else {
            $sub->setTable($this->table);
        }

        $sub->_ensureSoftDeleteScope();

        ($where['value'])($sub);
        $filter = $sub->_buildFilter($sub->wheres);

        if ($filter === []) {
            return [];
        }

        return $negated ? ['$nor' => [$filter]] : $filter;
    }

    protected function _prepareModelData(array $data): array
    {
        return $this->_castDataPre($data);
    }

    protected function _propertyDataToDocument(array $data): array
    {
        $document = [];

        foreach ($data as $property => $value) {
            if ($this->mode !== 'model' || !isset($this->modelData->column[$property])) {
                $document[$property] = $value;
                continue;
            }

            $document[$this->modelData->column[$property]->name] = $value;
        }

        return $document;
    }

    protected function _findPrimaryColumn(): ?object
    {
        if ($this->mode !== 'model') {
            return null;
        }

        foreach ($this->modelData->column as $column) {
            if ($column->primary === true) {
                return $column;
            }
        }

        return null;
    }

    protected function _generatePrimaryKeyValue(object $column): mixed
    {
        $type = \Flames\Orm\Database\Type\Kinds::normalize($column->type);

        if (in_array($type, ['int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'integer'], true)) {
            return (int) sprintf('%d%03d', (int) (microtime(true) * 1000), random_int(0, 999));
        }

        if ($type === 'uuid') {
            return (string) \Flames\Collection\Uuid::v4();
        }

        if (class_exists(\MongoDB\BSON\ObjectId::class)) {
            return new \MongoDB\BSON\ObjectId();
        }

        return $this->_generateUuid();
    }

    /**
     * Mirror the model primary key into MongoDB `_id`, always as the last field.
     * Model columns keep metadata order (id first, lifecycle columns last).
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    protected function _applyPrimaryKeyToDocument(array $document, ?object $pkColumn): array
    {
        if ($pkColumn === null) {
            return $document;
        }

        $pkName  = $pkColumn->name;
        $pkValue = $document[$pkName] ?? $document['_id'] ?? null;

        if ($pkValue === null) {
            return $document;
        }

        unset($document['_id']);

        $ordered = [];

        if ($this->mode === 'model') {
            foreach ($this->modelData->column as $column) {
                if ($column->name === '_id') {
                    continue;
                }

                if (array_key_exists($column->name, $document)) {
                    $ordered[$column->name] = $document[$column->name];
                    continue;
                }

                if ($column->name === $pkName) {
                    $ordered[$column->name] = $pkValue;
                }
            }

            foreach ($document as $key => $value) {
                if ($key !== '_id' && array_key_exists($key, $ordered) === false) {
                    $ordered[$key] = $value;
                }
            }
        } else {
            foreach ($document as $key => $value) {
                if ($key !== '_id') {
                    $ordered[$key] = $value;
                }
            }

            if (array_key_exists($pkName, $ordered) === false) {
                $ordered[$pkName] = $pkValue;
            }
        }

        $ordered['_id'] = $pkValue;

        return $ordered;
    }

    protected function _fetchDocumentByPrimaryKey(object $pkColumn, mixed $pkValue): ?array
    {
        $storedValue = $this->modelCast::pre($pkColumn, $pkValue);
        $filter      = [$pkColumn->name => $storedValue];
        $cursor      = $this->connection->getManager()->executeQuery(
            $this->connection->getNamespace($this->table),
            new Query($filter, ['limit' => 1]),
        );

        foreach ($cursor as $document) {
            return (array) $document;
        }

        return null;
    }

    protected function _resolveWherePrimaryKeyValue(): mixed
    {
        $pkColumn = $this->_findPrimaryColumn();
        if ($pkColumn === null) {
            throw new Exception('MongoDB update requires a model primary key.');
        }

        foreach ($this->wheres as $where) {
            if ($where['type'] !== WhereType::Simple) {
                continue;
            }

            if ($where['key'] !== $pkColumn->name) {
                continue;
            }

            if (in_array($where['condition'], ['=', '<=>'], true) === false) {
                continue;
            }

            return $where['value'];
        }

        throw new Exception('MongoDB update requires a primary key condition.');
    }

    protected function _insertOne(array $document): void
    {
        $bulk = new BulkWrite(['ordered' => true]);
        $bulk->insert($document);

        $this->_executeBulkWrite($bulk);
    }

    protected function _executeBulkWrite(BulkWrite $bulk): void
    {
        $this->connection->getManager()->executeBulkWrite(
            $this->connection->getNamespace($this->table),
            $bulk,
        );
    }

    public function get(): Arr
    {
        $this->_ensureSoftDeleteScope();

        $filter  = $this->_buildFilter($this->wheres);
        $options = [
            'limit' => $this->limit ?? 0,
            'skip'  => $this->offset ?? 0,
        ];

        if ($options['limit'] === 0) {
            unset($options['limit']);
        }

        if ($options['skip'] === 0) {
            unset($options['skip']);
        }

        if ($this->orders !== []) {
            $sort = [];
            foreach ($this->orders as $order) {
                $sort[$order['key']] = strtoupper($order['direction']) === 'DESC' ? -1 : 1;
            }
            $options['sort'] = $sort;
        }

        $query  = $filter === [] ? new Query([], $options) : new Query($filter, $options);
        $cursor = $this->connection->getManager()->executeQuery(
            $this->connection->getNamespace($this->table),
            $query,
        );

        if ($this->mode !== 'model') {
            $rows = [];
            foreach ($cursor as $document) {
                $rows[] = (array) $document;
            }

            return Arr($rows);
        }

        $cast    = $this->modelCast;
        $columns = $this->modelData->column;
        $class   = $this->modelData->class;
        $models  = Arr();

        foreach ($cursor as $document) {
            $document = (array) $document;
            $modelData = [];

            foreach ($columns as $column) {
                if (array_key_exists($column->name, $document)) {
                    $modelData[$column->property] = $cast::pos($column, $document[$column->name], true);
                    continue;
                }

                if ($column->primary === true && array_key_exists('_id', $document)) {
                    $modelData[$column->property] = $cast::pos($column, $document['_id'], true);
                }
            }

            $models[] = new $class($modelData, true);
        }

        return $models;
    }

    public function update(Arr|array $data): bool
    {
        $this->_ensureSoftDeleteScope();

        $data = (array) $data;

        if ($this->mode === 'model') {
            $data = $this->_prepareModelData($data);
        }

        if ($data === []) {
            throw new Exception("Update payload in table {$this->table} can't be empty.");
        }

        $pkColumn = $this->_findPrimaryColumn();
        if ($pkColumn === null) {
            throw new Exception('MongoDB update requires a primary key column.');
        }

        $pkValue  = $this->_resolveWherePrimaryKeyValue();
        $partial  = $this->_propertyDataToDocument($data);
        $existing = $this->_fetchDocumentByPrimaryKey($pkColumn, $pkValue);
        $document = $existing !== null ? array_merge($existing, $partial) : $partial;
        $document[$pkColumn->name] = $this->modelCast::pre($pkColumn, $pkValue);
        $document = $this->_applyPrimaryKeyToDocument($document, $pkColumn);

        $targetIds = $this->_resolveModifiedIdsForDocumentMutation();

        $bulk = new BulkWrite(['ordered' => true]);
        $bulk->update(
            ['_id' => $document['_id'] ?? $document[$pkColumn->name]],
            ['$set' => $document],
            ['upsert' => true],
        );
        $this->_executeBulkWrite($bulk);

        return $this->_finalizeUpdate(true, $targetIds);
    }

    public function insert(Arr|array $data): mixed
    {
        $data = (array) $data;

        if ($this->mode !== 'model') {
            return $this->_executeInsert($data);
        }

        return $this->_insertWithUuidCollisionRetry(
            $data,
            fn (array $payload): mixed => $this->_executeInsert($payload),
        );
    }

    private function _executeInsert(array $data): mixed
    {
        if ($this->mode === 'model') {
            $data = $this->_prepareModelData($data);
            $data = $this->_stripNullIdentityColumns($data);
            $data = $this->_ensureGeneratedPrimaryKeys($data);
        }

        if ($data === []) {
            throw new Exception("Insert payload in table {$this->table} can't be empty.");
        }

        $primaryKeyProperty = 'id';
        $pkColumn           = null;

        if ($this->mode === 'model') {
            $pkColumn = $this->_findPrimaryColumn();
            if ($pkColumn !== null) {
                $primaryKeyProperty = $pkColumn->property;

                if (array_key_exists($primaryKeyProperty, $data) === false) {
                    $data[$primaryKeyProperty] = $this->_generatePrimaryKeyValue($pkColumn);
                    $data[$primaryKeyProperty] = $this->modelCast::pre($pkColumn, $data[$primaryKeyProperty]);
                }
            }
        }

        $document = $this->_applyPrimaryKeyToDocument(
            $this->_propertyDataToDocument($data),
            $pkColumn,
        );
        $this->_insertOne($document);

        if ($pkColumn === null) {
            return $document;
        }

        return [
            $primaryKeyProperty => $this->modelCast::pos(
                $pkColumn,
                $document[$pkColumn->name] ?? $document['_id'] ?? $data[$primaryKeyProperty],
                true,
            ),
        ];
    }

    protected function _stripNullIdentityColumns(array $data): array
    {
        foreach ($this->modelData->column as $column) {
            if (($column->primary || $column->autoIncrement) === false) {
                continue;
            }

            if (array_key_exists($column->property, $data) === false) {
                continue;
            }

            $value = $data[$column->property];
            if ($value === null || ($column->autoIncrement && ($value === 0 || $value === '0'))) {
                unset($data[$column->property]);
            }
        }

        return $data;
    }

    protected function _generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        $hex     = bin2hex($data);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    protected function _executeSoftDelete(): int
    {
        $property = $this->_softDeleteColumnProperty();
        $column   = $this->modelData->column[$property];
        $filter   = $this->_buildFilter($this->wheres);

        if ($filter === []) {
            throw new Exception('MongoDB soft delete requires where conditions.');
        }

        $bulk = new BulkWrite(['ordered' => true]);
        $bulk->update(
            $filter,
            ['$set' => [$column->name => $this->modelCast::pre($column, $this->_softDeleteTimestamp())]],
            ['multi' => true],
        );

        $result = $this->connection->getManager()->executeBulkWrite(
            $this->connection->getNamespace($this->table),
            $bulk,
        );

        return $result->getModifiedCount();
    }

    protected function _executeHardDelete(?Arr $preResolvedIds = null): int
    {
        $filter = $this->_buildFilter($this->wheres);

        if ($filter === []) {
            throw new Exception('MongoDB delete requires where conditions.');
        }

        $this->pendingModifiedIds = $preResolvedIds ?? $this->_resolveModifiedIdsForDocumentMutation();

        $options = [];
        if ($this->limit !== null) {
            $options['limit'] = $this->limit;
        }

        $bulk = new BulkWrite(['ordered' => true]);
        $bulk->delete($filter, $options);

        $result = $this->connection->getManager()->executeBulkWrite(
            $this->connection->getNamespace($this->table),
            $bulk,
        );

        return $result->getDeletedCount();
    }
}
