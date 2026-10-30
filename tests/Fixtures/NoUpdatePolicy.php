<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures;

class NoUpdatePolicy extends PostPolicy
{
    public function update(User $user, Post $post): bool
    {
        return false;
    }
}
