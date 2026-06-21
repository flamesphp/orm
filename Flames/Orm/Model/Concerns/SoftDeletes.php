<?php

declare(strict_types=1);

namespace Flames\Orm\Model\Concerns;

use Flames\Date\DateTimeImmutable;
use Flames\Orm\Attribute\Column;

trait SoftDeletes
{
    public const DELETED_AT = 'deletedAt';

    #[Column(name: 'deleted_at', type: 'timestamp', nullable: true)]
    public ?DateTimeImmutable $deletedAt = null;
}
