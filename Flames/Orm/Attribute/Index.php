<?php

declare(strict_types=1);

namespace Flames\Orm\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class Index
{
    /** @var list<string> */
    public readonly array $columns;

    public function __construct(string ...$columns)
    {
        $this->columns = $columns;
    }
}
