<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Security;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\HasMany;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;

/**
 * Users whose name starts with "Hidden" are outside every scope. Carries two HasMany panels.
 */
class SecMember extends Entity
{
    public static string $model = Member::class;

    public static string $title = 'name';

    public static array $search = ['id', 'name'];

    public static function uriKey(): string
    {
        return 'users';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('name', 'not like', 'Hidden%');
    }

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Name')->sortable()->rules('required'),
            HasMany::make('Posts', 'posts', SecPost::class)->perPage(10),
            HasMany::make('Drafts', 'drafts', SecPost::class)->perPage(10),
        ];
    }
}
