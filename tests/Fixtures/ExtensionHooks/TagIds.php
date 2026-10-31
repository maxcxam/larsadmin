<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\ExtensionHooks;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Files\FileJournal;
use RuntimeException;

/**
 * An extension field without a column, built only on the stable hooks (ADR-035): its value lives in the
 * post_tag table, it is audited through auditValue(), and its deferred closure writes a file (an "export")
 * that it registers in the save's journal, replacing the previous one.
 */
class TagIds extends Field
{
    public string $component = 'acme/tag-ids';

    /** Throw in the deferred closure, after the file was written (rollback tests). */
    public static bool $fail = false;

    /** The entity the field was bound to (bindTo tests). */
    public ?string $boundTo = null;

    public static function reset(): void
    {
        static::$fail = false;
    }

    public function bindTo(Request $request, string $entity): void
    {
        $this->boundTo = $entity;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'boundTo' => $this->boundTo];
    }

    public function hasAuditValue(): bool
    {
        return true;
    }

    public function auditValue(Model $record): mixed
    {
        return DB::table('post_tag')->where('post_id', $record->getKey())->orderBy('tag_id')->pluck('tag_id')->map(fn ($id) => (int) $id)->all();
    }

    public function fillInto(Request $request, Model $record, FileJournal $journal): ?Closure
    {
        $ids = $request->input($this->attribute);

        if (! is_array($ids)) {
            return null;
        }

        return function (Model $record) use ($ids, $journal) {
            DB::table('post_tag')->where('post_id', $record->getKey())->delete();
            DB::table('post_tag')->insert(array_map(fn ($id) => ['post_id' => $record->getKey(), 'tag_id' => (int) $id], $ids));

            $path = 'exports/'.$record->getKey().'-'.uniqid().'.txt';
            Storage::disk('local')->put($path, implode(',', $ids));
            $journal->created('local', $path);

            foreach (Storage::disk('local')->files('exports') as $previous) {
                if ($previous !== $path && str_starts_with(basename($previous), $record->getKey().'-')) {
                    $journal->obsolete('local', $previous);
                }
            }

            if (static::$fail) {
                throw new RuntimeException('The export failed.');
            }
        };
    }
}
