<?php

declare(strict_types=1);

namespace Flames\Orm\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Database
{
    public function __construct(public readonly ?string $name = null)
    {
    }
}
