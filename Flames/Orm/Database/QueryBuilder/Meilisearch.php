<?php
declare(strict_types=1);


namespace Flames\Orm\Database\QueryBuilder;

use Flames\Collection\Arr;
use Exception;

/**
 * @internal
 */
class Meilisearch extends DefaultEx
{
    protected $client;

    public function __construct($connection)
    {
        parent::__construct($connection);
        $this->client = $connection->getClient();
    }

    protected function _buildFilter(array $wheres): string
    {
        if (empty($wheres)) {
            return '';
        }

        $parts = [];

        foreach ($wheres as $where) {
            $condition = match ($where['type']) {
                WhereType::Simple => (function () use ($where): string {
                    $key   = $where['key'];
                    $value = $where['value'];

                    return match ($where['condition']) {
                        'IN'   => $key . ' IN [' . implode(', ', array_map(
                            fn($v) => is_string($v) ? '"' . addslashes($v) . '"' : (int)$v,
                            (array)$value
                        )) . ']',
                        'LIKE' => $key . ' = "' . addslashes((string)$value) . '"',
                        default => is_string($value)
                            ? $key . ' ' . $where['condition'] . ' "' . addslashes($value) . '"'
                            : $key . ' ' . $where['condition'] . ' ' . $value,
                    };
                })(),

                WhereType::Raw => $where['condition'],

                WhereType::Delegate => (function () use ($where): string {
                    $sub = new static($this->connection);
                    if ($this->mode === 'model') {
                        $sub->setModel($this->model);
                    } else {
                        $sub->setTable($this->table);
                    }
                    ($where['value'])($sub);
                    return '(' . $sub->_buildFilter($sub->wheres) . ')';
                })(),
            };

            if ($condition !== '') {
                if (!empty($parts)) {
                    $parts[] = $where['operator']->value;
                }
                $parts[] = $condition;
            }
        }

        return implode(' ', $parts);
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

        $payload['limit']  = $this->limit  ?? PHP_INT_MAX;
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

    public function insert(Arr|array $data): mixed
    {
        $data = (array)$data;

        if ($this->mode === 'model') {
            $data = $this->_castDataPos($data);
            $data = $this->_castDataPre($data);
        }

        if (empty($data)) {
            throw new Exception("Insert payload in table {$this->table} can't be empty.");
        }

        $primaryKey         = 'id';
        $primaryKeyProperty = 'id';

        if ($this->mode === 'model') {
            foreach ($this->modelData->column as $column) {
                if ($column->primary === true) {
                    if ($column->type !== 'string') {
                        throw new Exception('Primary key for Meilisearch must be string type. Model ' . $this->model . '.');
                    }
                    $primaryKey         = $column->name;
                    $primaryKeyProperty = $column->property;
                    break;
                }
            }
        }

        $data[$primaryKey] = $this->_generateUuid();

        $response = $this->client->request(
            'POST',
            'indexes/' . $this->table . '/documents',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => json_encode((object)$data),
            ]
        );

        $taskUid = (int)json_decode($response->getBody()->getContents())->taskUid;
        if ($taskUid === 0) {
            throw new Exception('Failed to create row in model class ' . $this->model . ' with Meilisearch API.');
        }

        $this->_waitForTask($taskUid, 'create row in model class ' . $this->model);

        return [$primaryKeyProperty => $data[$primaryKey]];
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
