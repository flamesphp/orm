<?php
declare(strict_types=1);


namespace Flames\Orm\Database;

use Flames\Orm\Database\QueryBuilder\MariaDb;
use Flames\Orm\Database\QueryBuilder\MySql;
use Flames\Orm\Database\QueryBuilder\Postgresql;
use PDO;
use Exception;

/**
 * @internal
 */
class QueryBuilder
{
    public static function getByTypeAndConnection($type, $connection)
    {
        if ($type === 'mariadb') {
            return new MariaDb($connection);
        }
        elseif ($type === 'mysql') {
            return new MySql($connection);
        }
        elseif ($type === 'postgresql') {
            return new Postgresql($connection);
        }
    }
}
