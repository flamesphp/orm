<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Cast\Meilisearch;

use Flames\Orm\Database\Cast\Default\Set as DefaultSet;

class Set
{
    public static function pre($column, $value): array|string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if (($column->phpType ?? null) === 'string') {
            return DefaultSet::pre($column, $value);
        }

        $csv = DefaultSet::pre($column, $value);
        if ($csv === null) {
            return null;
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $csv)),
            static fn (string $item): bool => $item !== '',
        ));
    }

    public static function pos($column, $value)
    {
        return DefaultSet::pos($column, $value);
    }
}
