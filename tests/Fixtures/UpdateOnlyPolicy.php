<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures;

class UpdateOnlyPolicy
{
    public function update(User $user, Post $post): bool
    {
        return true;
    }
}
