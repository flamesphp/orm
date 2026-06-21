<?php
declare(strict_types=1);

namespace Flames\Orm\Database\RawConnection;

/**
 * @internal
 */
class Opensearch extends Elasticsearch
{
    public function __construct(
        string $dsn,
        ?string $user = null,
        ?string $password = null,
        mixed $config = null,
    ) {
        parent::__construct(
            $dsn,
            $user !== null && $user !== '' ? $user : 'admin',
            $password,
            $config,
        );
    }
}
