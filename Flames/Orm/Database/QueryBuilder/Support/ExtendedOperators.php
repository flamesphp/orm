<?php
declare(strict_types=1);

namespace Flames\Orm\Database\QueryBuilder\Support;

use Flames\Orm\Database\QueryBuilder\WhereOperator;
use Flames\Orm\Database\QueryBuilder\WhereType;

/**
 * PostgreSQL-style operators with cross-driver fallbacks.
 *
 * @internal
 */
trait ExtendedOperators
{
    public function whereOperator(string $left, string $operator, mixed $right, array $options = []): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $left, $operator, $right, $options);
    }

    public function orWhereOperator(string $left, string $operator, mixed $right, array $options = []): static
    {
        return $this->_whereExtendedOp(WhereOperator::Or, $left, $operator, $right, $options);
    }

    public function xorWhereOperator(string $left, string $operator, mixed $right, array $options = []): static
    {
        return $this->_whereExtendedOp(WhereOperator::Xor, $left, $operator, $right, $options);
    }

    public function whereIsDistinctFrom(string $key, mixed $value): static
    {
        return $this->_pushSimpleWhere(WhereOperator::And, $key, 'IS DISTINCT FROM', $value);
    }

    public function orWhereIsDistinctFrom(string $key, mixed $value): static
    {
        return $this->_pushSimpleWhere(WhereOperator::Or, $key, 'IS DISTINCT FROM', $value);
    }

    public function xorWhereIsDistinctFrom(string $key, mixed $value): static
    {
        return $this->_pushSimpleWhere(WhereOperator::Xor, $key, 'IS DISTINCT FROM', $value);
    }

    public function whereIsNotDistinctFrom(string $key, mixed $value): static
    {
        return $this->_pushSimpleWhere(WhereOperator::And, $key, 'IS NOT DISTINCT FROM', $value);
    }

    public function orWhereIsNotDistinctFrom(string $key, mixed $value): static
    {
        return $this->_pushSimpleWhere(WhereOperator::Or, $key, 'IS NOT DISTINCT FROM', $value);
    }

    public function xorWhereIsNotDistinctFrom(string $key, mixed $value): static
    {
        return $this->_pushSimpleWhere(WhereOperator::Xor, $key, 'IS NOT DISTINCT FROM', $value);
    }

    public function whereILike(string $key, mixed $pattern, bool $wrap = false): static
    {
        return $this->_whereILike(WhereOperator::And, $key, $pattern, false, $wrap);
    }

    public function orWhereILike(string $key, mixed $pattern, bool $wrap = false): static
    {
        return $this->_whereILike(WhereOperator::Or, $key, $pattern, false, $wrap);
    }

    public function xorWhereILike(string $key, mixed $pattern, bool $wrap = false): static
    {
        return $this->_whereILike(WhereOperator::Xor, $key, $pattern, false, $wrap);
    }

    public function whereNotILike(string $key, mixed $pattern, bool $wrap = false): static
    {
        return $this->_whereILike(WhereOperator::And, $key, $pattern, true, $wrap);
    }

    public function orWhereNotILike(string $key, mixed $pattern, bool $wrap = false): static
    {
        return $this->_whereILike(WhereOperator::Or, $key, $pattern, true, $wrap);
    }

    public function xorWhereNotILike(string $key, mixed $pattern, bool $wrap = false): static
    {
        return $this->_whereILike(WhereOperator::Xor, $key, $pattern, true, $wrap);
    }

    public function whereRegex(string $key, mixed $pattern, bool $caseInsensitive = false): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, $caseInsensitive ? '~*' : '~', $pattern);
    }

    public function orWhereRegex(string $key, mixed $pattern, bool $caseInsensitive = false): static
    {
        return $this->_whereExtendedOp(WhereOperator::Or, $key, $caseInsensitive ? '~*' : '~', $pattern);
    }

    public function xorWhereRegex(string $key, mixed $pattern, bool $caseInsensitive = false): static
    {
        return $this->_whereExtendedOp(WhereOperator::Xor, $key, $caseInsensitive ? '~*' : '~', $pattern);
    }

    public function whereNotRegex(string $key, mixed $pattern, bool $caseInsensitive = false): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, $caseInsensitive ? '!~*' : '!~', $pattern);
    }

    public function orWhereNotRegex(string $key, mixed $pattern, bool $caseInsensitive = false): static
    {
        return $this->_whereExtendedOp(WhereOperator::Or, $key, $caseInsensitive ? '!~*' : '!~', $pattern);
    }

    public function xorWhereNotRegex(string $key, mixed $pattern, bool $caseInsensitive = false): static
    {
        return $this->_whereExtendedOp(WhereOperator::Xor, $key, $caseInsensitive ? '!~*' : '!~', $pattern);
    }

    public function whereArrayOverlaps(string $key, array $value): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, '&&', $value, ['domain' => 'array']);
    }

    public function orWhereArrayOverlaps(string $key, array $value): static
    {
        return $this->_whereExtendedOp(WhereOperator::Or, $key, '&&', $value, ['domain' => 'array']);
    }

    public function whereArrayContains(string $key, array $value): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, '@>', $value, ['domain' => 'array']);
    }

    public function orWhereArrayContains(string $key, array $value): static
    {
        return $this->_whereExtendedOp(WhereOperator::Or, $key, '@>', $value, ['domain' => 'array']);
    }

    public function whereArrayContainedBy(string $key, array $value): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, '<@', $value, ['domain' => 'array']);
    }

    public function orWhereArrayContainedBy(string $key, array $value): static
    {
        return $this->_whereExtendedOp(WhereOperator::Or, $key, '<@', $value, ['domain' => 'array']);
    }

    public function whereJsonContains(string $key, mixed $document): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, '@>', $document, ['domain' => 'json']);
    }

    public function orWhereJsonContains(string $key, mixed $document): static
    {
        return $this->_whereExtendedOp(WhereOperator::Or, $key, '@>', $document, ['domain' => 'json']);
    }

    public function whereJsonContainedBy(string $key, mixed $document): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, '<@', $document, ['domain' => 'json']);
    }

    public function orWhereJsonContainedBy(string $key, mixed $document): static
    {
        return $this->_whereExtendedOp(WhereOperator::Or, $key, '<@', $document, ['domain' => 'json']);
    }

    public function whereJsonHasKey(string $key, string $jsonKey): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, '?', $jsonKey, ['domain' => 'json']);
    }

    public function orWhereJsonHasKey(string $key, string $jsonKey): static
    {
        return $this->_whereExtendedOp(WhereOperator::Or, $key, '?', $jsonKey, ['domain' => 'json']);
    }

    public function whereJsonHasAllKeys(string $key, array $jsonKeys): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, '?&', array_values($jsonKeys), ['domain' => 'json']);
    }

    public function orWhereJsonHasAllKeys(string $key, array $jsonKeys): static
    {
        return $this->_whereExtendedOp(WhereOperator::Or, $key, '?&', array_values($jsonKeys), ['domain' => 'json']);
    }

    public function whereJsonHasAnyKey(string $key, array $jsonKeys): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, '?|', array_values($jsonKeys), ['domain' => 'json']);
    }

    public function orWhereJsonHasAnyKey(string $key, array $jsonKeys): static
    {
        return $this->_whereExtendedOp(WhereOperator::Or, $key, '?|', array_values($jsonKeys), ['domain' => 'json']);
    }

    public function whereJsonPathNested(
        string $key,
        array|string $path,
        string $operator,
        mixed $value,
        bool $asText = false,
    ): static {
        return $this->_whereExtendedOp(WhereOperator::And, $key, $asText ? '#>>' : '#>', $value, [
            'domain' => 'json',
            'path'   => $path,
            'compare'=> $operator,
        ]);
    }

    public function orWhereJsonPathNested(
        string $key,
        array|string $path,
        string $operator,
        mixed $value,
        bool $asText = false,
    ): static {
        return $this->_whereExtendedOp(WhereOperator::Or, $key, $asText ? '#>>' : '#>', $value, [
            'domain' => 'json',
            'path'   => $path,
            'compare'=> $operator,
        ]);
    }

    public function whereTsMatch(string $column, string $query): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $column, '@@', $query, ['domain' => 'tsvector']);
    }

    public function orWhereTsMatch(string $column, string $query): static
    {
        return $this->_whereExtendedOp(WhereOperator::Or, $column, '@@', $query, ['domain' => 'tsvector']);
    }

    public function whereTsQueryAnd(string $leftQuery, string $rightQuery): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $leftQuery, '&&', $rightQuery, [
            'domain'       => 'tsquery',
            'leftIsValue'  => true,
        ]);
    }

    public function whereTsQueryOr(string $leftQuery, string $rightQuery): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $leftQuery, '||', $rightQuery, [
            'domain'       => 'tsquery',
            'leftIsValue'  => true,
        ]);
    }

    public function whereTsQueryNot(string $query): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $query, '!!', null, [
            'domain'      => 'tsquery',
            'leftIsValue' => true,
            'unary'       => true,
        ]);
    }

    public function whereNetworkContainedIn(string $key, mixed $network, bool $orEqual = false): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, $orEqual ? '<<=' : '<<', $network, ['domain' => 'network']);
    }

    public function whereNetworkContains(string $key, mixed $network, bool $orEqual = false): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, $orEqual ? '>>=' : '>>', $network, ['domain' => 'network']);
    }

    public function whereNetworkOverlaps(string $key, mixed $network): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, '&&', $network, ['domain' => 'network']);
    }

    public function whereRangeStrictlyLeft(string $key, mixed $range): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, '<<', $range, ['domain' => 'range']);
    }

    public function whereRangeStrictlyRight(string $key, mixed $range): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, '>>', $range, ['domain' => 'range']);
    }

    public function whereRangeNotExtendRight(string $key, mixed $range): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, '&<', $range, ['domain' => 'range']);
    }

    public function whereRangeNotExtendLeft(string $key, mixed $range): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, '&>', $range, ['domain' => 'range']);
    }

    public function whereRangeAdjacent(string $key, mixed $range): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, '-|-', $range, ['domain' => 'range']);
    }

    public function whereConcat(string $key, mixed $append, mixed $equals, string $compareOperator = '='): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $key, '||', $append, [
            'domain'        => 'concat',
            'compare'       => $compareOperator,
            'compareValue'  => $equals,
        ]);
    }

    public function whereCompareExpression(string $leftExpression, string $operator, mixed $right): static
    {
        return $this->_whereExtendedOp(WhereOperator::And, $leftExpression, $operator, $right, [
            'leftIsExpression' => true,
        ]);
    }

    protected function _whereILike(
        WhereOperator $logic,
        string $key,
        mixed $pattern,
        bool $not,
        bool $wrap,
    ): static {
        return $this->_pushSimpleWhere(
            $logic,
            $key,
            $not ? 'NOT ILIKE' : 'ILIKE',
            $pattern,
            ['wrap' => $wrap],
        );
    }

    protected function _whereExtendedOp(
        WhereOperator $logic,
        string $left,
        string $operator,
        mixed $right,
        array $options = [],
    ): static {
        return $this->_pushWhere($logic, [
            'type'     => WhereType::Operator,
            'left'     => ($options['leftIsValue'] ?? false) || ($options['leftIsExpression'] ?? false)
                ? $left
                : $this->_resolveWhereKey($left),
            'compare'  => $operator,
            'right'    => $right,
            'options'  => $options,
        ]);
    }
}
