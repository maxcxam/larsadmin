<?php

namespace Maxcxam\Larsadmin\Support;

use Illuminate\Database\Eloquent\Builder;

final class Like
{
    /**
     * Add a "contains" LIKE constraint with the wildcards in the term escaped.
     *
     * "!" is the escape character because, unlike a backslash, it parses the
     * same on sqlite, mysql/mariadb and pgsql. pgsql uses ILIKE to stay
     * case-insensitive.
     */
    public static function where(Builder $query, string $column, string $term, string $boolean = 'or'): void
    {
        $query->whereRaw(
            self::expression($query, $column),
            ['%'.self::escape($term).'%'],
            $boolean,
        );
    }

    /**
     * Order rows whose column starts with the term (case-insensitively, wildcards escaped) first.
     */
    public static function orderByPrefix(Builder $query, string $column, string $term): void
    {
        $query->orderByRaw(
            'case when '.self::expression($query, $column).' then 0 else 1 end',
            [self::escape($term).'%'],
        );
    }

    private static function escape(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }

    private static function expression(Builder $query, string $column): string
    {
        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query->getQuery()->getGrammar()->wrap($column)." {$operator} ? escape '!'";
    }
}
