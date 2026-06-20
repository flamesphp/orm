<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Cast\Meilisearch;

use Flames\Orm\Database\Cast\Default\Bit as DefaultBit;

class Bit
{
    public static function pre($column, $value): bool|int|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $bits   = max(1, (int) ($column->size ?? 1));
        $packed = DefaultBit::pre($column, $value);

        if ($packed === null) {
            return null;
        }

        if ($bits === 1) {
            return (ord($packed[0] ?? "\0") & 1) === 1;
        }

        return DefaultBit::pos($column, $packed);
    }

    public static function pos($column, $value, $fromDb = false): bool|int|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        $bits = max(1, (int) ($column->size ?? 1));

        if ($bits === 1) {
            if (is_bool($value)) {
                return $value;
            }

            return match (true) {
                $value === 1, $value === 1.0, $value === '1', $value === 'true'  => true,
                $value === 0, $value === 0.0, $value === '0', $value === 'false' => false,
                default => (bool) $value,
            };
        }

        return (int) $value;
    }
}
