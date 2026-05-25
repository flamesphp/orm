<?php

namespace Flames\Orm\Database\Driver;

/**
 * @internal
 */
class Mariadb extends MySql
{
    public function getQueryBuilder($model): \Flames\Orm\Database\QueryBuilder\MariaDb
    {
        return new \Flames\Orm\Database\QueryBuilder\MariaDb($this->connection);
    }
}
