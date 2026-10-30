<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

/**
 * AuthoredPost searchable through the author's email (a relation search column).
 */
class SearchByAuthorPost extends AuthoredPost
{
    public static array $search = ['title', 'author.email'];
}
