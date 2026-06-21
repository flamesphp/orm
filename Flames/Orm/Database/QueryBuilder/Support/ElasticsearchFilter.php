<?php
declare(strict_types=1);

namespace Flames\Orm\Database\QueryBuilder\Support;

use Flames\Orm\Database\QueryBuilder\WhereOperator;
use Flames\Orm\Database\QueryBuilder\WhereType;
use Flames\Orm\Exception\UnsupportedQueryException;

/**
 * Converts ORM where clauses to Elasticsearch query DSL fragments.
 *
 * @internal
 */
final class ElasticsearchFilter
{
    /**
     * @param list<array<string, mixed>> $wheres
     * @return array<string, mixed>
     */
    public static function build(array $wheres, string $driver = 'elasticsearch'): array
    {
        if ($wheres === []) {
            return [];
        }

        $compiled = [];

        foreach ($wheres as $where) {
            $filter = self::_buildWhereCondition($where, $driver);
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
    private static function _buildWhereCondition(array $where, string $driver): array
    {
        return match ($where['type']) {
            WhereType::Simple      => self::_buildSimpleCondition($where, $driver),
            WhereType::Raw         => throw new UnsupportedQueryException('whereRaw', $driver),
            WhereType::Delegate,
            WhereType::NotDelegate => throw new UnsupportedQueryException('where(function)', $driver),
            WhereType::Expression  => throw new UnsupportedQueryException('whereExpression', $driver),
            WhereType::Column      => throw new UnsupportedQueryException('whereColumn', $driver),
            WhereType::Bitwise     => throw new UnsupportedQueryException('whereBitwise', $driver),
            WhereType::Strcmp      => throw new UnsupportedQueryException('whereStrcmp', $driver),
            WhereType::RegexpLike  => throw new UnsupportedQueryException('whereRegexpLike', $driver),
            WhereType::JsonPath    => throw new UnsupportedQueryException('whereJsonExtract', $driver),
            WhereType::FullText    => throw new UnsupportedQueryException('whereFullText', $driver),
            WhereType::Operator    => self::_buildOperatorCondition($where, $driver),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function _buildSimpleCondition(array $where, string $driver): array
    {
        $key   = $where['key'];
        $value = $where['value'];

        return match ($where['condition']) {
            'IN'           => self::_terms($key, (array) $value),
            'NOT IN'       => self::_boolMustNot(self::_terms($key, (array) $value)),
            'BETWEEN'      => self::_range($key, (array) $value, false),
            'NOT BETWEEN'  => self::_boolMustNot(self::_range($key, (array) $value, false)),
            'IS NULL'      => self::_boolMustNot(['exists' => ['field' => $key]]),
            'IS NOT NULL'  => ['exists' => ['field' => $key]],
            'LIKE', 'ILIKE' => self::_wildcard($key, (string) $value, false),
            'NOT LIKE', 'NOT ILIKE' => self::_wildcard($key, (string) $value, true),
            'IS DISTINCT FROM' => self::_term($key, $value, true),
            'IS NOT DISTINCT FROM' => self::_safeEqual($key, $value),
            '<=>'                   => self::_safeEqual($key, $value),
            '='                     => self::_equals($key, $value),
            '!=', '<>'              => self::_term($key, $value, true),
            '>'                     => ['range' => [$key => ['gt' => $value]]],
            '>='                    => ['range' => [$key => ['gte' => $value]]],
            '<'                     => ['range' => [$key => ['lt' => $value]]],
            '<='                    => ['range' => [$key => ['lte' => $value]]],
            default => throw new UnsupportedQueryException(
                'where("' . $key . '", "' . $where['condition'] . '", …)',
                $driver,
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function _buildOperatorCondition(array $where, string $driver): array
    {
        $opts     = $where['options'] ?? [];
        $operator = $where['compare'];
        $domain   = $opts['domain'] ?? null;
        $key      = (string) $where['left'];

        if (in_array($operator, ['~', '~*', '!~', '!~*'], true)) {
            throw new UnsupportedQueryException('whereRegex', $driver);
        }

        if ($domain === 'json' && in_array($operator, ['@>', '<@'], true)) {
            return self::_objectEquality($key, (array) $where['right']);
        }

        if ($domain !== null && $domain !== 'tsvector') {
            throw new UnsupportedQueryException('whereOperator(' . ($domain ?? $operator) . ')', $driver);
        }

        if ($domain === 'tsvector' && $operator === '@@') {
            return self::_wildcard($key, (string) $where['right'], false);
        }

        return match ($operator) {
            '=', '<=>'       => self::_equals($key, $where['right']),
            '!=', '<>'      => self::_term($key, $where['right'], true),
            '>'             => ['range' => [$key => ['gt' => $where['right']]]],
            '>='            => ['range' => [$key => ['gte' => $where['right']]]],
            '<'             => ['range' => [$key => ['lt' => $where['right']]]],
            '<='            => ['range' => [$key => ['lte' => $where['right']]]],
            default         => throw new UnsupportedQueryException('whereOperator(' . $operator . ')', $driver),
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

        return self::_term($key, $value, false);
    }

    /**
     * @return array<string, mixed>
     */
    private static function _safeEqual(string $key, mixed $value): array
    {
        if ($value === null) {
            return self::_boolMustNot(['exists' => ['field' => $key]]);
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
            return ['exists' => ['field' => $key]];
        }

        $parts = [];
        foreach ($value as $subKey => $subValue) {
            $path = $key . '.' . $subKey;
            if (is_array($subValue)) {
                $parts[] = self::_objectEquality($path, $subValue);
                continue;
            }

            $parts[] = self::_term($path, $subValue, false);
        }

        return self::_boolFilter($parts);
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private static function _range(string $key, array $values, bool $not): array
    {
        [$from, $to] = array_values($values);
        $query       = ['range' => [$key => ['gte' => $from, 'lte' => $to]]];

        return $not ? self::_boolMustNot($query) : $query;
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private static function _terms(string $key, array $values): array
    {
        if ($values === []) {
            return ['match_none' => new \stdClass()];
        }

        return ['terms' => [$key => array_values($values)]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function _term(string $key, mixed $value, bool $negated): array
    {
        $query = ['term' => [$key => $value]];

        return $negated ? self::_boolMustNot($query) : $query;
    }

    /**
     * @return array<string, mixed>
     */
    private static function _wildcard(string $key, string $value, bool $negated): array
    {
        $pattern = '*' . str_replace(['\\', '*', '?'], ['\\\\', '\\*', '\\?'], $value) . '*';
        $query   = [
            'wildcard' => [
                $key => [
                    'value'            => $pattern,
                    'case_insensitive' => true,
                ],
            ],
        ];

        return $negated ? self::_boolMustNot($query) : $query;
    }

    /**
     * @param list<array<string, mixed>> $filters
     * @return array<string, mixed>
     */
    private static function _boolFilter(array $filters): array
    {
        if (count($filters) === 1) {
            return $filters[0];
        }

        return ['bool' => ['filter' => $filters]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function _boolMustNot(array $query): array
    {
        return ['bool' => ['must_not' => [$query]]];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    private static function _combineFilters(array $left, array $right, WhereOperator $operator): array
    {
        return match ($operator) {
            WhereOperator::And => ['bool' => ['filter' => [$left, $right]]],
            WhereOperator::Or  => [
                'bool' => [
                    'should'               => [$left, $right],
                    'minimum_should_match' => 1,
                ],
            ],
            WhereOperator::Xor => [
                'bool' => [
                    'should' => [
                        ['bool' => ['filter' => [$left]]],
                        ['bool' => ['filter' => [$right]]],
                    ],
                    'must_not' => [
                        ['bool' => ['filter' => [$left, $right]]],
                    ],
                    'minimum_should_match' => 1,
                ],
            ],
        };
    }
}
