<?php
declare(strict_types=1);

namespace Flames\Orm\Database\QueryBuilder\Support;

use Flames\Orm\Database\QueryBuilder\WhereOperator;
use Flames\Orm\Database\QueryBuilder\WhereType;
use Flames\Orm\Exception\UnsupportedQueryException;

/**
 * Converts ORM where clauses to MongoDB filter documents.
 *
 * @internal
 */
final class MongoFilter
{
    private const DRIVER = 'mongodb';

    /**
     * @param list<array<string, mixed>> $wheres
     * @return array<string, mixed>
     */
    public static function build(array $wheres): array
    {
        if ($wheres === []) {
            return [];
        }

        $compiled = [];

        foreach ($wheres as $where) {
            $filter = self::_buildWhereCondition($where);
            if ($filter === []) {
                continue;
            }

            $compiled[] = [
                'filter'   => $filter,
                'operator' => $where['operator'],
            ];
        }

        if ($compiled === []) {
            return [];
        }

        $result = $compiled[0]['filter'];

        for ($index = 1, $count = count($compiled); $index < $count; $index++) {
            $result = self::_combineFilters(
                $result,
                $compiled[$index]['filter'],
                $compiled[$index]['operator'],
            );
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function _buildWhereCondition(array $where): array
    {
        return match ($where['type']) {
            WhereType::Simple      => self::_buildSimpleCondition($where),
            WhereType::Raw         => throw new UnsupportedQueryException('whereRaw', self::DRIVER),
            WhereType::Delegate,
            WhereType::NotDelegate => throw new UnsupportedQueryException('where(function)', self::DRIVER),
            WhereType::Expression  => throw new UnsupportedQueryException('whereExpression', self::DRIVER),
            WhereType::Column      => throw new UnsupportedQueryException('whereColumn', self::DRIVER),
            WhereType::Bitwise     => throw new UnsupportedQueryException('whereBitwise', self::DRIVER),
            WhereType::Strcmp      => throw new UnsupportedQueryException('whereStrcmp', self::DRIVER),
            WhereType::RegexpLike  => throw new UnsupportedQueryException('whereRegexpLike', self::DRIVER),
            WhereType::JsonPath    => throw new UnsupportedQueryException('whereJsonExtract', self::DRIVER),
            WhereType::FullText    => throw new UnsupportedQueryException('whereFullText', self::DRIVER),
            WhereType::Operator    => self::_buildOperatorCondition($where),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function _buildSimpleCondition(array $where): array
    {
        $key   = $where['key'];
        $value = $where['value'];

        return match ($where['condition']) {
            'IN'           => [$key => ['$in' => array_values((array) $value)]],
            'NOT IN'       => [$key => ['$nin' => array_values((array) $value)]],
            'BETWEEN'      => self::_between($key, (array) $value, false),
            'NOT BETWEEN'  => ['$nor' => [self::_between($key, (array) $value, false)]],
            'IS NULL'      => [$key => null],
            'IS NOT NULL'  => [$key => ['$ne' => null, '$exists' => true]],
            'LIKE', 'ILIKE' => [$key => ['$regex' => self::_likePattern((string) $value), '$options' => 'i']],
            'NOT LIKE', 'NOT ILIKE' => [$key => ['$not' => ['$regex' => self::_likePattern((string) $value), '$options' => 'i']]],
            'IS DISTINCT FROM' => [$key => ['$ne' => $value]],
            'IS NOT DISTINCT FROM' => self::_safeEqual($key, $value),
            '<=>'                   => self::_safeEqual($key, $value),
            '='                     => self::_equals($key, $value),
            '!=' , '<>' => [$key => ['$ne' => $value]],
            '>'  => [$key => ['$gt' => $value]],
            '>=' => [$key => ['$gte' => $value]],
            '<'  => [$key => ['$lt' => $value]],
            '<=' => [$key => ['$lte' => $value]],
            default => throw new UnsupportedQueryException(
                'where("' . $key . '", "' . $where['condition'] . '", …)',
                self::DRIVER,
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function _buildOperatorCondition(array $where): array
    {
        $opts     = $where['options'] ?? [];
        $operator = $where['compare'];
        $domain   = $opts['domain'] ?? null;
        $key      = (string) $where['left'];

        if (in_array($operator, ['~', '~*', '!~', '!~*'], true)) {
            $options = str_contains($operator, '*') ? 'i' : '';
            $regex   = ['$regex' => (string) $where['right']];
            if ($options !== '') {
                $regex['$options'] = $options;
            }

            return str_starts_with($operator, '!')
                ? [$key => ['$not' => $regex]]
                : [$key => $regex];
        }

        if ($domain === 'json' && in_array($operator, ['@>', '<@'], true)) {
            return self::_objectEquality($key, (array) $where['right']);
        }

        if ($domain !== null && $domain !== 'tsvector') {
            throw new UnsupportedQueryException('whereOperator(' . ($domain ?? $operator) . ')', self::DRIVER);
        }

        if ($domain === 'tsvector' && $operator === '@@') {
            return [$key => ['$regex' => preg_quote((string) $where['right'], '/'), '$options' => 'i']];
        }

        return match ($operator) {
            '=', '<=>'       => self::_equals($key, $where['right']),
            '!=', '<>'      => [$key => ['$ne' => $where['right']]],
            '>'             => [$key => ['$gt' => $where['right']]],
            '>='            => [$key => ['$gte' => $where['right']]],
            '<'             => [$key => ['$lt' => $where['right']]],
            '<='            => [$key => ['$lte' => $where['right']]],
            default         => throw new UnsupportedQueryException('whereOperator(' . $operator . ')', self::DRIVER),
        };
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function _equals(string $key, mixed $value): array
    {
        if (is_array($value)) {
            return self::_objectEquality($key, $value);
        }

        return [$key => $value];
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function _safeEqual(string $key, mixed $value): array
    {
        if ($value === null) {
            return [$key => null];
        }

        return self::_equals($key, $value);
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function _objectEquality(string $key, array $value): array
    {
        if ($value === []) {
            return [$key => ['$exists' => true, '$eq' => new \stdClass()]];
        }

        $parts = [];
        foreach ($value as $subKey => $subValue) {
            $path = $key . '.' . $subKey;
            if (is_array($subValue)) {
                $parts[] = self::_objectEquality($path, $subValue);
                continue;
            }

            $parts[] = [$path => $subValue];
        }

        return ['$and' => $parts];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private static function _between(string $key, array $values, bool $not): array
    {
        [$from, $to] = array_values($values);

        return [
            '$and' => [
                [$key => ['$gte' => $from]],
                [$key => ['$lte' => $to]],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    private static function _combineFilters(array $left, array $right, WhereOperator $operator): array
    {
        return match ($operator) {
            WhereOperator::And => ['$and' => [$left, $right]],
            WhereOperator::Or  => ['$or' => [$left, $right]],
            WhereOperator::Xor => [
                '$and' => [
                    ['$or' => [$left, $right]],
                    ['$nor' => [['$and' => [$left, $right]]]],
                ],
            ],
        };
    }

    private static function _likePattern(string $value): string
    {
        $quoted = preg_quote($value, '/');

        return str_replace(['%', '_'], ['.*', '.'], $quoted);
    }
}
