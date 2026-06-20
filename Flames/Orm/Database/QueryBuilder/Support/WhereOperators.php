<?php
declare(strict_types=1);

namespace Flames\Orm\Database\QueryBuilder\Support;

use Flames\Collection\Arr;
use Flames\Orm\Database\QueryBuilder\WhereOperator;

/**
 * Extended WHERE helpers (where / orWhere / xorWhere families).
 *
 * @internal
 */
trait WhereOperators
{
    public function whereNotEqual(string $key, mixed $value): static
    {
        return $this->_where(WhereOperator::And, $key, '<>', $value);
    }

    public function orWhereNotEqual(string $key, mixed $value): static
    {
        return $this->_where(WhereOperator::Or, $key, '<>', $value);
    }

    public function xorWhereNotEqual(string $key, mixed $value): static
    {
        return $this->_where(WhereOperator::Xor, $key, '<>', $value);
    }

    public function whereNotBetween(string $key, mixed $fromOrRange, mixed $to = null): static
    {
        return $this->_whereBetween(WhereOperator::And, $key, $to === null ? $fromOrRange : [$fromOrRange, $to], true);
    }

    public function orWhereNotBetween(string $key, mixed $fromOrRange, mixed $to = null): static
    {
        return $this->_whereBetween(WhereOperator::Or, $key, $to === null ? $fromOrRange : [$fromOrRange, $to], true);
    }

    public function xorWhereNotBetween(string $key, mixed $fromOrRange, mixed $to = null): static
    {
        return $this->_whereBetween(WhereOperator::Xor, $key, $to === null ? $fromOrRange : [$fromOrRange, $to], true);
    }

    public function xorWhereBetween(string $key, mixed $fromOrRange, mixed $to = null): static
    {
        return $this->_whereBetween(WhereOperator::Xor, $key, $to === null ? $fromOrRange : [$fromOrRange, $to]);
    }

    public function xorWhereIn(string $key, mixed $value = null): static
    {
        return $this->_whereIn(WhereOperator::Xor, $key, $value);
    }

    public function xorWhereNotIn(string $key, mixed $value = null): static
    {
        return $this->_whereIn(WhereOperator::Xor, $key, $value, 'NOT IN');
    }

    public function xorWhereNull(string $key): static
    {
        return $this->_whereNull(WhereOperator::Xor, $key, false);
    }

    public function xorWhereNotNull(string $key): static
    {
        return $this->_whereNull(WhereOperator::Xor, $key, true);
    }

    public function xorWhereLike(string $key, mixed $value = null): static
    {
        return $this->_whereLike(WhereOperator::Xor, $key, $value);
    }

    public function xorWhereNotLike(string $key, mixed $value = null): static
    {
        return $this->_whereLike(WhereOperator::Xor, $key, $value, 'NOT LIKE');
    }

    public function xorWhereLikePattern(string $key, mixed $pattern): static
    {
        return $this->_whereLike(WhereOperator::Xor, $key, $pattern, 'LIKE_PATTERN');
    }

    public function xorWhereNotLikePattern(string $key, mixed $pattern): static
    {
        return $this->_whereLike(WhereOperator::Xor, $key, $pattern, 'NOT_LIKE_PATTERN');
    }

    public function xorWhereRegexp(string $key, mixed $pattern): static
    {
        return $this->_whereRegexp(WhereOperator::Xor, $key, $pattern, 'REGEXP');
    }

    public function xorWhereNotRegexp(string $key, mixed $pattern): static
    {
        return $this->_whereRegexp(WhereOperator::Xor, $key, $pattern, 'NOT REGEXP');
    }

