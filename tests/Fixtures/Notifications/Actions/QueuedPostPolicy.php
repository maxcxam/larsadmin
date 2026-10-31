<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions;

use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

/**
 * Everything is allowed, except updating posts whose title starts with "locked" and whatever the
 * static switches revoke (to revoke rights between dispatch and execution).
 */
class QueuedPostPolicy
{
    public static bool $viewAny = true;

    public static bool $update = true;

    public static bool $create = true;

    public function viewAny(User $user): bool
    {
        return static::$viewAny;
    }

    public function view(User $user, Post $post): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return static::$create;
    }

    public function update(User $user, Post $post): bool
    {
        return static::$update && ! str_starts_with($post->title, 'locked');
    }

    public function delete(User $user, Post $post): bool
    {
        return false;
    }

    public static function reset(): void
    {
        static::$viewAny = true;
        static::$update = true;
        static::$create = true;
    }
}
