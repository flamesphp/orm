<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Cast\Meilisearch;

use Flames\Orm\Database\Cast\Default\Binary as DefaultBinary;
use Flames\Orm\Database\Cast\Meilisearch\Support\BinaryCodec;

class Binary extends DefaultBinary
{
    public static function pre($column, $value): string|null
    {
        $raw = parent::pre($column, $value);

        return $raw === null ? null : BinaryCodec::encode($raw);
    }

    public static function pos($column, $value, $fromDb = false): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        return parent::pos($column, BinaryCodec::decode($value), $fromDb);
    }
}
