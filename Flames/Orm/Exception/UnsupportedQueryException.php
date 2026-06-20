<?php
declare(strict_types=1);


namespace Flames\Orm\Exception;

use Exception;

class UnsupportedQueryException extends Exception
{
    public function __construct(string $feature, string $driver = 'meilisearch')
    {
        parent::__construct(
            'Query feature "' . $feature . '" is not supported by the "' . $driver . '" driver.',
        );
    }
}
