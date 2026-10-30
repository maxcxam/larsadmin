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
        $value = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query->whereRaw(
            $query->getQuery()->getGrammar()->wrap($column)." {$operator} ? escape '!'",
            [$value],
            $boolean,
        );
    }
}
