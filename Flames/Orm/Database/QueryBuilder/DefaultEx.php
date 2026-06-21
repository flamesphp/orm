<?php
declare(strict_types=1);


namespace Flames\Orm\Database\QueryBuilder;

use Flames\Collection\Arr;
use Flames\Orm\Database\QueryBuilder\Support\ExtendedOperators;
use Flames\Orm\Database\QueryBuilder\Support\WhereOperators;
use Exception;

/**
 * @internal
 */
abstract class DefaultEx
{
    use WhereOperators;
    use ExtendedOperators;
    protected string $mode           = 'table';
    protected mixed  $connection;

    protected ?string $model     = null;
    protected mixed   $modelData = null;
    protected ?string $modelCast = null;
    protected ?string $table     = null;

    protected array  $wheres         = [];
    protected string $whereBaseIndex = '';
    protected array  $orders         = [];
    protected array  $groups         = [];
    protected ?int   $limit          = null;
    protected ?int   $offset         = null;

    public function __construct(mixed $connection)
    {
        $this->connection = $connection;
    }

    public function setTable(string $table): static
    {
        $this->mode  = 'table';
        $this->table = $table;
        $this->model = null;
        return $this;
    }

    public function setModel(string $model): static
    {
        $this->mode      = 'model';
        $this->model     = $model;
        $this->modelData = $model::getMetadata();
        $this->modelCast = \Flames\Orm\Database\Cast\Factory::getByDatabaseType(
            \Flames\Orm\Database\DataFactory::getConfigByDatabase($this->modelData->database)->type
        );
        $this->table = $this->modelData->table;
        return $this;
    }

    protected function _setBaseIndex(string $whereBaseIndex): void
    {
        $this->whereBaseIndex = $whereBaseIndex;
    }

