<?php
declare(strict_types=1);


namespace Flames\Orm\Database\QueryBuilder;

/**
 * @internal
 */
enum WhereOperator: string
{
    case And = 'AND';
    case Or  = 'OR';
}
