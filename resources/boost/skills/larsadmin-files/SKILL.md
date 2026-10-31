---
name: larsadmin-files
description: "Larsadmin files and images: File and Image fields, multiple files, disks, public and private files, signed download URLs, automatic deletion of replaced files, the file journal and the public FileSafety upload and download checks for extension fields. Use when adding uploads, attachments or images to an entity, or writing a field or route that stores or serves files, in a Laravel app that uses maxcxam/larsadmin."
license: MIT
metadata:
  package: maxcxam/larsadmin
  version: "1.6"
---

# Larsadmin files

## When to use

- Add an upload, attachment list or image to an Entity form.
- Choose a disk, make files private, tune size and type limits.
- Delete records with files from your own code or action.
- Write an extension field or route that stores or serves files: use `Files\FileSafety` and
  `Files\FileJournal` instead of your own checks.

## Quick start

```php
// Migration: one string column per single file, a json column for multiple().
// $table->string('cover')->nullable();
// $table->string('contract')->nullable();
// $table->string('contract_name')->nullable();
// $table->json('attachments')->nullable();   // model: casts 'attachments' => 'array'

namespace App\Larsadmin;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\File;
use Maxcxam\Larsadmin\Fields\Image;

class Post extends Entity
{
    public static string $model = \App\Models\Post::class;

    public function fields(Request $request): array
    {
        return [
            Image::make('Cover', 'cover')->disk('public')->maxSize(2048),          // needs storage:link
            File::make('Contract', 'contract')->types(['pdf'])->maxSize(5120)
                ->storeOriginalName('contract_name'),                              // private: files.disk
            File::make('Attachments', 'attachments')->multiple()->maxFiles(5)
                ->types(['pdf', 'docx', 'xlsx']),
        ];
    }
}
```

No disk means `config('larsadmin.files.disk')` (`LARSADMIN_FILES_DISK`, `local` by default), which is
private: the panel issues signed links and re-checks rights on every download.

## Recipes

### Public vs private

- Public: `->disk('public')` (or `->public()`), served by the web server via `Storage::url()`; run
  `php artisan storage:link` and set a correct `APP_URL`.
- Private on `local`: a signed panel route (`larsadmin.files.show`), checked on every request.
- Private on S3: `->disk('s3')` gives temporary URLs, checked only when issued. Use a `local`-driver disk
  when every download must be re-authorized.

### Directory and names

`->path('reports')` or `->path(fn (Request $request, Model $record) => 'reports/'.now()->format('Y/m'))`.
Segments must be `[A-Za-z0-9._-]`. The stored name is always 40 random characters plus the extension
detected from content. Keep the user's name with `storeOriginalName('column')` (single file only).

### Delete records with files

The panel deletes through `Entity::deleteRecord(Request $request)`, which prunes files after the
commit. In your own action do the same, never `$model->delete()`:

```php
use Illuminate\Database\Eloquent\Model;

$models->each(fn (Model $model) => $this->entity::make($model)->deleteRecord(request()));
```

Soft deletes keep files. A `forceDelete()` outside the panel does not delete them: add a
`forceDeleted` model listener that deletes after commit (guide section "Soft deletes").

### Shared paths

Pruning assumes one file per record. If code copies paths between records (`replicate()`, seeded
defaults), use `->prunable(false)` on that field.

### Extension field that stores files (FileSafety + FileJournal)

```php
namespace App\Larsadmin\Fields;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Files\FileJournal;
use Maxcxam\Larsadmin\Files\FileSafety;

class Scan extends Field
{
    public string $component = 'acme/scan';

    public function rulesFor(string $context, ?Model $record = null): array
    {
        $rules = parent::rulesFor($context, $record);
        $rules[$this->attribute][] = FileSafety::rule(publicDisk: false);

        return $rules;
    }

    public function fillInto(Request $request, Model $record, FileJournal $journal): ?Closure
    {
        $file = $request->file($this->attribute);

        if ($file instanceof UploadedFile) {
            $path = $file->storeAs('scans', Str::random(40).'.'.FileSafety::storedExtension($file), 'local');
            $journal->created('local', $path);                  // deleted if the save fails
            if ($record->scan) {
                $journal->obsolete('local', $record->scan);    // deleted after the commit
            }
            $record->scan = $path;
            $record->scan_name = FileSafety::sanitizeName($file->getClientOriginalName());
        }

        return null;
    }
}
```

Mark the field `->multipart()` so the form is sent as `multipart/form-data`. Instead of overriding
`rulesFor()` you can also pass the rule from the Entity: `Scan::make('Scan', 'scan')->rules('nullable',
'file', FileSafety::rule())`.

### Serving files from an extension route

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Files\FileSafety;
use Maxcxam\Larsadmin\Larsadmin;

