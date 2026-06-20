<?php

declare(strict_types=1);

namespace Flames\Orm\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Column
{
    /**
     * @param list<string>|class-string<\UnitEnum>|null $values Allowed values or enum class for enum/set columns.
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $type = null,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly array|string|null $values = null,
        public readonly ?int $srid = null,
        public readonly mixed $default = null,
        public readonly ?bool $nullable = null,
        public readonly ?bool $primary = null,
        public readonly ?bool $index = null,
        public readonly ?bool $unique = null,
        public readonly ?bool $autoIncrement = null,
        public readonly ?bool $unsigned = null,
    ) {
    }
}
