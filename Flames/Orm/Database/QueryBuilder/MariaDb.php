<?php
declare(strict_types=1);


namespace Flames\Orm\Database\QueryBuilder;

/**
 * @internal
 */
class MariaDb extends MySql
{
    protected function _driverSupportsReturning(): bool
    {
        return true;
    }

    protected function _jsonEqualitySql(string $col, string $paramName): string
    {
        return "JSON_CONTAINS($col, :$paramName)";
    }
}
