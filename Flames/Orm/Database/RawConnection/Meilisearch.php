<?php
declare(strict_types=1);


namespace Flames\Orm\Database\RawConnection;

use Flames\Http;

/**
 * @internal
 */
class Meilisearch
{
    private readonly Http\Client $client;

    public function __construct(
        string            $dsn,
        string            $masterKey,
        private readonly mixed $config = null,
    ) {
        $this->client = new Http\Client([
            'allow_redirects' => true,
            'base_uri'        => $dsn,
            'headers'         => [
                'X-MEILI-API-KEY' => $masterKey,
                'Authorization'   => 'Bearer ' . $masterKey,
            ],
        ]);
    }

    public function getConfig(): mixed       { return $this->config; }
    public function getClient(): Http\Client { return $this->client; }
}
