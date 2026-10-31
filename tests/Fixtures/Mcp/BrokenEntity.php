<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Mcp;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Tests\Fixtures\Note;
use RuntimeException;

/**
 * An entity whose fields() and actions() throw with a canary in the message.
 */
class BrokenEntity extends Entity
{
    public static string $model = Note::class;

    public function fields(Request $request): array
    {
        throw new RuntimeException(McpSpy::MESSAGE);
    }

    public function actions(Request $request): array
    {
        throw new RuntimeException(McpSpy::MESSAGE);
    }
}