    public function where(string $key, mixed $valueOrCondition = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            return $this->_where(WhereOperator::And, $key, '=', $valueOrCondition);
        }
        return $this->_where(WhereOperator::And, $key, $valueOrCondition, $value);
    }

    public function orWhere(string $key, mixed $valueOrCondition = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            return $this->_where(WhereOperator::Or, $key, '=', $valueOrCondition);
        }
        return $this->_where(WhereOperator::Or, $key, $valueOrCondition, $value);
    }

    public function whereNot(string $key, mixed $value): static
    {
        return $this->_where(WhereOperator::And, $key, '!=', $value);
    }

    public function orWhereNot(string $key, mixed $value): static
    {
        return $this->_where(WhereOperator::Or, $key, '!=', $value);
    }

    protected function _pushWhere(WhereOperator $operator, array $where): static
    {
        $where['operator'] = $operator;
        $this->wheres[]    = $where;

        return $this;
    }

    public function xorWhere(string $key, mixed $valueOrCondition = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            return $this->_where(WhereOperator::Xor, $key, '=', $valueOrCondition);
        }

        return $this->_where(WhereOperator::Xor, $key, $valueOrCondition, $value);
    }

    public function xorWhereNot(string $key, mixed $value): static
    {
        return $this->_where(WhereOperator::Xor, $key, '!=', $value);
    }

    public function whereNotGroup(callable $delegate): static
    {
        return $this->_pushWhere(WhereOperator::And, [
            'type'  => WhereType::NotDelegate,
            'value' => $delegate,
        ]);
    }

    public function orWhereNotGroup(callable $delegate): static
    {
        return $this->_pushWhere(WhereOperator::Or, [
            'type'  => WhereType::NotDelegate,
            'value' => $delegate,
        ]);
    }

    public function xorWhereNotGroup(callable $delegate): static
    {
        return $this->_pushWhere(WhereOperator::Xor, [
            'type'  => WhereType::NotDelegate,
            'value' => $delegate,
        ]);
    }

    public function whereSafeEqual(string $key, mixed $value): static
    {
        return $this->_where(WhereOperator::And, $key, '<=>', $value);
    }

    public function orWhereSafeEqual(string $key, mixed $value): static
    {
        return $this->_where(WhereOperator::Or, $key, '<=>', $value);
    }

    public function xorWhereSafeEqual(string $key, mixed $value): static
    {
        return $this->_where(WhereOperator::Xor, $key, '<=>', $value);
    }

    public function whereEquals(string $key, mixed $value): static
    {
        return $this->where($key, $value);
    }

    public function orWhereEquals(string $key, mixed $value): static
    {
        return $this->orWhere($key, $value);
    }

    public function xorWhereEquals(string $key, mixed $value): static
    {
        return $this->xorWhere($key, $value);
    }

    public function whereBigger(string $key, mixed $value): static
    {
        return $this->where($key, '>', $value);
    }

    public function orWhereBigger(string $key, mixed $value): static
    {
        return $this->orWhere($key, '>', $value);
    }

    public function xorWhereBigger(string $key, mixed $value): static
    {
        return $this->xorWhere($key, '>', $value);
    }

    public function whereLess(string $key, mixed $value): static
    {
        return $this->where($key, '<', $value);
    }

    public function orWhereLess(string $key, mixed $value): static
    {
        return $this->orWhere($key, '<', $value);
    }

    public function xorWhereLess(string $key, mixed $value): static
    {
        return $this->xorWhere($key, '<', $value);
    }

    public function whereBiggerOrEquals(string $key, mixed $value): static
    {
        return $this->where($key, '>=', $value);
    }

    public function orWhereBiggerOrEquals(string $key, mixed $value): static
    {
        return $this->orWhere($key, '>=', $value);
    }

    public function xorWhereBiggerOrEquals(string $key, mixed $value): static
    {
        return $this->xorWhere($key, '>=', $value);
    }

    public function whereLessOrEquals(string $key, mixed $value): static
    {
        return $this->where($key, '<=', $value);
    }

    public function orWhereLessOrEquals(string $key, mixed $value): static
    {
        return $this->orWhere($key, '<=', $value);
    }

    public function xorWhereLessOrEquals(string $key, mixed $value): static
    {
        return $this->xorWhere($key, '<=', $value);
    }

    public function whereExpression(
        string $expression,
        string $operator,
        mixed $value,
        Arr|array $bindings = [],
    ): static {
        return $this->_whereExpression(WhereOperator::And, $expression, $operator, $value, $bindings);
    }

    public function orWhereExpression(
        string $expression,
        string $operator,
        mixed $value,
        Arr|array $bindings = [],
    ): static {
        return $this->_whereExpression(WhereOperator::Or, $expression, $operator, $value, $bindings);
    }

    public function xorWhereExpression(
        string $expression,
        string $operator,
        mixed $value,
        Arr|array $bindings = [],
    ): static {
        return $this->_whereExpression(WhereOperator::Xor, $expression, $operator, $value, $bindings);
    }

    protected function _whereExpression(
        WhereOperator $operator,
        string $expression,
        string $compareOperator,
        mixed $value,
        Arr|array $bindings,
    ): static {
        if ($bindings instanceof Arr) {
            $bindings = $bindings->toArray();
        }

        return $this->_pushWhere($operator, [
            'type'       => WhereType::Expression,
            'expression' => $expression,
            'condition'  => $compareOperator,
            'value'      => $value,
            'bindings'   => $bindings,
        ]);
    }

    public function whereColumn(string $left, string $operator, string $right): static
    {
        return $this->_whereColumn(WhereOperator::And, $left, $operator, $right);
    }

    public function orWhereColumn(string $left, string $operator, string $right): static
    {
        return $this->_whereColumn(WhereOperator::Or, $left, $operator, $right);
    }

    public function xorWhereColumn(string $left, string $operator, string $right): static
    {
        return $this->_whereColumn(WhereOperator::Xor, $left, $operator, $right);
    }

    protected function _whereColumn(WhereOperator $operator, string $left, string $compareOperator, string $right): static
    {
        return $this->_pushWhere($operator, [
            'type'      => WhereType::Column,
            'left'      => $this->_resolveWhereKey($left),
            'condition' => $compareOperator,
            'right'     => $this->_resolveWhereKey($right),
        ]);
    }

    public function whereBitwise(
        string $key,
        string $bitOperator,
        mixed $operand,
        string $compareOperator = '=',
        mixed $compareValue = null,
    ): static {
        return $this->_whereBitwise(WhereOperator::And, $key, $bitOperator, $operand, $compareOperator, $compareValue);
    }

    public function orWhereBitwise(
        string $key,
        string $bitOperator,
        mixed $operand,
        string $compareOperator = '=',
        mixed $compareValue = null,
    ): static {
        return $this->_whereBitwise(WhereOperator::Or, $key, $bitOperator, $operand, $compareOperator, $compareValue);
    }

    public function xorWhereBitwise(
        string $key,
        string $bitOperator,
        mixed $operand,
        string $compareOperator = '=',
        mixed $compareValue = null,
    ): static {
        return $this->_whereBitwise(WhereOperator::Xor, $key, $bitOperator, $operand, $compareOperator, $compareValue);
    }

    protected function _whereBitwise(
        WhereOperator $operator,
        string $key,
        string $bitOperator,
        mixed $operand,
        string $compareOperator,
        mixed $compareValue,
    ): static {
        $bitOperator = strtoupper(trim($bitOperator));
        if ($bitOperator === '~') {
            return $this->_pushWhere($operator, [
                'type'          => WhereType::Bitwise,
                'key'           => $this->_resolveWhereKey($key),
                'bitOperator'   => '~',
                'operand'       => 0,
                'condition'     => $compareOperator,
                'value'         => $compareValue ?? 0,
                'unary'         => true,
            ]);
        }

        if (in_array($bitOperator, ['&', '|', '^', '<<', '>>'], true) === false) {
            throw new Exception('Invalid bitwise operator: ' . $bitOperator);
        }

        if ($compareValue === null) {
            $compareValue = $operand;
        }

        return $this->_pushWhere($operator, [
            'type'          => WhereType::Bitwise,
            'key'           => $this->_resolveWhereKey($key),
            'bitOperator'   => $bitOperator,
            'operand'       => $operand,
            'condition'     => $compareOperator,
            'value'         => $compareValue,
        ]);
    }

    public function xorWhereRaw(string $condition, mixed $values = null): static
    {
        return $this->_whereRaw(WhereOperator::Xor, $condition, $values);
    }

    protected function _resolveExpression(string $expression): string
    {
        return preg_replace_callback(
            '/\{(\w+)\}/',
            function (array $matches): string {
                $key = $matches[1];

                if ($this->mode === 'model' && isset($this->modelData->column[$key])) {
                    return '`' . $this->table . '`.`' . $this->_resolveWhereKey($key) . '`';
                }

                return '{' . $key . '}';
            },
            $expression,
        ) ?? $expression;
    }

    protected function _qualifiedColumn(string $columnName): string
    {
        return '`' . $this->table . '`.`' . $columnName . '`';
    }

    public function whereGroup(callable $delegate, Arr|array|null $values = null): static
    {
        return $this->_pushWhere(WhereOperator::And, [
            'type'  => WhereType::Delegate,
            'value' => $delegate,
        ]);
    }

    public function orWhereGroup(callable $delegate, Arr|array|null $values = null): static
    {
        return $this->_pushWhere(WhereOperator::Or, [
            'type'  => WhereType::Delegate,
            'value' => $delegate,
        ]);
    }

    public function xorWhereGroup(callable $delegate, Arr|array|null $values = null): static
    {
        return $this->_pushWhere(WhereOperator::Xor, [
            'type'  => WhereType::Delegate,
            'value' => $delegate,
        ]);
    }

    protected function _resolveWhereKey(string $key): string
    {
        if ($this->mode === 'model') {
            if (!isset($this->modelData->column[$key])) {
                throw new Exception('Model key ' . $key . ' not found in class ' . $this->modelData->class);
            }

            return $this->modelData->column[$key]->name;
        }

        return $key;
    }

    protected function _pushSimpleWhere(
        WhereOperator $operator,
        string $key,
        string $condition,
        mixed $value,
        array $options = [],
    ): static {
        $where = [
            'type'      => WhereType::Simple,
            'key'       => $this->_resolveWhereKey($key),
            'condition' => $condition,
            'value'     => $value,
            'operator'  => $operator,
        ];

        if ($options !== []) {
            $where['options'] = $options;
        }

        $this->wheres[] = $where;

        return $this;
    }

    /**
     * @return list<mixed>
     */
    protected function _normalizeList(mixed $value, string $method, string $key): array
    {
        if ($value instanceof Arr) {
            $value = $value->toArray();
        }

        if (!is_array($value)) {
            throw new Exception($method . ' for key ' . $key . ' expects an array.');
        }

        return array_values($value);
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    protected function _normalizeBetween(mixed $value, string $method, string $key): array
    {
        if ($value instanceof Arr) {
            $value = $value->toArray();
        }

        if (!is_array($value) || count($value) !== 2) {
            throw new Exception($method . ' for key ' . $key . ' expects exactly two values.');
        }

        return array_values($value);
    }

    protected function _where(WhereOperator $operator, string $key, string $condition, mixed $value): static
    {
        return $this->_pushSimpleWhere($operator, $key, $condition, $value);
    }

    public function whereLike(string $key, mixed $value = null): static
    {
        return $this->_whereLike(WhereOperator::And, $key, $value);
    }

    public function orWhereLike(string $key, mixed $value = null): static
    {
        return $this->_whereLike(WhereOperator::Or, $key, $value);
    }

    protected function _whereLike(WhereOperator $operator, string $key, mixed $value = null, string $condition = 'LIKE'): static
    {
        return $this->_pushSimpleWhere($operator, $key, $condition, $value);
    }

    public function whereLikePattern(string $key, mixed $pattern): static
    {
        return $this->_whereLike(WhereOperator::And, $key, $pattern, 'LIKE_PATTERN');
    }

    public function orWhereLikePattern(string $key, mixed $pattern): static
    {
        return $this->_whereLike(WhereOperator::Or, $key, $pattern, 'LIKE_PATTERN');
    }

    public function whereNotLike(string $key, mixed $value = null): static
    {
        return $this->_whereLike(WhereOperator::And, $key, $value, 'NOT LIKE');
    }

    public function orWhereNotLike(string $key, mixed $value = null): static
    {
        return $this->_whereLike(WhereOperator::Or, $key, $value, 'NOT LIKE');
    }

    public function whereNotLikePattern(string $key, mixed $pattern): static
    {
        return $this->_whereLike(WhereOperator::And, $key, $pattern, 'NOT_LIKE_PATTERN');
    }

    public function orWhereNotLikePattern(string $key, mixed $pattern): static
    {
        return $this->_whereLike(WhereOperator::Or, $key, $pattern, 'NOT_LIKE_PATTERN');
    }

    public function whereRegexp(string $key, mixed $pattern): static
    {
        return $this->_whereRegexp(WhereOperator::And, $key, $pattern, 'REGEXP');
    }

    public function orWhereRegexp(string $key, mixed $pattern): static
    {
        return $this->_whereRegexp(WhereOperator::Or, $key, $pattern, 'REGEXP');
    }

    public function whereNotRegexp(string $key, mixed $pattern): static
    {
        return $this->_whereRegexp(WhereOperator::And, $key, $pattern, 'NOT REGEXP');
    }

    public function orWhereNotRegexp(string $key, mixed $pattern): static
    {
        return $this->_whereRegexp(WhereOperator::Or, $key, $pattern, 'NOT REGEXP');
    }

    protected function _whereRegexp(WhereOperator $operator, string $key, mixed $pattern, string $condition): static
    {
        return $this->_pushSimpleWhere($operator, $key, $condition, $pattern);
    }

    public function whereIn(string $key, mixed $value = null): static
    {
        return $this->_whereIn(WhereOperator::And, $key, $value);
    }

    public function orWhereIn(string $key, mixed $value = null): static
    {
        return $this->_whereIn(WhereOperator::Or, $key, $value);
    }

    protected function _whereIn(WhereOperator $operator, string $key, mixed $value = null, string $condition = 'IN'): static
    {
        $values = $this->_normalizeList($value, $condition === 'IN' ? 'whereIn' : 'whereNotIn', $key);

        if ($condition === 'IN' && $this->mode === 'model' && $values === []) {
            throw new Exception('whereIn for model key ' . $key . ' in class ' . $this->modelData->class . " can't be empty.");
        }

        return $this->_pushSimpleWhere($operator, $key, $condition, $values);
    }

    public function whereNotIn(string $key, mixed $value = null): static
    {
        return $this->_whereIn(WhereOperator::And, $key, $value, 'NOT IN');
    }

    public function orWhereNotIn(string $key, mixed $value = null): static
    {
        return $this->_whereIn(WhereOperator::Or, $key, $value, 'NOT IN');
    }

    public function whereBetween(string $key, mixed $fromOrRange, mixed $to = null): static
    {
        return $this->_whereBetween(WhereOperator::And, $key, $to === null ? $fromOrRange : [$fromOrRange, $to]);
    }

    public function orWhereBetween(string $key, mixed $fromOrRange, mixed $to = null): static
    {
        return $this->_whereBetween(WhereOperator::Or, $key, $to === null ? $fromOrRange : [$fromOrRange, $to]);
    }

    protected function _whereBetween(WhereOperator $operator, string $key, mixed $value, bool $not = false): static
    {
        return $this->_pushSimpleWhere(
            $operator,
            $key,
            $not ? 'NOT BETWEEN' : 'BETWEEN',
            $this->_normalizeBetween($value, $not ? 'whereNotBetween' : 'whereBetween', $key),
        );
    }

    public function whereNull(string $key): static
    {
        return $this->_whereNull(WhereOperator::And, $key, false);
    }

    public function orWhereNull(string $key): static
    {
        return $this->_whereNull(WhereOperator::Or, $key, false);
    }

    public function whereNotNull(string $key): static
    {
        return $this->_whereNull(WhereOperator::And, $key, true);
    }

    public function orWhereNotNull(string $key): static
    {
        return $this->_whereNull(WhereOperator::Or, $key, true);
    }

    protected function _whereNull(WhereOperator $operator, string $key, bool $not): static
    {
        return $this->_pushSimpleWhere($operator, $key, $not ? 'IS NOT NULL' : 'IS NULL', null);
    }

    public function whereRaw(string $condition, mixed $values = null): static
    {
        return $this->_whereRaw(WhereOperator::And, $condition, $values);
    }

    public function orWhereRaw(string $condition, mixed $values = null): static
    {
        return $this->_whereRaw(WhereOperator::Or, $condition, $values);
    }

    protected function _whereRaw(WhereOperator $operator, string $condition, mixed $values = null): static
    {
        $this->wheres[] = [
            'type'      => WhereType::Raw,
            'condition' => $condition,
            'value'     => $values,
            'operator'  => $operator,
        ];
        return $this;
    }

    public function order(string $key, string $direction = 'ASC'): static
    {
        $direction = strtolower($direction);
        if ($this->mode === 'model') {
            if (!isset($this->modelData->column[$key])) {
                throw new Exception('Model key ' . $key . ' not found in class ' . $this->modelData->class);
            }
            $this->orders[] = ['key' => $this->modelData->column[$key]->name, 'direction' => $direction];
        } else {
            $this->orders[] = ['key' => $key, 'direction' => $direction];
        }
        return $this;
    }

    public function group(string $key): static
    {
        if ($this->mode === 'model') {
            if (!isset($this->modelData->column[$key])) {
                throw new Exception('Model key ' . $key . ' not found in class ' . $this->modelData->class);
            }
            $this->groups[] = ['key' => $this->modelData->column[$key]->name];
        } else {
            $this->groups[] = ['key' => $key];
        }
        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset = $offset;
        return $this;
    }

    public function paginate(int $limit, int $page): static
    {
        $this->limit  = $limit;
        $this->offset = $page > 1 ? ($page * $limit) - $limit : null;
        return $this;
    }

    protected function _castDataPos(array $data, bool $fromDb = false): array
    {
        $cast    = $this->modelCast;
        $columns = $this->modelData->column;
        foreach ($data as $key => $value) {
            if (!isset($columns[$key])) {
                throw new Exception('Model key ' . $key . ' not found in class ' . $this->modelData->class);
            }
            $data[$key] = $cast::pos($columns[$key], $value, $fromDb);
        }
        return $data;
    }

    protected function _castDataPre(array $data): array
    {
        $cast    = $this->modelCast;
        $columns = $this->modelData->column;
        foreach ($data as $key => $value) {
            if (!isset($columns[$key])) {
                throw new Exception('Model key ' . $key . ' not found in class ' . $this->modelData->class);
            }
            $data[$key] = $cast::pre($columns[$key], $value);
        }
        return $data;
    }

    protected function _primaryKeyColumn(): ?object
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

    protected function _usesUuidPrimaryKey(): bool
    {
        $column = $this->_primaryKeyColumn();

        return $column !== null
            && \Flames\Orm\Database\Type\Kinds::isUuidColumn($column);
    }

    protected function _ensureGeneratedPrimaryKeys(array $data): array
    {
        if ($this->mode !== 'model') {
            return $data;
        }

        foreach ($this->modelData->column as $column) {
            if ($column->primary !== true || $column->autoIncrement !== true) {
                continue;
            }

            if (\Flames\Orm\Database\Type\Kinds::isUuidColumn($column) === false) {
                continue;
            }

            if (array_key_exists($column->property, $data)) {
                continue;
            }

            $data[$column->property] = $this->modelCast::pre(
                $column,
                (string) \Flames\Collection\Uuid::v4(),
            );
        }

        return $data;
    }

    protected function _resolveInsertIdentity(array $data, mixed $driverIdentity): mixed
    {
        if ($this->_usesUuidPrimaryKey()) {
            $column = $this->_primaryKeyColumn();
            if ($column === null) {
                return $driverIdentity;
            }

            return $data[$column->property] ?? $driverIdentity;
        }

        return $driverIdentity;
    }

    private const UUID_INSERT_MAX_ATTEMPTS = 5;

    /**
     * Retries model inserts when an auto-generated UUID primary key collides.
     *
     * @param callable(array): mixed $attemptInsert
     */
    protected function _insertWithUuidCollisionRetry(array $data, callable $attemptInsert): mixed
    {
        if ($this->mode !== 'model') {
            throw new Exception('UUID collision retry requires model insert mode.');
        }

        if ($this->_usesUuidPrimaryKey() === false) {
            return $attemptInsert($this->_prepareInsertPayload($data));
        }

        $pkColumn   = $this->_primaryKeyColumn();
        $pkProperty = $pkColumn?->property;

        if ($pkProperty === null) {
            return $attemptInsert($this->_prepareInsertPayload($data));
        }

        if ($this->_insertPayloadHasExplicitPrimaryKey($data, $pkProperty)) {
            return $attemptInsert($this->_prepareInsertPayload($data));
        }

        $lastException = null;

        for ($attempt = 1; $attempt <= self::UUID_INSERT_MAX_ATTEMPTS; $attempt++) {
            $payload = $this->_stripNullIdentityColumns($data);
            unset($payload[$pkProperty]);
            $payload = $this->_ensureGeneratedPrimaryKeys($payload);

            if ($payload === []) {
                throw new Exception("Insert payload in table {$this->table} can't be empty.");
            }

            try {
                return $attemptInsert($payload);
            } catch (\PDOException $exception) {
                $lastException = $exception;

                if (
                    $attempt >= self::UUID_INSERT_MAX_ATTEMPTS
                    || $this->_isUuidPrimaryKeyCollision(
                        $exception,
                        $pkColumn,
                        $payload[$pkProperty] ?? null,
                    ) === false
                ) {
                    throw $exception;
                }
            }
        }

        throw $lastException ?? new Exception(
            'Insert in table ' . $this->table . ' failed after UUID primary-key collision retries.',
        );
    }

    protected function _prepareInsertPayload(array $data): array
    {
        $payload = $this->_stripNullIdentityColumns($data);

        return $this->_ensureGeneratedPrimaryKeys($payload);
    }

    protected function _insertPayloadHasExplicitPrimaryKey(array $data, string $pkProperty): bool
    {
        if (array_key_exists($pkProperty, $data) === false) {
            return false;
        }

        $value = $data[$pkProperty];

        return $value !== null && $value !== '' && $value !== 0 && $value !== '0';
    }

    protected function _isUuidPrimaryKeyCollision(
        \PDOException $exception,
        object $pkColumn,
        mixed $attemptedPrimaryKey,
    ): bool {
        if ($this->_usesUuidPrimaryKey() === false) {
            return false;
        }

        $sqlState   = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message    = $exception->getMessage();

        $isUniqueViolation = $sqlState === '23505'
            || ($sqlState === '23000' && in_array($driverCode, [1062, 19, 2627, 2601], true));

        if ($isUniqueViolation === false) {
            return false;
        }

        if ($this->_errorTargetsPrimaryKey($message, (string) ($pkColumn->name ?? 'id'), $sqlState, $driverCode) === false) {
            return false;
        }

        $attempted = strtolower(trim((string) $attemptedPrimaryKey));

        if ($attempted === '') {
            return false;
        }

        return str_contains(strtolower($message), $attempted);
    }

    protected function _errorTargetsPrimaryKey(
        string $message,
        string $pkColumnName,
        string $sqlState,
        int $driverCode,
    ): bool {
        $lower   = strtolower($message);
        $pkLower = strtolower($pkColumnName);

        if ($sqlState === '23505') {
            if (str_contains($lower, '_pkey') || str_contains($lower, 'primary key')) {
                return true;
            }

            return str_contains($lower, 'key (' . $pkLower . ')=')
                || str_contains($lower, 'key ("' . $pkLower . '")=');
        }

        if ($driverCode === 1062) {
            return str_contains($lower, "for key 'primary'")
                || str_contains($lower, 'for key `primary`');
        }

        if (in_array($driverCode, [2627, 2601], true)) {
            return str_contains($lower, 'primary key') || str_contains($lower, 'pk_');
        }

        if ($driverCode === 19) {
            return str_contains($lower, 'primary key')
                || str_contains($lower, 'unique constraint failed: ' . $pkLower);
        }

        return false;
    }

    protected function _stripNullIdentityColumns(array $data): array
    {
        return $data;
    }

    public function get(): Arr { return Arr(); }

    public function update(Arr|array $data): bool { return false; }

    public function insert(Arr|array $data): mixed { return null; }
}
