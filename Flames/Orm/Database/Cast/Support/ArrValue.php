<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Support;

use Flames\Collection\Arr;
use Flames\Collection\ArrImmutable;

/**
 * @internal
 */
final class ArrValue
{
    public static function wantsArray(object $column): bool
    {
        return ($column->phpType ?? null) === 'array';
    }

    public static function wantsMutableArr(object $column): bool
    {
        return ($column->phpType ?? null) === Arr::class;
    }

    public static function wantsImmutableArr(object $column): bool
    {
        return ($column->phpType ?? null) === ArrImmutable::class;
    }

    public static function fit(object $column, mixed $value): mixed
    {
        if (self::wantsArray($column)) {
            return self::toPlain($value);
        }

        if (self::wantsMutableArr($column)) {
            if ($value instanceof ArrImmutable) {
                return $value->toMutable();
            }

            if ($value instanceof Arr) {
                return $value;
            }

            if (is_array($value)) {
                return self::from($value);
            }

            return $value;
        }

        if ($value instanceof Arr && !$value instanceof ArrImmutable) {
            return $value->toImmutable();
        }

        if (is_array($value)) {
            return self::fromImmutable($value);
        }

        return $value;
    }

    public static function from(mixed $value): Arr
    {
        if ($value instanceof Arr) {
            return $value;
        }

        if ($value instanceof ArrImmutable) {
            return $value->toMutable();
        }

        if (!is_array($value)) {
            return new Arr();
        }

        $normalized = [];
        foreach ($value as $key => $child) {
            $normalized[$key] = is_array($child) ? self::from($child) : $child;
        }

        return new Arr($normalized);
    }

    public static function fromImmutable(mixed $value): ArrImmutable
    {
        if ($value instanceof ArrImmutable) {
            return $value;
        }

        if ($value instanceof Arr) {
            return $value->toImmutable();
        }

        if (!is_array($value)) {
            return new ArrImmutable();
        }

        return new ArrImmutable($value);
    }

    public static function toPlain(mixed $value): mixed
    {
        if ($value instanceof ArrImmutable) {
            return $value->toArray();
        }

        if ($value instanceof Arr) {
            return $value->toArray();
        }

        if (!is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $child) {
            $normalized[$key] = is_array($child) || $child instanceof Arr || $child instanceof ArrImmutable
                ? self::toPlain($child)
                : $child;
        }

        return $normalized;
    }

    public static function wrap(object $column, mixed $value): mixed
    {
        if (self::wantsArray($column)) {
            return self::toPlain($value);
        }

        if (self::wantsMutableArr($column)) {
            return self::from($value);
        }

        if (self::wantsImmutableArr($column) || in_array($column->type, ['json', 'set'], true)) {
            return self::fromImmutable($value);
        }

        return self::fromImmutable($value);
    }
}
