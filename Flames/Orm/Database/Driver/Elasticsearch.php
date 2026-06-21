<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Driver;

use Flames\Http;
use Flames\Orm\Database\Type\Kinds;
use Flames\Orm\Database\Type\Maps;
use Exception;

/**
 * @internal
 */
class Elasticsearch extends DefaultEx
{
    protected const __VERSION__ = 1;

    protected \Flames\Orm\Database\RawConnection\Elasticsearch $connection;

    /** @var list<string> */
    protected array $allIndexes = [];

    public function __construct(\Flames\Orm\Database\RawConnection\Elasticsearch $connection)
    {
        $this->connection = $connection;
    }

    public function getConnection(): \Flames\Orm\Database\RawConnection\Elasticsearch
    {
        return $this->connection;
    }

    public function getQueryBuilder($model): \Flames\Orm\Database\QueryBuilder\Elasticsearch
    {
        return new \Flames\Orm\Database\QueryBuilder\Elasticsearch($this->connection);
    }

    protected static function driverName(): string
    {
        return 'elasticsearch';
    }

    public function migrate($data): bool
    {
        if (in_array($data->table, $this->allIndexes, true)) {
            return true;
        }

        /** @var Http\Client $client */
        $client = $this->connection->getClient();
        $index  = (string) $data->table;

        if ($this->_indexExists($client, $index) === false) {
            $response = $client->request(
                'PUT',
                $index,
                [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body'    => json_encode([
                        'mappings' => [
                            'dynamic'    => true,
                            'properties' => self::_buildProperties($data),
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
            );

            self::_assertSuccess($response, 'create index for model class ' . $data->class);
        }

        $this->allIndexes[] = $data->table;

        return true;
    }

    protected function _indexExists(Http\Client $client, string $index): bool
    {
        $response = $client->request('HEAD', $index, ['http_errors' => false]);

        return $response->getStatusCode() === 200;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function _buildProperties(object $data): array
    {
        $properties = [];

        foreach ($data->column as $column) {
            $properties[$column->name] = self::_mapColumnProperty($column);
        }

        return $properties;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function _mapColumnProperty(object $column): array
    {
        $type = Kinds::normalize((string) ($column->type ?? 'string'));

        if (in_array($type, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'year'], true)) {
            return ['type' => 'long'];
        }

        if (in_array($type, ['float', 'double', 'real', 'decimal', 'numeric'], true)) {
            return ['type' => 'double'];
        }

        if (in_array($type, ['bool', 'boolean', 'bit'], true)) {
            return ['type' => 'boolean'];
        }

        if ($type === 'date') {
            return ['type' => 'date', 'format' => 'yyyy-MM-dd||strict_date_optional_time||epoch_millis'];
        }

        if (in_array($type, ['datetime', 'timestamp', 'timestamptz'], true)) {
            return [
                'type'   => 'date',
                'format' => 'strict_date_optional_time||epoch_millis||yyyy-MM-dd',
            ];
        }

        if (in_array($type, ['time', 'timetz', 'interval'], true)) {
            return ['type' => 'keyword'];
        }

        if (in_array($type, ['json', 'jsonb', 'object', 'array'], true)) {
            return ['type' => 'object', 'dynamic' => true];
        }

        if ($type === 'set') {
            return ['type' => 'keyword'];
        }

        if (in_array($type, Maps::TEXT_SEARCH_MEILI, true)) {
            return ['type' => 'keyword'];
        }

        return ['type' => 'keyword'];
    }

    protected static function _assertSuccess(mixed $response, string $context): void
    {
        $status = (int) $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        $body    = (string) $response->getBody()->getContents();
        $payload = json_decode($body, true);
        $reason  = is_array($payload) ? ($payload['error']['reason'] ?? $payload['error'] ?? $body) : $body;

        throw new Exception('Failed to ' . $context . ' with ' . static::driverName() . ' API: ' . (string) $reason);
    }
}
