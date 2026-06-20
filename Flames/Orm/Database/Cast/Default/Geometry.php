<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Cast\Default;

use Flames\Geometry\Geometries;
use Flames\Geometry\Geometry as GeometryValue;

class Geometry
{
    public static function pre($column, $value): string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if ($value instanceof GeometryValue) {
            return $value->toWkt();
        }

        if (is_string($value)) {
            Geometries::parse($value);
            return trim($value);
        }

        if (is_array($value)) {
            return Geometries::parse($value)->toWkt();
        }

        throw new \InvalidArgumentException('Geometry column value must be a Geometry instance, WKT, GeoJSON or coordinate array.');
    }

    public static function pos($column, $value, $fromDb = false): GeometryValue|string|null
    {
        if ($column->nullable === true && $value === null) {
            return null;
        }

        if ($value instanceof GeometryValue) {
            return $value;
        }

        $geometry = Geometries::parse($value);

        $phpType = $column->phpType ?? null;
        if ($phpType !== null && is_subclass_of($phpType, GeometryValue::class) && !is_a($geometry, $phpType, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Expected geometry type %s but got %s.',
                $phpType,
                $geometry::class,
            ));
        }

        return $geometry;
    }
}