    public function whereIsTrue(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::And, $key, 'IS TRUE');
    }

    public function orWhereIsTrue(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::Or, $key, 'IS TRUE');
    }

    public function xorWhereIsTrue(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::Xor, $key, 'IS TRUE');
    }

    public function whereIsFalse(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::And, $key, 'IS FALSE');
    }

    public function orWhereIsFalse(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::Or, $key, 'IS FALSE');
    }

    public function xorWhereIsFalse(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::Xor, $key, 'IS FALSE');
    }

    public function whereIsUnknown(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::And, $key, 'IS UNKNOWN');
    }

    public function orWhereIsUnknown(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::Or, $key, 'IS UNKNOWN');
    }

    public function xorWhereIsUnknown(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::Xor, $key, 'IS UNKNOWN');
    }

    public function whereIsNotTrue(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::And, $key, 'IS NOT TRUE');
    }

    public function orWhereIsNotTrue(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::Or, $key, 'IS NOT TRUE');
    }

    public function xorWhereIsNotTrue(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::Xor, $key, 'IS NOT TRUE');
    }

    public function whereIsNotFalse(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::And, $key, 'IS NOT FALSE');
    }

    public function orWhereIsNotFalse(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::Or, $key, 'IS NOT FALSE');
    }

    public function xorWhereIsNotFalse(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::Xor, $key, 'IS NOT FALSE');
    }

    public function whereIsNotUnknown(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::And, $key, 'IS NOT UNKNOWN');
    }

    public function orWhereIsNotUnknown(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::Or, $key, 'IS NOT UNKNOWN');
    }

    public function xorWhereIsNotUnknown(string $key): static
    {
        return $this->_whereBooleanState(WhereOperator::Xor, $key, 'IS NOT UNKNOWN');
    }

    public function whereStrcmp(string $left, string $right, string $compareOperator = '='): static
    {
        return $this->_whereStrcmp(WhereOperator::And, $left, $right, $compareOperator, false);
    }

    public function orWhereStrcmp(string $left, string $right, string $compareOperator = '='): static
    {
        return $this->_whereStrcmp(WhereOperator::Or, $left, $right, $compareOperator, false);
    }

    public function xorWhereStrcmp(string $left, string $right, string $compareOperator = '='): static
    {
        return $this->_whereStrcmp(WhereOperator::Xor, $left, $right, $compareOperator, false);
    }

    public function whereStrcmpValue(string $key, mixed $value, string $compareOperator = '='): static
    {
        return $this->_whereStrcmp(WhereOperator::And, $key, (string) $value, $compareOperator, true);
    }

    public function orWhereStrcmpValue(string $key, mixed $value, string $compareOperator = '='): static
    {
        return $this->_whereStrcmp(WhereOperator::Or, $key, (string) $value, $compareOperator, true);
    }

    public function xorWhereStrcmpValue(string $key, mixed $value, string $compareOperator = '='): static
    {
        return $this->_whereStrcmp(WhereOperator::Xor, $key, (string) $value, $compareOperator, true);
    }

    public function whereRegexpLike(string $key, mixed $pattern, ?string $flags = null): static
    {
        return $this->_whereRegexpLike(WhereOperator::And, $key, $pattern, $flags, false);
    }

    public function orWhereRegexpLike(string $key, mixed $pattern, ?string $flags = null): static
    {
        return $this->_whereRegexpLike(WhereOperator::Or, $key, $pattern, $flags, false);
    }

    public function xorWhereRegexpLike(string $key, mixed $pattern, ?string $flags = null): static
    {
        return $this->_whereRegexpLike(WhereOperator::Xor, $key, $pattern, $flags, false);
    }

    public function whereNotRegexpLike(string $key, mixed $pattern, ?string $flags = null): static
    {
        return $this->_whereRegexpLike(WhereOperator::And, $key, $pattern, $flags, true);
    }

    public function orWhereNotRegexpLike(string $key, mixed $pattern, ?string $flags = null): static
    {
        return $this->_whereRegexpLike(WhereOperator::Or, $key, $pattern, $flags, true);
    }

    public function xorWhereNotRegexpLike(string $key, mixed $pattern, ?string $flags = null): static
    {
        return $this->_whereRegexpLike(WhereOperator::Xor, $key, $pattern, $flags, true);
    }

    public function whereJsonExtract(
        string $key,
        string $path,
        string $operator,
        mixed $value,
        bool $unquoted = false,
    ): static {
        return $this->_whereJsonPath(WhereOperator::And, $key, $path, $operator, $value, $unquoted);
    }

    public function orWhereJsonExtract(
        string $key,
        string $path,
        string $operator,
        mixed $value,
        bool $unquoted = false,
    ): static {
        return $this->_whereJsonPath(WhereOperator::Or, $key, $path, $operator, $value, $unquoted);
    }

    public function xorWhereJsonExtract(
        string $key,
        string $path,
        string $operator,
        mixed $value,
        bool $unquoted = false,
    ): static {
        return $this->_whereJsonPath(WhereOperator::Xor, $key, $path, $operator, $value, $unquoted);
    }

    public function whereJsonExtractUnquoted(string $key, string $path, string $operator, mixed $value): static
    {
        return $this->whereJsonExtract($key, $path, $operator, $value, true);
    }

    public function orWhereJsonExtractUnquoted(string $key, string $path, string $operator, mixed $value): static
    {
        return $this->orWhereJsonExtract($key, $path, $operator, $value, true);
    }

    public function xorWhereJsonExtractUnquoted(string $key, string $path, string $operator, mixed $value): static
    {
        return $this->xorWhereJsonExtract($key, $path, $operator, $value, true);
    }

    public function whereFullText(
        array|string $columns,
        string $query,
        string $mode = 'BOOLEAN',
    ): static {
        return $this->_whereFullText(WhereOperator::And, $columns, $query, $mode);
    }

    public function orWhereFullText(
        array|string $columns,
        string $query,
        string $mode = 'BOOLEAN',
    ): static {
        return $this->_whereFullText(WhereOperator::Or, $columns, $query, $mode);
    }

    public function xorWhereFullText(
        array|string $columns,
        string $query,
        string $mode = 'BOOLEAN',
    ): static {
        return $this->_whereFullText(WhereOperator::Xor, $columns, $query, $mode);
    }

    public function whereBitwiseNot(
        string $key,
        string $compareOperator = '=',
        mixed $compareValue = 0,
    ): static {
        return $this->_whereBitwise(WhereOperator::And, $key, '~', 0, $compareOperator, $compareValue);
    }

    public function orWhereBitwiseNot(
        string $key,
        string $compareOperator = '=',
        mixed $compareValue = 0,
    ): static {
        return $this->_whereBitwise(WhereOperator::Or, $key, '~', 0, $compareOperator, $compareValue);
    }

    public function xorWhereBitwiseNot(
        string $key,
        string $compareOperator = '=',
        mixed $compareValue = 0,
    ): static {
        return $this->_whereBitwise(WhereOperator::Xor, $key, '~', 0, $compareOperator, $compareValue);
    }

    protected function _whereBooleanState(WhereOperator $operator, string $key, string $condition): static
    {
        return $this->_pushSimpleWhere($operator, $key, $condition, null);
    }

    protected function _whereStrcmp(
        WhereOperator $operator,
        string $left,
        string $right,
        string $compareOperator,
        bool $rightIsValue,
    ): static {
        return $this->_pushWhere($operator, [
            'type'              => \Flames\Orm\Database\QueryBuilder\WhereType::Strcmp,
            'left'              => $this->_resolveWhereKey($left),
            'right'             => $rightIsValue ? $right : $this->_resolveWhereKey($right),
            'rightIsValue'      => $rightIsValue,
            'condition'         => $compareOperator,
        ]);
    }

    protected function _whereRegexpLike(
        WhereOperator $operator,
        string $key,
        mixed $pattern,
        ?string $flags,
        bool $not,
    ): static {
        return $this->_pushWhere($operator, [
            'type'      => \Flames\Orm\Database\QueryBuilder\WhereType::RegexpLike,
            'key'       => $this->_resolveWhereKey($key),
            'pattern'   => $pattern,
            'flags'     => $flags,
            'not'       => $not,
        ]);
    }

    protected function _whereJsonPath(
        WhereOperator $operator,
        string $key,
        string $path,
        string $compareOperator,
        mixed $value,
        bool $unquoted,
    ): static {
        return $this->_pushWhere($operator, [
            'type'      => \Flames\Orm\Database\QueryBuilder\WhereType::JsonPath,
            'key'       => $this->_resolveWhereKey($key),
            'path'      => $path,
            'condition' => $compareOperator,
            'value'     => $value,
            'unquoted'  => $unquoted,
        ]);
    }

    protected function _whereFullText(
        WhereOperator $operator,
        array|string $columns,
        string $query,
        string $mode,
    ): static {
        $columns = is_string($columns) ? [$columns] : array_values($columns);

        return $this->_pushWhere($operator, [
            'type'    => \Flames\Orm\Database\QueryBuilder\WhereType::FullText,
            'columns' => array_map(fn (string $col): string => $this->_resolveWhereKey($col), $columns),
            'query'   => $query,
            'mode'    => strtoupper(trim($mode)),
        ]);
    }
}
