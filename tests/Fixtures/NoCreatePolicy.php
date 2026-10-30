<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures;

class NoCreatePolicy extends PostPolicy
{
    public function create(User $user): bool
    {
        return false;
    }
}
