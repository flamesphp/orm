<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Collection\Arr;
use Flames\Collection\ArrImmutable;
use Flames\Orm\Database\Cast\Support\ArrValue;

class Vector
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $items = self::__normalizeItems($column, $value);

        return json_encode($items, JSON_THROW_ON_ERROR);
    }

    public static function pos($column, $value, $fromDb = false): Arr|ArrImmutable|array|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
                $value = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
            } else {
                $value = array_map(
                    static fn (string $part): float => (float) trim($part),
                    explode(',', $trimmed),
                );
            }
        }

        $items = self::__normalizeItems($column, $value);

        return ArrValue::wrap($column, $items);
    }

    /**
     * @return list<float>
     */
    private static function __normalizeItems(object $column, mixed $value): array
    {
        if ($value instanceof ArrImmutable) {
            $value = $value->toArray();
        }

        if ($value instanceof Arr) {
            $value = $value->toArray();
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException('Vector column value must be an array of floats.');
        }

        $items = array_map(static fn (mixed $item): float => (float) $item, array_values($value));
        $dimensions = (int) ($column->size ?? 0);

        if ($dimensions > 0 && count($items) !== $dimensions) {
            throw new \InvalidArgumentException(sprintf(
                'Vector column %s expects exactly %d dimensions, got %d.',
                $column->name ?? 'unknown',
                $dimensions,
                count($items),
            ));
        }

        return $items;
    }
}
