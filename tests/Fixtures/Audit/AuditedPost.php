<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Audit;

use Closure;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\Boolean;
use Maxcxam\Larsadmin\Fields\Date;
use Maxcxam\Larsadmin\Fields\DateTime;
use Maxcxam\Larsadmin\Fields\File;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Password;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Fields\Textarea;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\User;

/**
 * Every kind of audited value: text, enum select, boolean, dates, BelongsTo, files and the four masking rules.
 */
class AuditedPost extends Entity
{
    public static string $model = AuditPost::class;

    public static string $title = 'title';

    /**
     * Runs after the record is saved, inside the transaction (fn (AuditPost $record)).
     */
    public static ?Closure $afterSave = null;

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Title')->rules('required')->fillUsing(function (Request $request, $record, string $attribute) {
                if ($request->exists($attribute)) {
                    $record->{$attribute} = $request->input($attribute);
                }

                return static::$afterSave;
            }),
            Textarea::make('Body')->nullable(),
            Select::make('Status')->options(['draft' => 'Draft', 'live' => 'Live']),
            Boolean::make('Published'),
            DateTime::make('Published at', 'published_at')->nullable(),
            Date::make('Published on', 'published_on')->nullable(),
            BelongsTo::make('Author', 'author', User::class)->nullable(),
            File::make('Cover')->nullable(),
            File::make('Attachments')->multiple()->nullable(),
            Password::make('Secret')->sensitive(false),
            Text::make('Note')->nullable()->sensitive(),
            Text::make('Token')->nullable(),
            Textarea::make('Private')->nullable(),
            Text::make('Computed', 'computed')->exceptOnForms(),
        ];
    }

    public static function reset(): void
    {
        static::$afterSave = null;
        static::$auditable = true;
    }
}
