<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search\Security;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\Scout\ScoutPost;

/**
 * A Scout entity whose index lists only the team of the user (team_id = user id).
 */
class TenantScoutPost extends Entity
{
    public static string $model = ScoutPost::class;

    public static string $title = 'title';

    public static array $search = ['title'];

    public static bool $globallySearchable = true;

    public static function uriKey(): string
    {
        return 'tenant-scout-posts';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        Probe::count(static::uriKey());

        return $query->where('team_id', $request->user()?->getAuthIdentifier() ?? 0);
    }

    public function fields(Request $request): array
    {
        return [ID::make(), Text::make('Title')];
    }
}
