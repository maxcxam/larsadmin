<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Files\Entities;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\File;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Image;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\Document as DocumentModel;

/**
 * Documents with a public cover image, private multiple attachments and a private secret.
 * Tests may swap the model, the fields ($factory) and the detail scope ($detailScope).
 */
class Document extends Entity
{
    public static string $model = DocumentModel::class;

    public static string $title = 'title';

    public static array $search = ['id', 'title'];

    /** @var Closure(Request): array<int, mixed>|null */
    public static ?Closure $factory = null;

    /** @var Closure(Builder): Builder|null */
    public static ?Closure $detailScope = null;

    public static function uriKey(): string
    {
        return 'documents';
    }

    public function fields(Request $request): array
    {
        if (static::$factory !== null) {
            return (static::$factory)($request);
        }

        return [
            ID::make(),
            Text::make('Title')->rules('required'),
            Image::make('Cover')->disk('public')->storeOriginalName('cover_name'),
            File::make('Attachments')->multiple()->maxFiles(3)->disk('local'),
            File::make('Secret')->disk('local')->hideFromIndex(),
        ];
    }

    public static function detailQuery(Request $request, Builder $query): Builder
    {
        return static::$detailScope !== null ? (static::$detailScope)($query) : $query;
    }

    public static function reset(): void
    {
        static::$model = DocumentModel::class;
        static::$factory = null;
        static::$detailScope = null;
    }
}
