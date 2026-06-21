<?php

declare(strict_types=1);

namespace Flames\Orm\Model\Concerns;

use Flames\Date\DateTimeImmutable;
use Flames\Orm\Attribute\Column;

trait Timestamps
{
    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    #[Column(name: 'created_at', type: 'timestamp', nullable: true)]
    public ?DateTimeImmutable $createdAt = null;

    #[Column(name: 'updated_at', type: 'timestamp', nullable: true)]
    public ?DateTimeImmutable $updatedAt = null;
}
