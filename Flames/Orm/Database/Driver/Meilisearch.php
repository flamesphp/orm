<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Driver;

use Exception;
use Flames\Collection\Arr;
use Flames\Http;
use Flames\Orm\Database\Type\Kinds;
use Flames\Orm\Database\Type\Maps;

/**
 * @internal
 */
class Meilisearch extends DefaultEx
{
    protected const __VERSION__ = 3;

    protected $connection = null;
    protected $allIndexes = [];
    /** @var array<string, string> index uid => migration hash */
    protected array $settingsSynced = [];
    private static bool $containsFilterEnabled = false;

    public function __construct(\Flames\Orm\Database\RawConnection\Meilisearch $connection)
    {
        $this->connection = $connection;
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function getQueryBuilder($model)
    {
        $metadata = $model::getMetadata();
        return new \Flames\Orm\Database\QueryBuilder\Meilisearch($this->connection);
    }

    public function migrate($data)
    {
        $connection = $this->getConnection();
        /** @var Http\Client $client */
        $client = $connection->getClient();

        self::_ensureContainsFilterEnabled($client);

        $hash = $this->__migrationHash($data);

        if (($this->settingsSynced[$data->table] ?? '') === $hash) {
            return true;
        }

        $request = $client->request(
            'GET',
            'indexes?limit=' . PHP_INT_MAX
        );

        $indexExists = false;
        $results = json_decode($request->getBody()->getContents())->results ?? [];
        foreach ($results as $result) {
            $this->allIndexes[] = $result->uid;
            if ($result->uid === $data->table) {
                $indexExists = true;
                break;
            }
        }

        if ($indexExists === false) {
            $request = $client->request(
                'POST',
                'indexes',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode((object) [
                        'uid'        => $data->table,
                        'primaryKey' => self::_resolvePrimaryKeyName($data),
                    ]),
                ]
            );

            self::_waitForTask(
                $client,
                self::_extractTaskUid($request, 'create index for model class ' . $data->class),
            );
        }

        self::_syncIndexSettings($client, $data);

        $this->settingsSynced[$data->table] = $hash;

        if (in_array($data->table, $this->allIndexes, true) === false) {
            $this->allIndexes[] = $data->table;
        }

        return true;
    }

    private static function _syncIndexSettings(Http\Client $client, object $data): void
    {
        $attributes = [];
        foreach ($data->column as $column) {
            $attributes[] = $column->name;
        }

        $request = $client->request(
            'PATCH',
            'indexes/' . $data->table . '/settings',
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'filterableAttributes' => $attributes,
                    'sortableAttributes'   => $attributes,
                    'searchableAttributes' => self::_resolveSearchableAttributes($data),
                ], JSON_THROW_ON_ERROR),
            ]
        );

        self::_waitForTask(
            $client,
            self::_extractTaskUid($request, 'configure index settings for model class ' . $data->class),
        );
    }

    private static function _ensureContainsFilterEnabled(Http\Client $client): void
    {
        if (self::$containsFilterEnabled) {
            return;
        }

        $response = $client->request('GET', 'experimental-features');
        $features = json_decode($response->getBody()->getContents(), true);

        if (($features['containsFilter'] ?? false) !== true) {
            $client->request(
                'PATCH',
                'experimental-features',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode(['containsFilter' => true], JSON_THROW_ON_ERROR),
                ]
            );
        }

        self::$containsFilterEnabled = true;
    }

    /**
     * @return list<string>
     */
    private static function _resolveSearchableAttributes(object $data): array
    {
        $searchable = [];

        foreach ($data->column as $column) {
            $type = Kinds::normalize((string) ($column->type ?? 'string'));
            if (in_array($type, Maps::TEXT_SEARCH_MEILI, true)) {
                $searchable[] = $column->name;
            }
        }

        return $searchable !== [] ? $searchable : ['*'];
    }

    private static function _extractTaskUid(mixed $response, string $context): int
    {
        $payload = json_decode($response->getBody()->getContents());
        $taskUid = (int) ($payload->taskUid ?? 0);

        if ($taskUid === 0) {
            $message = isset($payload->message) ? ' ' . $payload->message : '';
            throw new Exception('Failed to ' . $context . ' with meilisearch API.' . $message);
        }

        return $taskUid;
    }

    private static function _waitForTask(Http\Client $client, int $taskUid): void
    {
        $errorMessage = null;

        do {
            usleep(1000);
            $request = $client->request(
                'GET',
                'tasks/' . $taskUid,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                ]
            );

            $requestData = json_decode($request->getBody()->getContents());
            $status      = $requestData->status ?? null;

            if ($status === 'failed') {
                $errorMessage = ' ' . ($requestData->error->message ?? 'unknown error');
            }
        } while ($status === 'processing' || $status === 'enqueued');

        if ($status !== 'succeeded') {
            throw new Exception('Failed meilisearch task ' . $taskUid . '.' . ($errorMessage ?? ''));
        }
    }

    private static function _resolvePrimaryKeyName($data): string
    {
        foreach ($data->column as $column) {
            if ($column->primary === true) {
                return $column->name;
            }
        }

        return 'id';
    }
}
