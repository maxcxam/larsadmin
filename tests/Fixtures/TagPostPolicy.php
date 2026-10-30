<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures;

/**
 * A Post policy with per-tag abilities (ADR-006) and switchable results.
 */
class TagPostPolicy extends PostPolicy
{
    public static bool $attachAny = true;

    public static bool $attach = true;

    public static bool $detach = true;

    public function attachAnyTag(User $user, Post $post): bool
    {
        return static::$attachAny;
    }

    public function attachTag(User $user, Post $post, Tag $tag): bool
    {
        return static::$attach;
    }

    public function detachTag(User $user, Post $post, Tag $tag): bool
    {
        return static::$detach;
    }
}
