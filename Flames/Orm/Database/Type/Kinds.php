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
    ];

    public static function normalize(string $type): string
    {
        $type = strtolower($type);

        return match ($type) {
            'integer' => 'int',
            'boolean' => 'bool',
            default   => $type,
        };
    }

    public static function isSpatial(string $type): bool
    {
        return in_array(self::normalize($type), self::SPATIAL, true);
    }

    public static function castClass(string $type): string
    {
        $type = self::normalize($type);

        $normalized = match ($type) {
            'bool'   => 'Boolean',
            'int', 'tinyint', 'smallint', 'mediumint' => 'Ints',
            'bigint' => 'Bigint',
            'float'  => 'Floats',
            'double' => 'Double',
            'decimal'=> 'Decimal',
            'bit'    => 'Bit',
            'string' => 'Strings',
            'char'   => 'Char',
            'varchar'=> 'Varchar',
            'text', 'tinytext', 'mediumtext' => 'Text',
            'longtext' => 'Longtext',
            'binary' => 'Binary',
            'varbinary' => 'Varbinary',
            'blob', 'tinyblob', 'mediumblob', 'longblob' => 'Blob',
            'enum'   => 'Enum',
            'set'    => 'Set',
            'json'   => 'Json',
            'date'   => 'Date',
            'datetime' => 'Datetime',
            'timestamp' => 'Timestamp',
            'time'   => 'Time',
            'year'   => 'Year',
            'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection' => 'Geometry',
            default  => ucfirst($type),
        };

        $class = 'Flames\\Orm\\Database\\Cast\\Default\\' . $normalized;

        if (!class_exists($class)) {
            return 'Flames\\Orm\\Database\\Cast\\Default\\Strings';
        }

        return $class;
    }

    public static function ddlType(object $column): string
    {
        $type = self::normalize($column->type);

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

        if ($type === 'bool' || $type === 'boolean') {
            return 'tinyint(1)';
        }

        $sql = $type;

        $size = $column->size ?? match ($type) {
            'varchar' => 255,
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

    public static function ddlDefault(object $column): string
    {
        if ($column->default === null) {
            return ' DEFAULT NULL';
        }

        $type = self::normalize($column->type);

        if (in_array($type, ['bool', 'boolean', 'bit'], true)) {
            $bool = filter_var($column->default, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return ' DEFAULT ' . ($bool ? 1 : 0);
        }

        if (in_array($type, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'float', 'double', 'decimal', 'year'], true)) {
            return ' DEFAULT ' . $column->default;
        }

        $escaped = str_replace("'", "''", (string) $column->default);

        return " DEFAULT '" . $escaped . "'";
    }
}
