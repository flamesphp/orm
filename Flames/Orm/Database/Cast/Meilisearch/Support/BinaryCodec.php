<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Cast\Meilisearch\Support;

/**
 * @internal
 */
final class BinaryCodec
{
    private const PREFIX = 'b64:';

    public static function encode(string $value): string
    {
        return self::PREFIX . base64_encode($value);
    }

    public static function decode(mixed $value): string
    {
        if (!is_string($value)) {
            return (string) $value;
        }

        if (str_starts_with($value, self::PREFIX)) {
            return base64_decode(substr($value, strlen(self::PREFIX)), true) ?: '';
        }

        return $value;
    }

    public static function isEncoded(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }
}