function showScan(Request $request, string $entity, string $id)
{
    $record = Larsadmin::findRecord($request, $entity, $id, 'view'); // viewAny, detailQuery, policy
    Larsadmin::findField($request, $entity, $record, 'scan', Field::DETAIL); // visible field or 404
    $disk = Storage::disk('local');

    abort_unless(is_string($record->scan) && $disk->exists($record->scan), 404);

    $download = ! FileSafety::isRasterImage($record->scan, (string) $disk->mimeType($record->scan));

    return FileSafety::response($disk, $record->scan, (string) $record->scan_name, $download);
}
```

`FileSafety::response()` streams with the panel's headers: inline only for raster images, otherwise an
octet-stream attachment; nosniff, sandbox CSP, CORP same-origin, no-referrer, private cache.

### URLs only for users who may view the record (1.6)

Inside a field, `$this->authorizedTo('view')` answers from the rights the panel already computed for
the row (no extra policy call). It is `false` on create forms, in actions and for hand-built fields.
The built-in `File` field uses it to decide whether to issue links:

```php
namespace App\Larsadmin\Fields;

use Maxcxam\Larsadmin\Fields\Field;

class ScanLink extends Field
{
    public string $component = 'acme/scan-link';

    protected function scanUrl(): ?string
    {
        // No "view" right on this record: names only, never a URL.
        return $this->authorizedTo('view') ? '/admin/ext/scans/'.$this->value : null;
    }
}
```

Test it with `serializeField($field, $record, Field::DETAIL, Post::class, ['view' => true])` from
`Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin` (skill `larsadmin-testing`).

## API cheat sheet

| API | Notes |
| --- | --- |
| `Maxcxam\Larsadmin\Fields\File` | `multiple()`, `maxFiles()`, `disk()`, `public()`, `private()`, `path()`, `types()`, `maxSize()` (KB), `storeOriginalName()`, `prunable()`, `removable()`, `filesOf($record)` |
| `Maxcxam\Larsadmin\Fields\Image` | extends `File`; raster types only (no SVG); `thumbnail(fn ($file, Model $record) => ?string)` |
| `Entity::deleteRecord(Request $request)` | delete in a transaction, prune files after commit |
| `Maxcxam\Larsadmin\Files\FileJournal` | `created($disk, $path)`, `obsolete($disk, $path)`; passed to `Field::fillInto()` |
| `Maxcxam\Larsadmin\Files\FileSafety` | stable since 1.6: `rule()`, `blockedExtension()`, `storedExtension()`, `sanitizeName()`, `asciiName()`, `isRasterImage()`, `response()` |
| `Field::authorizedTo('view'\|'update'\|'delete')` | stable since 1.6; fail closed |
| route `larsadmin.files.show`, limiter `larsadmin-files` | signed private downloads |

Config (`larsadmin.files.*`): `larsadmin.files.disk`, `larsadmin.files.directory`,
`larsadmin.files.max_size` (10240 KB), `larsadmin.files.max_files` (10), `larsadmin.files.prune`,
`larsadmin.files.url_ttl` (30 min), `larsadmin.files.throttle` (600/min),
`larsadmin.files.blocked_extensions`.

## Security rules

- MUST set `types()` on every `File` field; `larsadmin.files.blocked_extensions` is only a backstop.
- NEVER take a file path or stored name from the request; NEVER store under the client's extension.
  In extension code use `FileSafety::storedExtension()` and a random name.
- MUST run `FileSafety::rule()` (or `blockedExtension()`) on every upload an extension accepts; pass
  `publicDisk: true` for public disks.
- MUST authorize before `FileSafety::response()` (`Larsadmin::findRecord()` + `Larsadmin::findField()`):
  it sets safe headers but checks no rights.
- MUST issue file URLs only when `authorizedTo('view')` is true.
- Keep files that must not be public on a private disk; for public disks add `nosniff`, sandbox CSP and
  attachment headers for `/storage` in the web server.

## Pitfalls

- No file fields in actions: `File`, `Image` or any `multipart` field in `Action::fields()` throws
  `LogicException` (1.6).
- `->public()` on a `local` disk without `url` throws `InvalidArgumentException`; so does an unknown
  disk or `types([])`.
- `storeOriginalName()` with `multiple()` throws `LogicException`; `sortable()` on a file field too.
- Images not loading: wrong `APP_URL` or missing `storage:link`.
- Uploads fail before Laravel runs: raise `upload_max_filesize` / `post_max_size` in `php.ini`.
- `fillUsing()` replaces saving completely: nothing is written or pruned.

## Reference

- Guide: `vendor/maxcxam/larsadmin/docs/guide/en/15-files.md` (MCP: `read-docs` with
  `page: "15-files"`; e.g. `section: "private-files-and-signed-urls"`).
- Save hooks for extension fields: `vendor/maxcxam/larsadmin/docs/guide/en/19-extensions.md`
  (skill `larsadmin-extending`).
