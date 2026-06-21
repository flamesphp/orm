<?php
declare(strict_types=1);

namespace Flames\Orm\Database\QueryBuilder;

use Flames\Collection\Arr;
use Exception;
use Flames\Orm\Database\QueryBuilder\Support\ElasticsearchFilter;
use Flames\Orm\Exception\UnsupportedQueryException;

/**
 * @internal
 */
class Elasticsearch extends DefaultEx
{
    protected const DRIVER = 'elasticsearch';

    protected $client;

    public function __construct($connection)
    {
        parent::__construct($connection);
        $this->client = $connection->getClient();
    }

    protected function driverName(): string
    {
        return static::DRIVER;
    }

    public function group(string $key): static
    {
        throw new UnsupportedQueryException('group', $this->driverName());
    }

    protected function _pushWhere(WhereOperator $operator, array $where): static
    {
        match ($where['type']) {
            WhereType::Expression => throw new UnsupportedQueryException('whereExpression', $this->driverName()),
            WhereType::Column     => throw new UnsupportedQueryException('whereColumn', $this->driverName()),
            WhereType::Bitwise    => throw new UnsupportedQueryException('whereBitwise', $this->driverName()),
            WhereType::Strcmp     => throw new UnsupportedQueryException('whereStrcmp', $this->driverName()),
            WhereType::RegexpLike => throw new UnsupportedQueryException('whereRegexpLike', $this->driverName()),
            WhereType::JsonPath   => throw new UnsupportedQueryException('whereJsonExtract', $this->driverName()),
            WhereType::FullText   => throw new UnsupportedQueryException('whereFullText', $this->driverName()),
            WhereType::Operator   => null,
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
            throw new UnsupportedQueryException('whereRegexp', $this->driverName());
        }

        if (in_array($condition, ['LIKE_PATTERN', 'NOT_LIKE_PATTERN'], true)) {
            throw new UnsupportedQueryException('whereLikePattern', $this->driverName());
        }

        if (in_array($condition, ['IS TRUE', 'IS FALSE', 'IS UNKNOWN', 'IS NOT TRUE', 'IS NOT FALSE', 'IS NOT UNKNOWN'], true)) {
            throw new UnsupportedQueryException('whereIsTrue', $this->driverName());
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
        throw new UnsupportedQueryException('whereExpression', $this->driverName());
    }

    protected function _whereColumn(WhereOperator $operator, string $left, string $compareOperator, string $right): static
    {
        throw new UnsupportedQueryException('whereColumn', $this->driverName());
    }

    protected function _whereBitwise(
        WhereOperator $operator,
        string $key,
        string $bitOperator,
        mixed $operand,
        string $compareOperator,
        mixed $compareValue,
    ): static {
        throw new UnsupportedQueryException('whereBitwise', $this->driverName());
    }

    protected function _whereRegexp(WhereOperator $operator, string $key, mixed $pattern, string $condition): static
    {
        throw new UnsupportedQueryException('whereRegexp', $this->driverName());
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
                default                => ElasticsearchFilter::build([$where], $this->driverName()),
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
            $result = $this->_combineFilters(
                $result,
                $compiled[$index]['filter'],
                $compiled[$index]['operator'],
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    protected function _combineFilters(array $left, array $right, WhereOperator $operator): array
    {
        return match ($operator) {
            WhereOperator::And => ['bool' => ['filter' => [$left, $right]]],
            WhereOperator::Or  => [
                'bool' => [
                    'should'               => [$left, $right],
                    'minimum_should_match' => 1,
                ],
            ],
            WhereOperator::Xor => [
                'bool' => [
                    'should' => [
                        ['bool' => ['filter' => [$left]]],
                        ['bool' => ['filter' => [$right]]],
                    ],
                    'must_not' => [
                        ['bool' => ['filter' => [$left, $right]]],
                    ],
                    'minimum_should_match' => 1,
                ],
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function _buildFilterDelegate(array $where, bool $negated): array
    {
        $sub = $this->_newSubQuery();
        ($where['value'])($sub);
        $filter = $sub->_buildFilter($sub->wheres);

        if ($filter === []) {
            return [];
        }

        return $negated ? ['bool' => ['must_not' => [$filter]]] : $filter;
    }

    protected function _newSubQuery(): static
    {
        $sub = new static($this->connection);
        if ($this->mode === 'model') {
            $sub->setModel($this->model);
        } else {
            $sub->setTable($this->table);
        }

        $sub->_ensureSoftDeleteScope();

        return $sub;
    }

    /**
     * @return array<string, mixed>
     */
    protected function _buildSearchQuery(array $wheres): array
    {
        $filter = $this->_buildFilter($wheres);

        if ($filter === []) {
            return ['match_all' => new \stdClass()];
        }

        return $filter;
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

        return $this->_generateUuid();
    }

    protected function _fetchDocumentByPrimaryKey(object $pkColumn, mixed $pkValue): ?array
    {
        $storedValue = $this->modelCast::pre($pkColumn, $pkValue);
        $documentId  = rawurlencode((string) $storedValue);

        $response = $this->client->request('GET', $this->table . '/_doc/' . $documentId, ['http_errors' => false]);
        $status   = (int) $response->getStatusCode();

        if ($status === 404) {
            return null;
        }

        if ($status < 200 || $status >= 300) {
            throw new Exception('Failed to fetch document from ' . $this->driverName() . ' index ' . $this->table . '.');
        }

        $payload = json_decode($response->getBody()->getContents(), true);

        return is_array($payload['_source'] ?? null) ? $payload['_source'] : null;
    }

    protected function _resolveWherePrimaryKeyValue(): mixed
    {
        $pkColumn = $this->_findPrimaryColumn();
        if ($pkColumn === null) {
            throw new Exception(ucfirst($this->driverName()) . ' update requires a model primary key.');
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

        throw new Exception(ucfirst($this->driverName()) . ' update requires a primary key condition.');
    }

    protected function _indexDocument(string $documentId, array $document): void
    {
        $response = $this->client->request(
            'PUT',
            $this->table . '/_doc/' . rawurlencode($documentId),
            [
                'headers' => ['Content-Type' => 'application/json'],
                'query'   => ['refresh' => 'wait_for'],
                'body'    => json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ],
        );

        $status = (int) $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $body = (string) $response->getBody()->getContents();
            throw new Exception('Failed to write document in model class ' . $this->model . ' with ' . $this->driverName() . ' API: ' . $body);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function _buildSort(): array
    {
        $sort = [];

        foreach ($this->orders as $order) {
            $sort[] = [
                $order['key'] => [
                    'order' => strtolower($order['direction']) === 'desc' ? 'desc' : 'asc',
                ],
            ];
        }

        return $sort;
    }

    public function get(): Arr
    {
        $this->_ensureSoftDeleteScope();

        $payload = [
            'query' => $this->_buildSearchQuery($this->wheres),
            'size'  => $this->limit ?? 10000,
            'from'  => $this->offset ?? 0,
        ];

        $sort = $this->_buildSort();
        if ($sort !== []) {
            $payload['sort'] = $sort;
        }

        $response = $this->client->request(
            'POST',
            $this->table . '/_search',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => json_encode($payload, JSON_THROW_ON_ERROR),
            ],
        );

        $result = json_decode($response->getBody()->getContents(), true);
        $hits   = $result['hits']['hits'] ?? [];

        if ($this->mode !== 'model') {
            $rows = [];
            foreach ($hits as $hit) {
                $rows[] = is_array($hit['_source'] ?? null) ? $hit['_source'] : [];
            }

            return Arr($rows);
        }

        $cast    = $this->modelCast;
        $columns = $this->modelData->column;
        $class   = $this->modelData->class;
        $models  = Arr();

        foreach ($hits as $hit) {
            $source = is_array($hit['_source'] ?? null) ? $hit['_source'] : [];
            $modelData = [];

            foreach ($columns as $column) {
                if (array_key_exists($column->name, $source)) {
                    $modelData[$column->property] = $cast::pos($column, $source[$column->name], true);
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
            throw new Exception(ucfirst($this->driverName()) . ' update requires a primary key column.');
        }

        $pkValue  = $this->_resolveWherePrimaryKeyValue();
        $partial  = $this->_propertyDataToDocument($data);
        $existing = $this->_fetchDocumentByPrimaryKey($pkColumn, $pkValue);

        $document = $existing !== null ? array_merge($existing, $partial) : $partial;
        $document[$pkColumn->name] = $this->modelCast::pre($pkColumn, $pkValue);

        $targetIds = $this->_resolveModifiedIdsForDocumentMutation();

        $this->_indexDocument((string) $document[$pkColumn->name], $document);

        return $this->_finalizeUpdate(true, $targetIds);
    }

    public function insert(Arr|array $data): mixed
    {
        $data = (array) $data;

        if ($this->mode === 'model') {
            $data = $this->_prepareModelData($data);
            $data = $this->_stripNullIdentityColumns($data);
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

        $document = $this->_propertyDataToDocument($data);
        $documentId = (string) ($document[$pkColumn?->name ?? $primaryKeyProperty] ?? $this->_generateUuid());
        $this->_indexDocument($documentId, $document);

        if ($pkColumn === null) {
            return $this->_finalizeInsertResult($document);
        }

        return $this->_finalizeInsertResult([
            $primaryKeyProperty => $this->modelCast::pos(
                $pkColumn,
                $document[$pkColumn->name] ?? $data[$primaryKeyProperty],
                true,
            ),
        ]);
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
        $value    = $this->modelCast::pre($column, $this->_softDeleteTimestamp());

        $response = $this->client->request(
            'POST',
            $this->table . '/_update_by_query',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'query'   => ['refresh' => 'true'],
                'body'    => json_encode([
                    'query' => $this->_buildSearchQuery($this->wheres),
                    'script' => [
                        'source' => 'ctx._source.' . $column->name . ' = params.deletedAt',
                        'params' => ['deletedAt' => $value],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $payload = json_decode($response->getBody()->getContents(), true);

        return (int) ($payload['updated'] ?? 0);
    }

    protected function _executeHardDelete(?Arr $preResolvedIds = null): int
    {
        $this->pendingModifiedIds = $preResolvedIds ?? $this->_resolveModifiedIdsForDocumentMutation();

        $response = $this->client->request(
            'POST',
            $this->table . '/_delete_by_query',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'query'   => ['refresh' => 'true'],
                'body'    => json_encode([
                    'query' => $this->_buildSearchQuery($this->wheres),
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $payload = json_decode($response->getBody()->getContents(), true);

        return (int) ($payload['deleted'] ?? 0);
    }
}
