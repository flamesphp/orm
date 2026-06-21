<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast;

/**
 * @internal
 */
class Opensearch extends Elasticsearch
{
    /**
     * @return list<string>
     */
    protected static function _castNamespaces(): array
    {
        return ['Opensearch', 'Elasticsearch', 'Meilisearch'];
    }
}
