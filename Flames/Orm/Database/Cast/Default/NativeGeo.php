<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Collection\Vector2Immutable;
use Flames\Geometry\Pg\Geo as PgGeo;
use Flames\Orm\Database\Cast\Support\PgValue;
use Flames\Orm\Database\Type\Kinds;

class NativeGeo
{
    public static function pre($column, $value): string|null
    {
        return PgValue::pre($column, $value, static function (mixed $input) use ($column): string {
            $type = Kinds::normalize($column->type);

            if ($type === 'point2d') {
                return (string) Vector2Immutable::parse($input);
            }

            return (string) PgGeo::parse($type, $input);
        });
    }

    public static function pos($column, $value, $fromDb = false): PgGeo|Vector2Immutable|string|null
    {
        $type = Kinds::normalize($column->type);

        if ($type === 'point2d') {
            return PgValue::pos($column, $value, static fn (mixed $input): Vector2Immutable => Vector2Immutable::parse($input));
        }

        return PgValue::pos($column, $value, static fn (mixed $input): PgGeo => PgGeo::parse($type, $input));
    }
}
