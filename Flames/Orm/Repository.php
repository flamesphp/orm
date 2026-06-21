<?php

declare(strict_types=1);

namespace Flames\Orm;

use Exception;
use Flames\Collection\Arr;
use Flames\Collection\Uuid;
use Flames\Orm\Repository\Data;

/**
 * Base implementation for repositories that retrieve and manipulate model data.
 */
abstract class Repository
{
    private static array $__setup = [];
    private static array $_driver = [];
    private static array $_data = [];

    /** @var array<class-string, int|Uuid|null> */
    private static array $_lastInsertId = [];

    /** @var array<class-string, Arr> */
    private static array $_modifiedIds = [];

    /** @var array<class-string, list<class-string>> */
    private static array $_repositoriesByModel = [];

    public static function __constructStatic(): void
    {
        $class = static::class;

        if (isset(static::$__setup[$class]) === true && self::$__setup[$class] === true) {
            return;
        }

        self::__setup(Data::mountData(static::class));
        static::$__setup[$class] = true;
    }

    private static function __setup(Arr $data): void
    {
        $class = static::class;

        if ($data->model === null || class_exists($data->model) === false) {
            throw new Exception('Repository ' . static::class . ' need a model.');
        }

        if ($data->database === null) {
            $data->database = $data->model::getDatabase();

            if ($data->database === null) {
                throw new Exception('Repository ' . static::class . ' need a database, not founded in model.');
            }
        }

        self::$_data[$class] = $data;

        if (isset(self::$_repositoriesByModel[$data->model]) === false) {
            self::$_repositoriesByModel[$data->model] = [];
        }

        if (in_array($class, self::$_repositoriesByModel[$data->model], true) === false) {
            self::$_repositoriesByModel[$data->model][] = $class;
        }
    }

    public static function getLastInsertId(): int|Uuid|null
    {
        static::__constructStatic();

        return self::$_lastInsertId[static::class] ?? null;
    }

    /**
     * @param class-string $modelClass
     */
    public static function rememberLastInsertId(string $modelClass, mixed $id): void
    {
        if ($id === null || $id === false || $id === '') {
            return;
        }

        if (is_int($id) === false && $id instanceof Uuid === false) {
            return;
        }

        foreach (self::$_repositoriesByModel[$modelClass] ?? [] as $repositoryClass) {
            self::$_lastInsertId[$repositoryClass] = $id;
        }
    }

    public static function getModifiedIds(): Arr
    {
        static::__constructStatic();

        return self::$_modifiedIds[static::class] ?? Arr();
    }

    /**
     * @param class-string $modelClass
     */
    public static function rememberModifiedIds(string $modelClass, Arr|array $ids): void
    {
        $ids = $ids instanceof Arr ? $ids : Arr(array_values($ids));

        foreach (self::$_repositoriesByModel[$modelClass] ?? [] as $repositoryClass) {
            self::$_modifiedIds[$repositoryClass] = $ids;
        }
    }

    public static function query(): Database\QueryBuilder\DefaultEx
    {
        $class = static::class;

        $model = self::$_data[$class]->model;
        $model::getMetadata(true);

        /** @var Database\QueryBuilder\DefaultEx $queryBuilder */
        $queryBuilder = self::getDriver()->getQueryBuilder(self::$_data[$class]->model);
        $queryBuilder->setModel(self::$_data[$class]->model);

        return $queryBuilder;
    }

    public static function get(mixed $index): Model|null
    {
        $indexColumn = self::_getIndexColumn();

        $queryBuilder = self::query();
        $queryBuilder->where($indexColumn->property, $index);
        $queryBuilder->limit(1);
        $rows = $queryBuilder->get();

        if ($rows->count === 0) {
            return null;
        }

        return $rows[0];
    }

    public static function withFilters(Arr|array $filters, Arr|array $options = null): Arr|null
    {
        $filters = self::_parseFilters($filters);
        $queryBuilder = self::query();

        foreach ($filters as $filter) {
            self::_applyFilter($queryBuilder, $filter);
        }

        return $queryBuilder->get();
    }

