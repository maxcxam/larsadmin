<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures;

class DenyTagPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Tag $tag): bool
    {
        return false;
    }
}
