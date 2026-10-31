<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\ExtensionHooks;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Fields\Textarea;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

/**
 * An entity with an extension field (TagIds) and fields for findField(): read-only, hidden on update,
 * invisible, and visible only for some records.
 */
class HookedPost extends Entity
{
    public static string $model = Post::class;

    /** Mask the tag ids in the audit log. */
    public static bool $sensitiveTags = false;

    public static function reset(): void
    {
        static::$sensitiveTags = false;
        TagIds::reset();
    }

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Title'),
            Text::make('Status'),
            TagIds::make('Tags', 'tag_ids')->sensitive(static::$sensitiveTags),
            Text::make('Subtitle')->readonly(),
            Textarea::make('Body')->hideWhenUpdating(),
            Text::make('Secret', 'views')->canSee(fn () => false),
            Text::make('Live note', 'published_at')->showOnDetail(fn (Request $request, $record) => $record?->status === 'live'),
        ];
    }
}
