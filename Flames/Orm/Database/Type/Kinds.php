<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Type;

/**
 * @internal
 */
final class Kinds
{
    public const SPATIAL = [
        'geometry',
        'point',
        'linestring',
        'polygon',
        'multipoint',
        'multilinestring',
        'multipolygon',
        'geometrycollection',
    ];

    public const UNSIGNED = [
        'int',
        'integer',
        'bigint',
        'tinyint',
        'smallint',
        'mediumint',
        'float',
        'double',
        'decimal',
        'numeric',
    ];

    public static function normalize(string $type): string
    {
        $type = strtolower(trim($type));

        return Maps::NORMALIZE[$type] ?? $type;
    }

    public static function resolveCastType(object $column, ?string $driver = null): string
    {
        $type = self::normalize($column->type);

        if ($type === 'bit' && max(1, (int) ($column->size ?? 1)) > 1) {
            $type = 'varbit';
        }

        if ($driver !== null) {
            $type = self::resolveDriverAlias($type, $driver);
        }

        return $type;
    }

    public static function resolveDriverAlias(string $type, string $driver): string
    {
        $type   = self::normalize($type);
        $driver = strtolower(trim($driver));

        if (in_array($driver, ['mysql', 'mariadb', 'meilisearch'], true)) {
            return Maps::STORAGE_ALIASES[$type] ?? $type;
        }

        return $type;
    }

    public static function isSpatial(string $type): bool
    {
        return in_array(self::normalize($type), self::SPATIAL, true);
    }

    public static function isVector(string $type): bool
    {
        return self::normalize($type) === 'vector';
    }

    public static function isBinary(string $type): bool
    {
        return in_array(self::normalize($type), ['binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob'], true);
    }

    public static function isSerial(string $type): bool
    {
        return in_array(self::normalize($type), Maps::SERIAL, true);
    }

    public static function isRange(string $type): bool
    {
        return in_array(self::normalize($type), Maps::RANGE, true);
    }

    public static function isNativeGeo(string $type): bool
    {
        return in_array(self::normalize($type), Maps::NATIVE_GEO, true);
    }

    public static function isNetwork(string $type): bool
    {
        return in_array(self::normalize($type), Maps::NETWORK, true);
    }

    public static function isFullText(string $type): bool
    {
        return in_array(self::normalize($type), Maps::FULL_TEXT, true);
    }

    public static function needsPgTextCast(string $type): bool
    {
        $type = self::normalize($type);

        return self::isRange($type)
            || self::isNativeGeo($type)
            || self::isNetwork($type)
            || self::isFullText($type)
            || in_array($type, ['interval', 'xml', 'money', 'pg_lsn', 'txid_snapshot', 'uuid', 'varbit'], true);
    }

    public static function castClass(string $type): string
    {
        $type = self::normalize($type);

        if ($type === 'bit') {
            return 'Flames\\Orm\\Database\\Cast\\Default\\Bit';
        }

        $shortName = Maps::CAST[$type] ?? ucfirst($type);
        $class     = 'Flames\\Orm\\Database\\Cast\\Default\\' . $shortName;

        if (!class_exists($class)) {
            return 'Flames\\Orm\\Database\\Cast\\Default\\Strings';
        }

        return $class;
    }

    public static function castClassForColumn(object $column, ?string $driver = null): string
    {
        return self::castClass(self::resolveCastType($column, $driver));
    }

    public static function ddlType(object $column, string $driver = 'mysql'): string
    {
        if ($driver === 'postgresql') {
            return self::ddlTypePostgresql($column);
        }

        $type = self::resolveCastType($column);
        $type = self::resolveDriverAlias($type, $driver);

        if ($type === 'varbit') {
            return 'bit(' . max(1, (int) ($column->size ?? 64)) . ')';
        }

        if ($type === 'string') {
            $type = 'varchar';
        }

        if ($type === 'enum' || $type === 'set') {
            $values = $column->values ?? [];
            if ($values === []) {
                throw new \InvalidArgumentException("Column {$column->name} of type {$type} requires values.");
            }

            $quoted = implode(', ', array_map(
                static fn (string $value): string => "'" . str_replace("'", "''", $value) . "'",
                $values,
            ));

            return $type . '(' . $quoted . ')';
        }

        if ($type === 'decimal') {
            $precision = $column->precision ?? $column->size ?? 10;
            $scale     = $column->scale ?? 0;

            return 'decimal(' . $precision . ',' . $scale . ')';
        }

        if ($type === 'vector') {
            $dimensions = $column->size ?? null;
            if ($dimensions === null || $dimensions < 1) {
                throw new \InvalidArgumentException("Column {$column->name} of type vector requires length (dimensions).");
            }

            return 'vector(' . (int) $dimensions . ')';
        }

        if (in_array($type, ['bool', 'boolean'], true) || ($type === 'bit' && max(1, (int) ($column->size ?? 1)) === 1)) {
            return 'tinyint(1)';
        }

        if ($type === 'varbit') {
            return 'bit(' . max(1, (int) ($column->size ?? 64)) . ')';
        }

        if (self::isRange($type)) {
            return 'json';
        }

        if ($type === 'uuid') {
            return 'char(36)';
        }

        if ($type === 'xml' || $type === 'tsvector') {
            return 'longtext';
        }

        if ($type === 'tsquery') {
            return 'varchar(512)';
        }

        if ($type === 'interval') {
            return 'varchar(64)';
        }

        if ($type === 'money') {
            return 'decimal(19,4)';
        }

        if (in_array($type, ['pg_lsn', 'txid_snapshot', 'cidr', 'inet'], true)) {
            return 'varchar(128)';
        }

        if ($type === 'macaddr') {
            return 'char(17)';
        }

        if ($type === 'macaddr8') {
            return 'char(23)';
        }

        if (self::isNativeGeo($type)) {
            return 'varchar(255)';
        }

        if (self::isSpatial($type)) {
            $sql = self::normalize($column->type);
            if ($driver === 'mysql' && ($column->srid ?? 0) > 0) {
                $sql .= ' SRID ' . (int) $column->srid;
            }

            return $sql;
        }

        if ($type === 'real') {
            return 'double';
        }

        if ($type === 'timestamptz') {
            return 'timestamp';
        }

        if ($type === 'timetz') {
            return 'time';
        }

        if (isset(Maps::MYSQL_FALLBACK[$type])) {
            $type = Maps::MYSQL_FALLBACK[$type];
        }

        $sql = $type;

        $size = $column->size ?? match ($type) {
            'varchar' => 255,
            'uuid'    => 36,
            default   => null,
        };

        if ($size !== null && in_array($type, ['bigint', 'int', 'tinyint', 'smallint', 'mediumint', 'varchar', 'char', 'binary', 'varbinary', 'bit', 'float', 'double'], true)) {
            $sql .= '(' . $size . ')';
        }

        if ($column->unsigned === true && in_array($type, self::UNSIGNED, true)) {
            $sql .= ' UNSIGNED';
        }

        return $sql;
    }

