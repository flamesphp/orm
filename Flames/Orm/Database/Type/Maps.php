<?php
declare(strict_types=1);

namespace Flames\Orm\Database\Type;

/**
 * Canonical PostgreSQL ORM type maps and cross-driver fallbacks.
 *
 * @internal
 */
final class Maps
{
    /** @var array<string, string> */
    public const NORMALIZE = [
        'integer'           => 'int',
        'boolean'           => 'bool',
        'numeric'           => 'decimal',
        'int2'              => 'smallint',
        'int4'              => 'int',
        'int8'              => 'bigint',
        'float4'            => 'float',
        'float8'            => 'double',
        'character'         => 'char',
        'character varying' => 'varchar',
        'serial2'           => 'smallserial',
        'serial4'           => 'serial',
        'serial8'           => 'bigserial',
        'bit varying'       => 'varbit',
        'double precision'  => 'double',
    ];

    /** @var list<string> */
    public const SERIAL = ['smallserial', 'serial', 'bigserial'];

    /** @var list<string> */
    public const RANGE = ['int4range', 'int8range', 'numrange', 'tsrange', 'tstzrange', 'daterange'];

    /** @var list<string> */
    public const NATIVE_GEO = ['point2d', 'line', 'lseg', 'box', 'path', 'polygon2d', 'circle'];

    /** @var list<string> */
    public const NETWORK = ['cidr', 'inet', 'macaddr', 'macaddr8'];

    /** @var list<string> */
    public const FULL_TEXT = ['tsvector', 'tsquery'];

    /** @var list<string> */
    public const TEXT_SEARCH_MEILI = [
        'string', 'char', 'varchar', 'text', 'longtext', 'tinytext', 'mediumtext',
        'xml', 'tsvector', 'tsquery', 'json', 'jsonb',
    ];

    /** @var array<string, string> */
    public const CAST = [
        'smallint'    => 'Ints',
        'int'         => 'Ints',
        'mediumint'   => 'Ints',
        'tinyint'     => 'Ints',
        'bigint'      => 'Bigint',
        'float'       => 'Floats',
        'real'        => 'Floats',
        'decimal'     => 'Decimal',
        'double'      => 'Double',
        'vector'      => 'Vector',
        'bool'        => 'Boolean',
        'string'      => 'Strings',
        'char'        => 'Char',
        'varchar'     => 'Varchar',
        'text'        => 'Text',
        'tinytext'    => 'Text',
        'mediumtext'  => 'Text',
        'longtext'    => 'Longtext',
        'binary'      => 'Binary',
        'varbinary'   => 'Varbinary',
        'blob'        => 'Blob',
        'tinyblob'    => 'Blob',
        'mediumblob'  => 'Blob',
        'longblob'    => 'Blob',
        'enum'        => 'Enum',
        'set'         => 'Set',
        'array'       => 'ArrayList',
        'object'      => 'Json',
        'json'        => 'Json',
        'jsonb'       => 'Jsonb',
        'date'        => 'Date',
        'datetime'    => 'Datetime',
        'timestamp'   => 'Timestamp',
        'timestamptz' => 'Timestamptz',
        'time'        => 'Time',
        'timetz'      => 'Timetz',
        'interval'    => 'Interval',
        'year'        => 'Year',
        'uuid'        => 'Uuid',
        'xml'         => 'Xml',
        'money'       => 'Money',
        'pg_lsn'      => 'PgLsn',
        'txid_snapshot' => 'TxidSnapshot',
        'cidr'        => 'Cidr',
        'inet'        => 'Inet',
        'macaddr'     => 'Macaddr',
        'macaddr8'    => 'Macaddr8',
        'tsvector'    => 'Tsvector',
        'tsquery'     => 'Tsquery',
        'varbit'      => 'Varbit',
        'int4range'   => 'Range',
        'int8range'   => 'Range',
        'numrange'    => 'Range',
        'tsrange'     => 'Range',
        'tstzrange'   => 'Range',
        'daterange'   => 'Range',
        'point2d'     => 'NativeGeo',
        'line'        => 'NativeGeo',
        'lseg'        => 'NativeGeo',
        'box'         => 'NativeGeo',
        'path'        => 'NativeGeo',
        'polygon2d'   => 'NativeGeo',
        'circle'      => 'NativeGeo',
        'geometry'    => 'Geometry',
        'point'       => 'Geometry',
        'linestring'  => 'Geometry',
        'polygon'     => 'Geometry',
        'multipoint'  => 'Geometry',
        'multilinestring' => 'Geometry',
        'multipolygon'=> 'Geometry',
        'geometrycollection' => 'Geometry',
        'smallserial' => 'Ints',
        'serial'      => 'Ints',
        'bigserial'   => 'Bigint',
    ];

    /** @var array<string, string> Shared logical aliases on non-PostgreSQL drivers (DDL + cast). */
    public const STORAGE_ALIASES = [
        'jsonb'  => 'json',
        'object' => 'json',
    ];

    /** @var array<string, string> MySQL/MariaDB DDL renames after PG-native handlers (serials, etc.). */
    public const MYSQL_FALLBACK = [
        'timestamptz'   => 'timestamp',
        'timetz'        => 'time',
        'interval'      => 'varchar',
        'xml'           => 'longtext',
        'money'         => 'decimal',
        'pg_lsn'        => 'varchar',
        'txid_snapshot' => 'varchar',
        'cidr'          => 'varchar',
        'inet'          => 'varchar',
        'tsvector'      => 'longtext',
        'tsquery'       => 'varchar',
        'int4range'     => 'json',
        'int8range'     => 'json',
        'numrange'      => 'json',
        'tsrange'       => 'json',
        'tstzrange'     => 'json',
        'daterange'     => 'json',
        'point2d'       => 'varchar',
        'line'          => 'varchar',
        'lseg'          => 'varchar',
        'box'           => 'varchar',
        'path'          => 'varchar',
        'polygon2d'     => 'varchar',
        'circle'        => 'varchar',
        'smallserial'   => 'smallint',
        'serial'        => 'int',
        'bigserial'     => 'bigint',
    ];
}
