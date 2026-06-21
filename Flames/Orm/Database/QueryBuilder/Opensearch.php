<?php
declare(strict_types=1);

namespace Flames\Orm\Database\QueryBuilder;

/**
 * @internal
 */
class Opensearch extends Elasticsearch
{
    protected const DRIVER = 'opensearch';
}
