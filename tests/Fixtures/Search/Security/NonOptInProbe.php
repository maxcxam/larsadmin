<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search\Security;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use RuntimeException;

/**
 * Searchable on its index but not opted in: the global search must never touch it.
 */
class NonOptInProbe extends Entity
{
    public static string $model = Post::class;

    public static string $title = 'title';

    public static array $search = ['id', 'title'];

    public static function uriKey(): string
    {
        return 'internal-ledger';
    }

    public static function label(): string
    {
        return 'Internal ledger';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        Probe::count(static::uriKey());

        throw new RuntimeException('probe secret: index query of '.static::uriKey());
    }

    public function fields(Request $request): array
    {
        return [ID::make(), Text::make('Title')];
    }
}
