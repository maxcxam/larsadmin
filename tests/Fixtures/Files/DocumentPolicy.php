<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Files;

/**
 * Allows every ability unless a test turns it off in $denied.
 */
class DocumentPolicy
{
    /** @var array<int, string> */
    public static array $denied = [];

    public function viewAny(): bool
    {
        return ! in_array('viewAny', static::$denied, true);
    }

    public function view(): bool
    {
        return ! in_array('view', static::$denied, true);
    }

    public function create(): bool
    {
        return ! in_array('create', static::$denied, true);
    }

    public function update(): bool
    {
        return ! in_array('update', static::$denied, true);
    }

    public function delete(): bool
    {
        return ! in_array('delete', static::$denied, true);
    }
}
