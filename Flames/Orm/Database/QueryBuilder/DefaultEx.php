<?php
declare(strict_types=1);


namespace Flames\Orm\Database\QueryBuilder;

use Flames\Collection\Arr;
use Exception;

/**
 * @internal
 */
abstract class DefaultEx
{
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

    public function whereGroup(callable $delegate, Arr|array|null $values = null): static
    {
        $this->wheres[] = ['type' => WhereType::Delegate, 'value' => $delegate, 'operator' => WhereOperator::And];
        return $this;
    }

    public function orWhereGroup(callable $delegate, Arr|array|null $values = null): static
    {
        $this->wheres[] = ['type' => WhereType::Delegate, 'value' => $delegate, 'operator' => WhereOperator::Or];
        return $this;
    }

    protected function _where(WhereOperator $operator, string $key, string $condition, mixed $value): static
    {
        if ($this->mode === 'model') {
            if (!isset($this->modelData->column[$key])) {
                throw new Exception('Model key ' . $key . ' not found in class ' . $this->modelData->class);
            }
            $this->wheres[] = [
                'type'      => WhereType::Simple,
                'key'       => $this->modelData->column[$key]->name,
                'condition' => $condition,
                'value'     => $value,
                'operator'  => $operator,
            ];
        } else {
            $this->wheres[] = [
                'type'      => WhereType::Simple,
                'key'       => $key,
                'condition' => $condition,
                'value'     => $value,
                'operator'  => $operator,
            ];
        }
        return $this;
    }

    public function whereLike(string $key, mixed $value = null): static
    {
        return $this->_whereLike(WhereOperator::And, $key, $value);
    }

    public function orWhereLike(string $key, mixed $value = null): static
    {
        return $this->_whereLike(WhereOperator::Or, $key, $value);
    }

    protected function _whereLike(WhereOperator $operator, string $key, mixed $value = null): static
    {
        if ($this->mode === 'model') {
            if (!isset($this->modelData->column[$key])) {
                throw new Exception('Model key ' . $key . ' not found in class ' . $this->modelData->class);
            }
            $this->wheres[] = [
                'type'      => WhereType::Simple,
                'key'       => $this->modelData->column[$key]->name,
                'condition' => 'LIKE',
                'value'     => $value,
                'operator'  => $operator,
            ];
        } else {
            $this->wheres[] = [
                'type'      => WhereType::Simple,
                'key'       => $key,
                'condition' => 'LIKE',
                'value'     => $value,
                'operator'  => $operator,
            ];
        }
        return $this;
    }

    public function whereIn(string $key, mixed $value = null): static
    {
        return $this->_whereIn(WhereOperator::And, $key, $value);
    }

    public function orWhereIn(string $key, mixed $value = null): static
    {
        return $this->_whereIn(WhereOperator::Or, $key, $value);
    }

    protected function _whereIn(WhereOperator $operator, string $key, mixed $value = null): static
    {
        if ($this->mode === 'model') {
            if (!isset($this->modelData->column[$key])) {
                throw new Exception('Model key ' . $key . ' not found in class ' . $this->modelData->class);
            }
            if (empty($value)) {
                throw new Exception('WhereIn for model key ' . $key . ' in class ' . $this->modelData->class . " can't be empty.");
            }
            $this->wheres[] = [
                'type'      => WhereType::Simple,
                'key'       => $this->modelData->column[$key]->name,
                'condition' => 'IN',
                'value'     => $value,
                'operator'  => $operator,
            ];
        } else {
            $this->wheres[] = [
                'type'      => WhereType::Simple,
                'key'       => $key,
                'condition' => 'IN',
                'value'     => $value,
                'operator'  => $operator,
            ];
        }
        return $this;
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

    public function get(): Arr { return Arr(); }

    public function update(Arr|array $data): bool { return false; }

    public function insert(Arr|array $data): mixed { return null; }
}
