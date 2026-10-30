<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Closure;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Tests\Fixtures\Post as PostModel;

/**
 * An entity whose fields and default sort are set per test.
 */
class ConfigurablePost extends Entity
{
    public static string $model = PostModel::class;

    public static string $title = 'title';

    public static array $search = ['id', 'title', 'subtitle'];

    /** @var Closure(Request): array<int, mixed>|null */
    public static ?Closure $factory = null;

    public function fields(Request $request): array
    {
        return (static::$factory)($request);
    }

    public static function reset(): void
    {
        static::$factory = null;
        static::$sort = [];
    }
}
