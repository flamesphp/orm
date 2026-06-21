<?php
declare(strict_types=1);

namespace Flames\Orm\Database\RawConnection;

use Flames\Http;

/**
 * @internal
 */
class Elasticsearch
{
    private readonly Http\Client $client;

    public function __construct(
        string            $dsn,
        private readonly ?string $user = null,
        private readonly ?string $password = null,
        private readonly mixed $config = null,
    ) {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];

        $user     = trim((string) ($user ?? ''));
        $password = (string) ($password ?? '');

        if ($user !== '' && $password !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($user . ':' . $password);
        }

        $this->client = new Http\Client([
            'allow_redirects' => true,
            'base_uri'        => $dsn,
            'headers'         => $headers,
        ]);
    }

    public function getConfig(): mixed       { return $this->config; }
    public function getClient(): Http\Client { return $this->client; }
}
