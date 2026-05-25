<?php

namespace Flames\Orm\Database\QueryBuilder;

/**
 * @internal
 */
enum WhereType
{
    case Simple;
    case Raw;
    case Delegate;
}
