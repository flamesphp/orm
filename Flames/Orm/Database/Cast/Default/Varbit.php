<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

class Varbit extends StringValue
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            $bits = max(1, (int) ($column->size ?? 1));

            return str_pad(decbin($value), $bits, '0', STR_PAD_LEFT);
        }

        $string = (string) $value;

        if (!preg_match('/^[01]+$/', $string)) {
            throw new \InvalidArgumentException('Varbit value must be a bit string.');
        }

        return $string;
    }
}
