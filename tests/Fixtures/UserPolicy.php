<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures;

class UserPolicy
{
    public static bool $viewAny = true;

    public static bool $view = true;

    public function viewAny(User $user): bool
    {
        return static::$viewAny;
    }

    public function view(User $user, User $model): bool
    {
        return static::$view;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, User $model): bool
    {
        return true;
    }
}
