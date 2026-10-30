<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Security;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

/**
 * A user model with two HasMany relations, for panels on one detail page.
 */
class Member extends User
{
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'author_id');
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(Post::class, 'author_id')->where('status', 'draft');
    }
}
