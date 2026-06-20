<?php
declare(strict_types=1);


namespace Flames\Orm\Database\QueryBuilder;

use Flames\Collection\Arr;
use Exception;
use Flames\Orm\Exception\UnsupportedQueryException;

/**
 * @internal
 */
class Meilisearch extends DefaultEx
{
    private const DRIVER = 'meilisearch';

    protected $client;

    public function __construct($connection)
    {
        parent::__construct($connection);
        $this->client = $connection->getClient();
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
            WhereType::JsonPath   => throw new UnsupportedQueryException('whereJsonExtract', self::DRIVER),
            WhereType::FullText   => throw new UnsupportedQueryException('whereFullText', self::DRIVER),
            default               => null,
        };

        return parent::_pushWhere($operator, $where);
    }

    protected function _pushSimpleWhere(WhereOperator $operator, string $key, string $condition, mixed $value): static
    {
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

        return parent::_pushSimpleWhere($operator, $key, $condition, $value);
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

    protected function _buildFilter(array $wheres): string
    {
        if ($wheres === []) {
            return '';
        }

        $compiled = [];

        foreach ($wheres as $where) {
            $condition = $this->_buildWhereCondition($where);
            if ($condition === '') {
                continue;
            }

            $compiled[] = [
                'condition' => $condition,
                'operator'  => $where['operator'],
            ];
        }

        if ($compiled === []) {
            return '';
        }

        $result = $compiled[0]['condition'];

        for ($i = 1, $count = count($compiled); $i < $count; $i++) {
            $result = $this->_combineFilterConditions(
                $result,
                $compiled[$i]['condition'],
                $compiled[$i]['operator'],
            );
        }

        return $result;
    }

    private function _combineFilterConditions(string $left, string $right, WhereOperator $operator): string
    {
        return match ($operator) {
            WhereOperator::And => '(' . $left . ' AND ' . $right . ')',
            WhereOperator::Or  => '(' . $left . ' OR ' . $right . ')',
            WhereOperator::Xor => '((' . $left . ' OR ' . $right . ') AND NOT (' . $left . ' AND ' . $right . '))',
        };
    }

    private function _buildWhereCondition(array $where): string
    {
        return match ($where['type']) {
            WhereType::Simple      => $this->_buildSimpleCondition($where),
            WhereType::Raw         => $where['condition'],
            WhereType::Delegate    => $this->_buildFilterDelegate($where, false),
            WhereType::NotDelegate => $this->_buildFilterDelegate($where, true),
            WhereType::Expression  => throw new UnsupportedQueryException('whereExpression', self::DRIVER),
            WhereType::Column      => throw new UnsupportedQueryException('whereColumn', self::DRIVER),
            WhereType::Bitwise     => throw new UnsupportedQueryException('whereBitwise', self::DRIVER),
            WhereType::Strcmp      => throw new UnsupportedQueryException('whereStrcmp', self::DRIVER),
            WhereType::RegexpLike  => throw new UnsupportedQueryException('whereRegexpLike', self::DRIVER),
            WhereType::JsonPath    => throw new UnsupportedQueryException('whereJsonExtract', self::DRIVER),
            WhereType::FullText    => throw new UnsupportedQueryException('whereFullText', self::DRIVER),
        };
    }

    private function _buildSimpleCondition(array $where): string
    {
        $key   = $where['key'];
        $value = $where['value'];

        return match ($where['condition']) {
            'IN', 'NOT IN' => $this->_buildFilterList($key, (array) $value, $where['condition'] === 'NOT IN'),
            'BETWEEN'      => $this->_buildFilterBetween($key, (array) $value),
            'NOT BETWEEN'  => '(NOT (' . $this->_buildFilterBetween($key, (array) $value) . '))',
            'IS NULL'      => $key . ' IS NULL',
            'IS NOT NULL'  => $key . ' IS NOT NULL',
            'LIKE'         => $key . ' CONTAINS ' . $this->_quoteFilterValue($value),
            'NOT LIKE'     => $key . ' NOT CONTAINS ' . $this->_quoteFilterValue($value),
            '<=>'          => $this->_buildSafeEqualCondition($key, $value),
            '=', '!=', '<>', '>', '<', '>=', '<=' => $this->_buildCompareCondition($key, $where['condition'], $value),
            default => throw new UnsupportedQueryException(
                'where("' . $key . '", "' . $where['condition'] . '", …)',
                self::DRIVER,
            ),
        };
    }

    private function _buildSafeEqualCondition(string $key, mixed $value): string
    {
        if ($value === null) {
            return $key . ' IS NULL';
        }

        return $this->_buildCompareCondition($key, '=', $value);
    }

    private function _buildCompareCondition(string $key, string $operator, mixed $value): string
    {
        if ($operator === '<>') {
            $operator = '!=';
        }

        if ($operator === '=' || $operator === '<=>') {
            if (is_array($value)) {
                return $this->_buildObjectEqualityFilter($key, $value);
            }
        }

        return $key . ' ' . $operator . ' ' . $this->_quoteFilterScalar($value);
    }

    /**
     * @param array<mixed> $value
     */
    private function _buildObjectEqualityFilter(string $key, array $value): string
    {
        if ($value === []) {
            return $key . ' IS EMPTY';
        }

        $parts = [];
        foreach ($value as $subKey => $subValue) {
            $path = $key . '.' . $subKey;
            if (is_array($subValue)) {
                $parts[] = $this->_buildObjectEqualityFilter($path, $subValue);
                continue;
            }

            $parts[] = $path . ' = ' . $this->_quoteFilterScalar($subValue);
        }

        return '(' . implode(' AND ', $parts) . ')';
    }

    private function _quoteFilterValue(mixed $value): string
    {
        return '"' . addslashes((string) $value) . '"';
    }

    private function _buildFilterDelegate(array $where, bool $negated): string
    {
        $sub = new static($this->connection);
        if ($this->mode === 'model') {
            $sub->setModel($this->model);
        } else {
            $sub->setTable($this->table);
        }

        ($where['value'])($sub);
        $filter = $sub->_buildFilter($sub->wheres);

        if ($filter === '') {
            return '';
        }

        return $negated ? '(NOT ' . $filter . ')' : '(' . $filter . ')';
    }

    private function _buildFilterList(string $key, array $values, bool $not): string
    {
        if ($values === []) {
            return $not ? '1=1' : '0=1';
        }

        $quoted = implode(', ', array_map(
            fn ($v): string => $this->_quoteFilterScalar($v),
            $values,
        ));

        return $key . ($not ? ' NOT IN [' : ' IN [') . $quoted . ']';
    }

    private function _buildFilterBetween(string $key, array $values): string
    {
        [$from, $to] = array_values($values);

        return $key . ' >= ' . $this->_quoteFilterScalar($from) . ' AND ' . $key . ' <= ' . $this->_quoteFilterScalar($to);
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

        if ($type === 'string' || in_array($type, ['char', 'varchar', 'text', 'longtext'], true)) {
            return $this->_generateUuid();
        }

        return $this->_generateUuid();
    }

    protected function _quoteFilterScalar(mixed $value): string
    {
        return match (true) {
            $value === null       => 'null',
            is_bool($value)       => $value ? 'true' : 'false',
            is_int($value),
            is_float($value)      => (string) $value,
            is_string($value)     => '"' . addslashes($value) . '"',
            default               => '"' . addslashes((string) $value) . '"',
        };
    }

    protected function _fetchDocumentByPrimaryKey(object $pkColumn, mixed $pkValue): ?array
    {
        $storedValue = $this->modelCast::pre($pkColumn, $pkValue);
        $filter      = $pkColumn->name . ' = ' . $this->_quoteFilterScalar($storedValue);

        $response = $this->client->request(
            'POST',
            'indexes/' . $this->table . '/search',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => json_encode([
                    'q'      => '',
                    'filter' => $filter,
                    'limit'  => 1,
                ]),
            ],
        );

        $result = json_decode($response->getBody()->getContents(), true);
        $hits   = $result['hits'] ?? [];

        return $hits[0] ?? null;
    }

    protected function _resolveWherePrimaryKeyValue(): mixed
    {
        $pkColumn = $this->_findPrimaryColumn();
        if ($pkColumn === null) {
            throw new Exception('Meilisearch update requires a model primary key.');
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

        throw new Exception('Meilisearch update requires a primary key condition.');
    }

    protected function _postDocuments(array $documents): void
    {
        if ($documents === []) {
            throw new Exception("Document payload in table {$this->table} can't be empty.");
        }

        $response = $this->client->request(
            'POST',
            'indexes/' . $this->table . '/documents',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => json_encode($documents, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ],
        );

        $taskUid = (int) json_decode($response->getBody()->getContents())->taskUid;
        if ($taskUid === 0) {
            throw new Exception('Failed to write documents in model class ' . $this->model . ' with Meilisearch API.');
        }

        $this->_waitForTask($taskUid, 'write documents in model class ' . $this->model);
    }

    protected function _buildSort(): array
    {
        return array_map(
            fn($o) => $o['key'] . ':' . $o['direction'],
            $this->orders
        );
    }

    public function get(): Arr
    {
        $payload = ['q' => ''];

        $filter = $this->_buildFilter($this->wheres);
        if ($filter !== '') {
            $payload['filter'] = $filter;
        }

        $sort = $this->_buildSort();
        if (!empty($sort)) {
            $payload['sort'] = $sort;
        }

        $payload['limit']  = $this->limit ?? PHP_INT_MAX;
        $payload['offset'] = $this->offset ?? 0;

        $response = $this->client->request(
            'POST',
            'indexes/' . $this->table . '/search',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => json_encode($payload),
            ]
        );

        $result = json_decode($response->getBody()->getContents(), true);
        $hits   = $result['hits'] ?? [];

        if ($this->mode === 'model') {
            $cast    = $this->modelCast;
            $columns = $this->modelData->column;
            $class   = $this->modelData->class;
            $models  = Arr();
            foreach ($hits as $hit) {
                $modelData = [];
                foreach ($columns as $column) {
                    if (array_key_exists($column->name, $hit)) {
                        $modelData[$column->property] = $cast::pos($column, $hit[$column->name], true);
                    }
                }
                $models[] = new $class($modelData, true);
            }
            return $models;
        }

        return Arr($hits);
    }

    public function update(Arr|array $data): bool
    {
        $data = (array) $data;

        if ($this->mode === 'model') {
            $data = $this->_prepareModelData($data);
        }

        if ($data === []) {
            throw new Exception("Update payload in table {$this->table} can't be empty.");
        }

        $pkColumn = $this->_findPrimaryColumn();
        if ($pkColumn === null) {
            throw new Exception('Meilisearch update requires a primary key column.');
        }

        $pkValue  = $this->_resolveWherePrimaryKeyValue();
        $partial  = $this->_propertyDataToDocument($data);
        $existing = $this->_fetchDocumentByPrimaryKey($pkColumn, $pkValue);

        $document = $existing !== null ? array_merge($existing, $partial) : $partial;
        $document[$pkColumn->name] = $this->modelCast::pre($pkColumn, $pkValue);

        $this->_postDocuments([$document]);

        return true;
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
        $this->_postDocuments([$document]);

        if ($pkColumn === null) {
            return $document;
        }

        return [
            $primaryKeyProperty => $this->modelCast::pos(
                $pkColumn,
                $document[$pkColumn->name] ?? $data[$primaryKeyProperty],
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

    protected function _waitForTask(int $taskUid, string $context): void
    {
        do {
            usleep(2000);
            $response    = $this->client->request('GET', 'tasks/' . $taskUid, ['headers' => ['Content-Type' => 'application/json']]);
            $requestData = json_decode($response->getBody()->getContents());
            $status      = $requestData->status;
        } while ($status === 'processing' || $status === 'enqueued');

        if ($status !== 'succeeded') {
            $msg = isset($requestData->error->message) ? ' ' . $requestData->error->message : '';
            throw new Exception('Failed to ' . $context . ' with Meilisearch API.' . $msg);
        }
    }

    protected function _generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        $hex     = bin2hex($data);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex,  0, 8),
            substr($hex,  8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