    private static function _applyFilter(Database\QueryBuilder\DefaultEx $queryBuilder, array $filter): void
    {
        $operator = strtoupper((string) $filter[1]);
        $logic    = strtoupper((string) ($filter[3] ?? 'AND'));

        $method = match ($operator) {
            'LIKE'             => self::_linkMethod('WhereLike', $logic),
            'NOT LIKE'         => self::_linkMethod('WhereNotLike', $logic),
            'LIKE_PATTERN'     => self::_linkMethod('WhereLikePattern', $logic),
            'NOT_LIKE_PATTERN' => self::_linkMethod('WhereNotLikePattern', $logic),
            'IN'               => self::_linkMethod('WhereIn', $logic),
            'NOT IN'           => self::_linkMethod('WhereNotIn', $logic),
            'BETWEEN'          => self::_linkMethod('WhereBetween', $logic),
            'NOT BETWEEN'      => self::_linkMethod('WhereNotBetween', $logic),
            'IS NULL'          => self::_linkMethod('WhereNull', $logic),
            'IS NOT NULL'      => self::_linkMethod('WhereNotNull', $logic),
            'IS DISTINCT FROM' => self::_linkMethod('WhereIsDistinctFrom', $logic),
            'IS NOT DISTINCT FROM' => self::_linkMethod('WhereIsNotDistinctFrom', $logic),
            'ILIKE'            => self::_linkMethod('WhereILike', $logic),
            'NOT ILIKE'        => self::_linkMethod('WhereNotILike', $logic),
            'IS TRUE'          => self::_linkMethod('WhereIsTrue', $logic),
            'IS FALSE'         => self::_linkMethod('WhereIsFalse', $logic),
            'IS UNKNOWN'       => self::_linkMethod('WhereIsUnknown', $logic),
            'IS NOT TRUE'      => self::_linkMethod('WhereIsNotTrue', $logic),
            'IS NOT FALSE'     => self::_linkMethod('WhereIsNotFalse', $logic),
            'IS NOT UNKNOWN'   => self::_linkMethod('WhereIsNotUnknown', $logic),
            '!=', '<>'         => self::_linkMethod('WhereNot', $logic),
            '<=>'              => self::_linkMethod('WhereSafeEqual', $logic),
            'REGEXP', 'RLIKE'  => self::_linkMethod('WhereRegexp', $logic),
            'NOT REGEXP', 'NOT RLIKE' => self::_linkMethod('WhereNotRegexp', $logic),
            '='                => self::_linkMethod('WhereEquals', $logic),
            '>'                => self::_linkMethod('WhereBigger', $logic),
            '<'                => self::_linkMethod('WhereLess', $logic),
            '>='               => self::_linkMethod('WhereBiggerOrEquals', $logic),
            '<='               => self::_linkMethod('WhereLessOrEquals', $logic),
            default            => null,
        };

        if ($method !== null) {
            if (in_array($operator, ['IS NULL', 'IS NOT NULL', 'IS TRUE', 'IS FALSE', 'IS UNKNOWN', 'IS NOT TRUE', 'IS NOT FALSE', 'IS NOT UNKNOWN'], true)) {
                $queryBuilder->{$method}($filter[0]);
                return;
            }

            if (in_array($operator, ['ILIKE', 'NOT ILIKE'], true)) {
                $queryBuilder->{$method}($filter[0], $filter[2], $filter[4]['wrap'] ?? false);
                return;
            }

            if (in_array($operator, ['IS DISTINCT FROM', 'IS NOT DISTINCT FROM'], true)) {
                $queryBuilder->{$method}($filter[0], $filter[2]);
                return;
            }

            if ($operator === 'BETWEEN' || $operator === 'NOT BETWEEN') {
                $queryBuilder->{$method}($filter[0], $filter[2][0], $filter[2][1]);
                return;
            }

            $queryBuilder->{$method}($filter[0], $filter[2]);
            return;
        }

        if ($logic === 'OR') {
            $queryBuilder->orWhere($filter[0], $filter[1], $filter[2]);
            return;
        }

        if ($logic === 'XOR') {
            $queryBuilder->xorWhere($filter[0], $filter[1], $filter[2]);
            return;
        }

        $queryBuilder->where($filter[0], $filter[1], $filter[2]);
    }

    private static function _linkMethod(string $method, string $logic): string
    {
        return match ($logic) {
            'OR'  => 'or' . $method,
            'XOR' => 'xor' . $method,
            default => lcfirst($method),
        };
    }

    protected static function _parseFilters(Arr|array $filters): array
    {
        if ($filters instanceof Arr) {
            $filters = (array) $filters;
        }

        $_filters = [];
        foreach ($filters as $key => $filter) {
            if (is_array($filter) || $filter instanceof Arr) {
                $filterCount = count($filter);
                if ($filterCount === 2) {
                    $operator = strtoupper((string) $filter[1]);
                    if (in_array($operator, ['IS NULL', 'IS NOT NULL'], true)) {
                        $_filters[] = [$filter[0], $operator, null, 'AND'];
                    } else {
                        $_filters[] = [$filter[0], '=', $filter[1], 'AND'];
                    }
                } elseif ($filterCount === 3) {
                    $_filters[] = [$filter[0], strtoupper($filter[1]), $filter[2], 'AND'];
                } elseif ($filterCount === 4) {
                    $_filters[] = [$filter[0], strtoupper($filter[1]), $filter[2], strtoupper($filter[3])];
                } else {
                    throw new Exception('Invalid filter data.');
                }
                continue;
            }

            $_filters[] = [$key, '=', $filter, 'AND'];
        }

        return $_filters;
    }

    public static function getDriver(): mixed
    {
        $class = static::class;

        if (isset(self::$_driver[$class]) === false || self::$_driver[$class] === null) {
            self::$_driver[$class] = self::$_data[$class]->model::getDriver();
        }

        return self::$_driver[$class];
    }

    protected static function _getIndexColumn()
    {
        $class = static::class;

        $metadata = self::$_data[$class]->model::getMetadata();

        $indexColumn = null;
        foreach ($metadata->column as $column) {
            if ($column->primary === true || $column->autoIncrement === true) {
                $indexColumn = $column;
                break;
            }
        }
        if ($indexColumn === null) {
            foreach ($metadata->column as $column) {
                if ($column->unique === true) {
                    $indexColumn = $column;
                    break;
                }
            }
        }

        if ($indexColumn === null) {
            throw new \Error('Missing primary or unique column in table ' . self::$_data[$class]->model::getTable() . ' using class ' . static::class . '.');
        }

        return $indexColumn;
    }
}

class_alias(Repository::class, 'Flames\\Repository');
