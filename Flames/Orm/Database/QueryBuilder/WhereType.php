<?php
declare(strict_types=1);


namespace Flames\Orm\Database\QueryBuilder;

/**
 * @internal
 */
enum WhereType
{
    case Simple;
    case Raw;
    case Delegate;
    case NotDelegate;
    case Expression;
    case Column;
    case Bitwise;
}
