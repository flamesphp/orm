<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Driver;

/**
 * @internal
 */
class Opensearch extends Elasticsearch
{
    protected static function driverName(): string
    {
        return 'opensearch';
    }

    public function __construct(\Flames\Orm\Database\RawConnection\Opensearch $connection)
    {
        parent::__construct($connection);
    }

    public function getQueryBuilder($model): \Flames\Orm\Database\QueryBuilder\Opensearch
    {
        return new \Flames\Orm\Database\QueryBuilder\Opensearch($this->connection);
    }
}
