<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Audit;

/**
 * A switchable policy for the audit fixtures.
 */
class AuditPostPolicy
{
    public static bool $viewAny = true;

    public static bool $view = true;

    public function viewAny($user): bool
    {
        return static::$viewAny;
    }

    public function view($user, $post): bool
    {
        return static::$view;
    }

    public function create($user): bool
    {
        return true;
    }

    public function update($user, $post): bool
    {
        return true;
    }

    public function delete($user, $post): bool
    {
        return true;
    }

    public static function reset(): void
    {
        static::$viewAny = true;
        static::$view = true;
    }
}
