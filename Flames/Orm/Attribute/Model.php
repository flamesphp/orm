<?php

declare(strict_types=1);

namespace Flames\Orm\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Model
{
    public function __construct(public readonly ?string $model = null)
    {
    }
}
