<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures;

class DenyBeforePolicy extends PostPolicy
{
    public function before(User $user): bool
    {
        return false;
    }
}
