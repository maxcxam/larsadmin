<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search;

use Illuminate\Auth\Access\AuthorizationException;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

/**
 * Posts titled "Private ..." may not be viewed; "Throwing ..." throws an authorization error.
 */
class SearchPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Post $post): bool
    {
        if (str_starts_with($post->title, 'Throwing')) {
            throw new AuthorizationException;
        }

        return ! str_starts_with($post->title, 'Private');
    }
}