    public static function ddlDefault(object $column, string $driver = 'mysql'): string
    {
        if ($column->default === null) {
            return ' DEFAULT NULL';
        }

        $type = self::resolveCastType($column);

        if ($driver === 'postgresql' && in_array($type, ['bool', 'boolean', 'bit'], true) && max(1, (int) ($column->size ?? 1)) === 1) {
            $bool = filter_var($column->default, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return ' DEFAULT ' . ($bool ? 'true' : 'false');
        }

        if (in_array($type, ['bool', 'boolean', 'bit'], true) && max(1, (int) ($column->size ?? 1)) === 1) {
            $bool = filter_var($column->default, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return ' DEFAULT ' . ($bool ? 1 : 0);
        }

        if (in_array($type, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'float', 'double', 'decimal', 'year'], true)) {
            return ' DEFAULT ' . $column->default;
        }

        $escaped = str_replace("'", "''", (string) $column->default);

        return " DEFAULT '" . $escaped . "'";
    }

    public static function ddlTypePostgresql(object $column): string
    {
        $type = self::normalize($column->type);

        if (self::isSerial($type)) {
            return $type;
        }

        if ($type === 'string') {
            $type = 'varchar';
        }

        if ($type === 'enum') {
            $length = $column->size ?? 255;

            return 'varchar(' . (int) $length . ')';
        }

        if ($type === 'set') {
            return 'text[]';
        }

        if ($type === 'decimal') {
            $precision = $column->precision ?? $column->size ?? 10;
            $scale     = $column->scale ?? 0;

            return 'numeric(' . $precision . ',' . $scale . ')';
        }

        if ($type === 'vector') {
            $dimensions = $column->size ?? null;
            if ($dimensions === null || $dimensions < 1) {
                throw new \InvalidArgumentException("Column {$column->name} of type vector requires length (dimensions).");
            }

            return 'vector(' . (int) $dimensions . ')';
        }

        if (in_array($type, ['bool', 'boolean'], true)) {
            return 'boolean';
        }

        if ($type === 'bit') {
            $bits = max(1, (int) ($column->size ?? 1));

            return $bits === 1 ? 'boolean' : 'bit(' . $bits . ')';
        }

        if ($type === 'varbit') {
            $size = (int) ($column->size ?? 0);

            return $size > 0 ? 'bit varying(' . $size . ')' : 'bit varying';
        }

        if (self::isSpatial($type)) {
            $postgisType = match ($type) {
                'point'              => 'Point',
                'linestring'         => 'LineString',
                'polygon'            => 'Polygon',
                'multipoint'         => 'MultiPoint',
                'multilinestring'    => 'MultiLineString',
                'multipolygon'       => 'MultiPolygon',
                'geometrycollection' => 'GeometryCollection',
                default              => 'Geometry',
            };
            $srid = (int) ($column->srid ?? 4326);

            return 'geometry(' . $postgisType . ',' . $srid . ')';
        }

        if (self::isNativeGeo($type)) {
            return match ($type) {
                'point2d'   => 'point',
                'polygon2d' => 'polygon',
                default     => $type,
            };
        }

        if (self::isRange($type) || self::isNetwork($type) || self::isFullText($type)) {
            return $type;
        }

        if (in_array($type, ['jsonb', 'timestamptz', 'timetz', 'interval', 'uuid', 'xml', 'money', 'pg_lsn', 'txid_snapshot'], true)) {
            return $type;
        }

        $type = match ($type) {
            'tinyint'                 => 'smallint',
            'mediumint'               => 'integer',
            'int', 'integer'          => 'integer',
            'bigint'                  => 'bigint',
            'float'                   => 'real',
            'double'                  => 'double precision',
            'real'                    => 'real',
            'tinytext', 'mediumtext', 'longtext' => 'text',
            'binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob' => 'bytea',
            'datetime', 'timestamp'   => 'timestamp',
            'year'                    => 'smallint',
            'json'                    => 'jsonb',
            default                   => $type,
        };

        if ($column->autoIncrement === true && in_array($type, ['smallint', 'integer', 'bigint'], true)) {
            return match ($type) {
                'bigint'  => 'bigserial',
                'integer' => 'serial',
                default   => 'smallserial',
            };
        }

        $size = $column->size ?? match ($type) {
            'varchar' => 255,
            default   => null,
        };

        if ($size !== null && in_array($type, ['varchar', 'char'], true)) {
            return $type . '(' . $size . ')';
        }

        return $type;
    }
}
