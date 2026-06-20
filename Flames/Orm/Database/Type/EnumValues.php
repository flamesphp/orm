<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Type;

/**
 * @internal
 */
final class EnumValues
{
    /**
     * @param list<string>|class-string<\UnitEnum>|null $values
     *
     * @return list<string>
     */
    public static function resolve(array|string|null $values, ?string $phpType = null): array
    {
        if (is_string($values) && enum_exists($values)) {
            return self::fromEnumClass($values);
        }

        if (is_array($values)) {
            return array_values($values);
        }

        if ($phpType !== null && enum_exists($phpType)) {
            return self::fromEnumClass($phpType);
        }

        return [];
    }

    public static function resolveClass(array|string|null $values, ?string $phpType = null): ?string
    {
        if (is_string($values) && enum_exists($values)) {
            return $values;
        }

        if ($phpType !== null && enum_exists($phpType)) {
            return $phpType;
        }

        return null;
    }

    /**
     * @param class-string<\UnitEnum> $enumClass
     *
     * @return list<string>
     */
    public static function fromEnumClass(string $enumClass): array
    {
        $values = [];

        foreach ($enumClass::cases() as $case) {
            $values[] = $case instanceof \BackedEnum ? (string) $case->value : $case->name;
        }

        return $values;
    }

    public static function normalizeDefault(mixed $default): mixed
    {
        if (!$default instanceof \UnitEnum) {
            return $default;
        }

        return $default instanceof \BackedEnum ? $default->value : $default->name;
    }

    /**
     * @param class-string<\UnitEnum> $enumClass
     */
    public static function toEnum(string $enumClass, string $value): \UnitEnum
    {
        if (is_subclass_of($enumClass, \BackedEnum::class)) {
            /** @var class-string<\BackedEnum> $enumClass */
            return $enumClass::from($value);
        }

        foreach ($enumClass::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Value "%s" is not a valid case for enum %s.',
            $value,
            $enumClass,
        ));
    }
}
