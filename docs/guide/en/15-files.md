# 15. Files and images
🇺🇦 [Українська](../15-files.md) · 🌐 [larsadmin.dev](https://larsadmin.dev/en/docs/files)

The `File` and `Image` fields upload files to a Laravel disk (`config/filesystems.php`) and store the
path in a model column. `Image` shows a thumbnail on the index, a preview on the record page and in
the form. `File` shows the file name as a link. Both fields work with one file or with several
(`multiple()`), with public and private disks, S3 included.

No extra Composer or npm dependencies are needed.

For models that use [spatie/laravel-medialibrary](https://spatie.be/docs/laravel-medialibrary) (collections,
conversions, galleries), install the separate add-on
[`maxcxam/larsadmin-medialibrary`](https://github.com/maxcxam/larsadmin-medialibrary) (1.0, needs Larsadmin 1.6+).
It adds the `Media` and `MediaGallery` fields and is documented in its own guide,
`vendor/maxcxam/larsadmin-medialibrary/docs/guide/en/medialibrary.md`.

## Quick start

### Public image

Migration: a plain string column for the path.

```php
$table->string('cover')->nullable();
```

Entity:

```php
use Maxcxam\Larsadmin\Fields\Image;

Image::make('Cover', 'cover')->disk('public'),
```

The `public` disk has `'visibility' => 'public'`, so the web server serves the file at
`Storage::disk('public')->url($path)`. This needs the `public/storage` link:

```bash
php artisan storage:link
```

`Storage::url()` returns an absolute address built from the disk's `url`, which is
`APP_URL.'/storage'` in the default config. If the panel is opened on a host other than `APP_URL`
(for example `127.0.0.1:8000` instead of `localhost`), images do not load. Set the right `APP_URL`
or `filesystems.disks.public.url`.

### Private file

```php
$table->string('contract')->nullable();
$table->string('contract_name')->nullable();
```

```php
use Maxcxam\Larsadmin\Fields\File;

File::make('Contract', 'contract')
    ->types(['pdf'])
    ->maxSize(5120)                    // KB
    ->storeOriginalName('contract_name'),
```

No disk is given, so `larsadmin.files.disk` is used, `local` by default (`storage/app/private` in
Laravel 11+). Such a file cannot be reached directly: the panel issues a signed link with a limited
lifetime and re-checks the user's permissions before every download
(see [Private files and signed URLs](#private-files-and-signed-urls)).

### Several files

```php
$table->json('attachments')->nullable();
```

```php
// app/Models/Post.php
protected function casts(): array
{
    return ['attachments' => 'array'];
}
```

```php
File::make('Attachments', 'attachments')
    ->multiple()
    ->maxFiles(5)
    ->types(['pdf', 'docx', 'xlsx']),
```

The user can add files with the "Choose files" button or drop them onto the form, remove single
files and bring removed ones back before saving. New files are added after the existing ones.
Files cannot be reordered in 1.1.

### Example: a post with a cover, attachments and a private document

```php
// migration
$table->string('cover')->nullable();
$table->string('cover_name')->nullable();
$table->json('attachments')->nullable();
$table->string('contract')->nullable();
$table->string('contract_name')->nullable();
```

```php
use Maxcxam\Larsadmin\Fields\File;
use Maxcxam\Larsadmin\Fields\Image;
use Maxcxam\Larsadmin\Layout\Panel;

public function fields(Request $request): array
{
    return [
        // Public disk: served by the web server through storage:link.
        Image::make('Cover', 'cover')->disk('public')->storeOriginalName('cover_name')->maxSize(2048),
        Text::make('Title', 'title')->rules('required', 'max:255'),
        Panel::make('Files', [
            // Several files in one JSON column on the private "local" disk (the default).
            File::make('Attachments', 'attachments')->multiple()->maxFiles(5)->maxSize(5120)
                ->types(['pdf', 'docx', 'xlsx', 'txt', 'zip', 'jpg', 'png']),
            // A private document for the first user only.
            File::make('Internal document', 'contract')->private()->types(['pdf', 'docx'])
                ->storeOriginalName('contract_name')
                ->hideFromIndex()
                ->canSee(fn (Request $request) => $request->user()?->getKey() === 1),
        ]),
    ];
}
```

The model casts `attachments` to `array`. Only a user for whom `canSee()` returns `true` sees and
can open the `contract` link: for everyone else the file route answers 404.

## Disks and visibility

| What | How it is decided |
| --- | --- |
| Disk | `->disk('s3')`, otherwise `larsadmin.files.disk` (`local` by default) |
| Public or private | `->public()` or `->private()`; if neither is called, a disk with `'visibility' => 'public'` in `config/filesystems.php` is public |
| Directory | `->path(...)`, otherwise `{files.directory}/{entity uriKey}/{attribute}`, for example `larsadmin/posts/cover` |

An unknown disk (missing from `filesystems.disks`) throws `InvalidArgumentException` as soon as the
field is bound to the entity. So does a `->public()` field on a `local`-driver disk without a `url`
key (the default `local` disk, for example): such a file has no public address. Use the `public`
disk or `->private()`.

How links are built:

| Visibility | Disk driver | Link |
| --- | --- | --- |
| public | any | `Storage::disk()->url($path)`, does not expire |
| private | not `local`, provides temporary URLs (S3 and compatible) | the disk's `temporaryUrl()` with lifetime `files.url_ttl` |
| private | `local` (`serve => true` included) or no temporary URLs | the panel route with a relative signature and lifetime `files.url_ttl` |

The file is written with `public` or `private` visibility to match the field.

### S3

```php
Image::make('Photo', 'photo')->disk('s3'),             // private: S3 temporary URLs
Image::make('Banner', 'banner')->disk('s3')->public(), // public: Storage::url()
```

For private S3 files the panel signs links locally, without a request to S3. The link sets
`ResponseContentType`, `ResponseContentDisposition` and `ResponseCacheControl`: raster images open
in the browser, every other file downloads as `application/octet-stream` and is not cached
(see [Caching](#caching)).

**An S3 temporary URL is checked only when it is issued** (the user could see the record and the
field). After that only its lifetime, `files.url_ttl`, bounds it. If access must be checked on every
download, use a disk with the `local` driver.

### Directory

```php
File::make('Report', 'report')->path('reports'),
File::make('Report', 'report')->path(fn (Request $request, Model $record) => 'reports/'.now()->format('Y/m')),
```

The directory must be relative and made of `[A-Za-z0-9._-]` segments, without `.` and `..`.
Otherwise `InvalidArgumentException`: at once for a string, on save for a closure. On create, the
record passed to the closure is not saved yet, so it has no key.

## Storage format

The name on the disk is always generated: 40 random characters plus the extension detected from the
file content (`bin` when the type is unknown). The name from the user's computer is never used on
the disk.

**One file.** The column holds the path relative to the disk root:

```
larsadmin/posts/cover/Xk3fq…40 characters….jpg
```

The file size is not stored (only `multiple()` stores size and type), the type is derived from the
extension. The original name is not stored either: without `storeOriginalName()` the file is shown
and downloaded under the field label plus the extension, for example `Contract.pdf`. So for a single
file or image **we recommend storing the original name** in a separate column:

```php
File::make('Contract', 'contract')->storeOriginalName('contract_name'),
```

The name is cleaned: only the last path segment, without Unicode control and format characters
(including the bidirectional overrides and zero-width characters used to fake an extension) and
`"<>:*?|`, at most 200 characters (the extension is kept). `storeOriginalName()` together with `multiple()` throws
`LogicException`.

**Several files.** The column holds a JSON array of objects:

```json
[{"path":"larsadmin/posts/attachments/Q9…40.pdf","name":"October report.pdf","size":48213,"mime":"application/pdf"}]
```

`size` and `mime` are detected by the server (`getSize()`, fileinfo), not by the browser. We
recommend a `json` (or `text`) column and the `array` cast. If the model casts the column to `array`,
`json`, `object`, `collection`, their `encrypted:` variants, `AsArrayObject` or `AsCollection`, the
field writes a PHP array, otherwise a JSON string.

Reading is tolerant: `null` and `''` mean "no files", a list of strings is read as a list of paths,
entries without a valid path are skipped. An entry without a name is shown as
`{field label} (N).{extension}`, where N is its position. A valid path is non-empty, relative, without `..`, `\`,
`:` and control characters. Invalid entries are not shown, not served and not deleted.

## Validation and limits

| Limit | How to set it | Default |
| --- | --- | --- |
| Types | `->types(['pdf', 'docx'])` | `File`: any type that is not blocked; `Image`: `jpg`, `jpeg`, `png`, `gif`, `webp`, `avif` |
| Size of one file | `->maxSize(5120)` (KB, like Laravel's `max` rule) | `files.max_size` = 10240 (10 MB) |
| Number of files | `->maxFiles(5)` (with `multiple()` only) | `files.max_files` = 10; always 1 without `multiple()` |
| Required | `->rules('required')`, `->creationRules('required')` | not required |
| Blocked types | `files.blocked_extensions` | see [Configuration](#configuration) |

- `types()` checks both the content (`mimes`) and the extension of the browser's file name
  (`extensions`). This is the main type control: **set `types()` on every `File` field**, and keep the
  blocked-extension list as a backstop. `types([])` throws `InvalidArgumentException`; to allow any
  type that is not blocked, do not call `types()` at all.
- `Image::types()` accepts raster types only (`jpg`, `jpeg`, `png`, `gif`, `webp`, `avif`); any other
  type, SVG included, throws `InvalidArgumentException`.
- `required` means at least one file must remain in the field after the save. When editing, a file
  that is already uploaded satisfies the rule.
- The count is taken after the save: kept existing files plus new ones.
- Your other rules from `rules()`, `creationRules()`, `updateRules()` apply **to each uploaded file**
  on its own. For example, a minimum image size:

  ```php
  Image::make('Cover', 'cover')->rules('required', 'dimensions:min_width=600'),
  ```

  `required`, `nullable`, `sometimes` and `array` apply to the field as a whole, not to the files.
  `nullable()` is accepted but changes nothing: emptiness is governed by `required`.

Errors are shown next to the field, and the error of a particular file next to that file. The
browser also checks size, type and count before sending, but the server always has the final say.

### Blocked extensions

Extensions from `files.blocked_extensions` are always rejected, even when listed in `types()`. The
default list covers:

- server-side scripts: the PHP family (`php`, `phtml`, `phar`, `inc` and so on), CGI, Perl, Python,
  Ruby, shell, ASP/ASP.NET, JSP, ColdFusion, SSI (`shtml`);
- Windows executables and script hosts: `exe`, `dll`, `msi`, `bat`, `cmd`, `scr`, `hta`, `vbs`,
  `ps1`, `reg`, `lnk`, `jar` and others;
- markup and scripts run by browsers: HTML, XHTML, MHT, SVG, XML, XSL, JS (`js`, `mjs`, `cjs`), `swf`;
- server configuration: `htaccess`, `htpasswd`, `ini`.

The check covers:

- every segment of the browser's file name after the first dot, in both the raw and the cleaned name
  (without the trailing dots and spaces Windows drops): `photo.php.jpg`, `run.bat"` and `.htaccess`
  are rejected. The base of the name itself is not checked, so `php.pdf` passes (and lands on the
  disk under a random name);
- the extension detected from the content: PHP code renamed to `.jpg` is rejected too.

**Public disks** are served by the web server, so they have a floor that config cannot turn off:
markup and browser scripts (`svg`, `svgz`, `html`, `htm`, `xhtml`, `xht`, `xhtm`, `shtml`, `mht`,
`mhtml`, `xml`, `xsl`, `xslt`, `js`, `mjs`) and server-side ones (`php`, `phtml`, `phar`,
`htaccess`). They are rejected even when removed from `files.blocked_extensions`.

**SVG** can carry scripts. It can be allowed only on a private disk, by removing `svg` (and `svgz` if
needed) from `files.blocked_extensions`, and only in a `File` field: `Image` never accepts SVG. Such a
file is served as an attachment and never opens in the browser.

### PHP limits

PHP rejects oversized requests before Laravel runs. Check `php.ini`:

| Directive | What it limits |
| --- | --- |
| `upload_max_filesize` | the size of one file; 2M by default |
| `post_max_size` | the size of the whole request with all files; 8M by default |
| `max_file_uploads` | the number of files in one request; 20 by default |

The field passes these limits to the browser, so the form shows the smaller of the two values
(`maxSize` and `upload_max_filesize`) and does not let users send oversized files. A file larger
than `upload_max_filesize` is rejected by the server as not uploaded. A request larger than
`post_max_size` is stopped by Laravel with a 413 response; the form shows the message "The files
are too large to save at once" instead of an error page. Web server limits (`client_max_body_size`
in nginx) must not be lower either.

## Private files and signed URLs

Links to private files on `local` disks point to a panel route:

```
GET {path}/files/{entity}/{id}/{field}/{token}?expires=…&signature=…
```

The route name is `larsadmin.files.show`. The link never carries the file path: `token` is the first
32 hex characters of an HMAC of `APP_KEY`, the disk and the path. The signature is relative
(`signed:relative`), so the link works behind a proxy and on another host. The `download=1`
parameter (part of the signature) asks for a download instead of a preview.

**Permissions are checked on every request**, in the same order as for the record page:

1. the signature and expiry (otherwise 403), panel login and `viewLarsadmin`, the rate limit;
2. the entity exists (otherwise 404) and `viewAny` (otherwise 403);
3. the record is found through `detailQuery` (otherwise 404);
4. `view` on the record (otherwise 403);
5. the `File`/`Image` field with this attribute is shown to the user on detail or index, or on the
   edit form when the user has `update`; `canSee()` returns `true` (otherwise 404);
6. the token matches one of the record's **current** files (otherwise 404) and the file exists on
   the disk (otherwise 404).

So a link stops working as soon as the user loses access or the file is replaced, even before it
expires.

Other rules:

- Links are issued only when the user may view the record (`view`). Otherwise the list shows file
  names only.
- Lifetime at least `files.url_ttl` (minutes, 30 by default). If the page has been open longer,
  clicking a link first refreshes the page data and then opens the fresh link.
- Limit: `files.throttle` downloads per minute per user (per IP for guests), limiter
  `larsadmin-files`. `null` disables the limit. Every request streams the whole file (no `Range`),
  so at 600 requests a minute one user can pull several gigabytes. Lower the limit if storage
  traffic is metered.
- The response is streamed; the file is never read into memory as a whole.
- Headers: raster images (`jpg`, `jpeg`, `png`, `gif`, `webp`, `avif`) are served inline with their
  type, everything else as `application/octet-stream` with `Content-Disposition: attachment`. Always
  `X-Content-Type-Options: nosniff`, a `Content-Security-Policy` with `sandbox`,
  `Cross-Origin-Resource-Policy: same-origin` and `Referrer-Policy: no-referrer`. The file name is
  cleaned and encoded per RFC 6266 with an ASCII fallback.

### Caching

- Attachments (everything that downloads rather than displays) are served with
  `Cache-Control: private, no-store`: no copy stays in the browser after logout or lost access.
- Raster images shown inline are served with `Cache-Control: private, max-age=<url_ttl>`.
- A link's expiry is rounded up to a quarter of `url_ttl` (at least a minute). So within that
  interval the links to a file are identical, and the browser takes thumbnails from its cache. The
  effective lifetime is between `url_ttl` and `url_ttl` plus a quarter.
- S3 temporary URLs get the same values through `ResponseCacheControl`.

## Deletion and pruning

A disk is not transactional, so the panel keeps a journal of the files of one save:

- new files are written **before** the database transaction;
- if filling, `save()` or an after-save callback throws, the files written by this request are
  deleted and the old ones stay;
- replaced and removed files are deleted **only after the commit** of the outermost transaction. If
  your code wraps the save in its own transaction, deletion waits for its commit, and on rollback the
  files stay;
- errors while deleting from the disk go to `report()` (with the disk, path, entity and key) and do
  not break the request.

### Pruning

Pruning is **on by default** (`files.prune`). It deletes:

- a file replaced by a new one;
- files removed in the form;
- all files of a record deleted through the panel.

To turn it off for a field:

```php
File::make('Archive', 'archive')->prunable(false),
```

or for the whole panel: `'prune' => false` in `config/larsadmin.php`.

Pruning assumes **"one file, one record"** and does not count references. The panel itself keeps to
it: every upload gets a new random name. But if your code writes the same path into several records
(`$post->replicate()`, a shared default such as `avatars/default.png` from a seeder), replacing the
file or deleting one record deletes the file for all of them. Use `prunable(false)` for such columns.

### Concurrent edits

The form sends two token lists: the files it showed (`known`) and the files the user kept (`keep`).
Only files in `known` but not in `keep` count as removed. So:

- files someone else added while the form was open stay;
- tokens from an old `APP_KEY` (a form opened before the key changed) match nothing, and nothing is
  removed;
- with `removable(false)` the server removes no file, even from a request sent around the form. A new
  file in a single field still replaces the old one.

For requests that do not come from the panel form (API clients, tests): without `known` nothing is
removed, new `multiple()` files are appended to the existing ones, and a file uploaded to a single
field replaces the old one.

### Deleting records

The panel's delete button calls `Entity::deleteRecord(Request $request)`: `delete()` in a
transaction, and after the commit, deletion of the files of every `File`/`Image` field of the
entity, even fields that are hidden or not visible to the current user (the record is gone).
`prunable(false)` and `files.prune` apply here too.

In your own delete actions use the same method instead of `$model->delete()`. `handle()` does not
receive the `Request`, so pass `request()`. `$this->entity` holds the class of the entity the panel
bound the action to:

```php
public function handle(ActionFields $fields, Collection $models): ?ActionResponse
{
    // Through the entity, not $model->delete(): files of File/Image fields are deleted after the commit.
    $models->each(fn (Model $model) => $this->entity::make($model)->deleteRecord(request()));

    return ActionResponse::message('Deleted records: '.$models->count());
}
```

`$this->entity` is `null` when the action is created outside the panel (for example with `new` in a
test). Then name the entity class explicitly: `\App\Larsadmin\Post::make($model)->deleteRecord(request())`.

### Soft deletes

If the model uses `SoftDeletes`, deleting in the panel only sets `deleted_at`, and **the files
stay** so the record can be restored.

A permanent deletion outside the panel (`forceDelete()`, `$model->delete()` in your code, cleanup
commands) does not delete files. Add a model listener:

```php
// app/Models/Post.php
use Illuminate\Support\Facades\Storage;

protected static function booted(): void
{
    static::forceDeleted(function (Post $post) {
        $cover = $post->cover;                                             // Image, public disk
        $attachments = collect($post->attachments ?? [])->pluck('path')->filter()->all(); // multiple(), local disk

        $post->getConnection()->afterCommit(function () use ($cover, $attachments) {
            if ($cover) {
                Storage::disk('public')->delete($cover);
            }

            Storage::disk('local')->delete($attachments);
        });
    });
}
```

`afterCommit()` waits for the transaction to commit and runs at once when there is no transaction.
The example assumes the `array` cast on `attachments`.

### Custom fields with files

An extension field that writes files registers them in the same save journal that `File` and `Image` use:
it overrides `Field::fillInto(Request $request, Model $record, FileJournal $journal)` and calls
`$journal->created($disk, $path)` for written files (deleted when the save fails) and
`$journal->obsolete($disk, $path)` for replaced ones (deleted after the commit). Its form is sent as
`multipart/form-data` when the field has `multipart()`. See
[Extensions: save hooks](19-extensions.md#save-hooks-fillinto-and-filejournal).

### `FileSafety` for extensions

Since 1.6 the safety checks that `File` and `Image` use are available to extensions through the stable
`Maxcxam\Larsadmin\Files\FileSafety` facade. An extension that stores or serves files (like the media
library add-on) keeps no copy of these checks.

| Method | Description |
| --- | --- |
| `rule(bool $publicDisk = false): Closure` | a validation rule: rejects [blocked extensions](#blocked-extensions) by every segment of the name and by the type detected from the content; with `$publicDisk` also markup and scripts |
| `blockedExtension(UploadedFile $file, bool $publicDisk = false): ?string` | the file's first blocked extension, or `null` |
| `storedExtension(UploadedFile $file, bool $publicDisk = false): string` | the extension to store with: detected from the content and allowed, else `bin`; never the browser's extension |
| `sanitizeName(string $name): string` | the browser's name cleaned up: the last path segment, without control, bidi/zero-width and reserved characters, at most 200 characters; may be `''` |
| `asciiName(string $name): string` | the ASCII fallback of a name for the `filename` parameter of `Content-Disposition` |
| `isRasterImage(string $path, ?string $mime = null): bool` | whether the file may be shown inline: a raster extension (jpg, jpeg, png, gif, webp, avif; never SVG) and, when given, a raster MIME type |
| `response(FilesystemAdapter $disk, string $path, ?string $name = null, bool $download = false, ?int $ttlMinutes = null): StreamedResponse` | a streamed response with the panel's headers (see below) |

```php
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maxcxam\Larsadmin\Files\FileSafety;

// validating an upload
$request->validate(['upload' => ['required', 'file', 'max:10240', FileSafety::rule($public)]]);

// storing it under a random name with the extension detected from the content
$file = $request->file('upload');
$path = $file->storeAs('acme', Str::random(40).'.'.FileSafety::storedExtension($file, $public), $disk);
$name = FileSafety::sanitizeName($file->getClientOriginalName());

// serving it (after authorizing the request, e.g. with Larsadmin::findRecord())
return FileSafety::response(Storage::disk($disk), $path, $name);
```

`response()` serves only raster images inline (by the path's extension, unless `$download`), everything else
as an `application/octet-stream` attachment; always with `X-Content-Type-Options: nosniff`,
`Content-Security-Policy: sandbox`, `Cross-Origin-Resource-Policy: same-origin`,
`Referrer-Policy: no-referrer` and a private cache (inline images for `$ttlMinutes`, by default
`files.url_ttl`; a value below 1 throws `InvalidArgumentException`; attachments are not stored). The name defaults to the path's base name. **The extension's
code authorizes the request before the call.** `FileTypes`, `FileResponse` and `StoredFile` stay internal.

## Security

- The file path never comes from the browser. The form sends tokens of existing files, and they are
  matched only against the record's current files; unknown tokens are ignored. A field value that is
  not an array (a forged path, for example) fails validation, and nothing is stored.
- The name on the disk is random, the extension is detected from the content. The directory is set
  in code only.
- Private files are checked on every request (see above) and served with safe headers.
- **Public disks are served by the web server**, and the panel cannot set headers there. Markup and
  scripts (HTML, SVG, XML, JS, PHP) are blocked on public disks with no way to turn that off, but
  other types such as `pdf` or `txt` are served inline by the web server from the application's
  origin. Recommendations:
  - restrict fields with `types()`, not only with the blocked-extension list;
  - add `X-Content-Type-Options: nosniff`, `Content-Security-Policy: sandbox` and
    `Content-Disposition: attachment` for everything except images to `/storage` in the web server
    config;
  - keep files that must not be public on a private disk.
- Only paths that the record referenced are deleted, and only after the commit.
- `File` and `Image` fields, like any field with `multipart: true`, cannot be used in action `fields()`
  (`LogicException`): an action fills a throw-away model, and the files would be left without an owner.
- Extensions that work with files use the same checks through [`FileSafety`](#filesafety-for-extensions).

## API reference

Classes `Maxcxam\Larsadmin\Fields\File` and `Maxcxam\Larsadmin\Fields\Image` (`Image extends File`).

| Method | Description |
| --- | --- |
| `File::make(string $name, ?string $attribute = null)` | component `file-field`; `Image::make()` gives `image-field` |
| `multiple(bool $multiple = true)` | several files in a JSON column |
| `maxFiles(int $max)` | the maximum number of files for `multiple()`; less than 1 throws `InvalidArgumentException` |
| `disk(string $disk)` | the disk; `files.disk` by default |
| `public()`, `private()` | visibility; taken from the disk's `visibility` by default. `public()` on a `local` disk without `url` throws `InvalidArgumentException` |
| `path(string\|Closure $directory)` | a directory or `fn (Request $request, Model $record): string` |
| `types(array $types)` | allowed extensions, for example `['pdf', 'docx']`; when not called (the `File` default), any type that is not blocked; `types([])` throws `InvalidArgumentException`; `Image::types()` accepts raster types only |
| `maxSize(int $kilobytes)` | the maximum size of one file in KB; less than 1 throws `InvalidArgumentException` |
| `storeOriginalName(string $column)` | a column for the cleaned original name; single file only |
| `prunable(bool $prunable = true)` | delete replaced and removed files and files of deleted records; `files.prune` by default. `false` for paths shared by several records |
| `removable(bool $removable = true)` | allow removing files. With `false` the form hides the "Remove" button and the server removes no file, even from a request sent around the form; a new file in a single field still replaces the old one |
| `Image::thumbnail(Closure $callback)` | `fn (StoredFile $file, Model $record): ?string`; a thumbnail URL, or `null` for the original |
| `filesOf(Model $record): array` | the record's current files as `list<StoredFile>` (`path`, `name`, `size`, `mime`, `extension()`, `isRasterImage()`) |
| `rules()`, `creationRules()`, `updateRules()` | `required` for the field, the rest for each file (see [Validation](#validation-and-limits)) |
| `fillUsing(callable)` | replaces saving completely: the field writes nothing to the disk and deletes nothing |
| `displayUsing(callable)` | `fn (array $names, Model $record, string $attribute)`, receives the list of file names. The result goes to `displayedAs`; the built-in components show the file list, so it only matters to custom components |
| `resolveUsing(callable)` | ignored on file fields |
| `sortable()` | throws `LogicException`: file fields cannot be sorted |
| `nullable()` | accepted, changes nothing |

Other:

| API | Description |
| --- | --- |
| `Entity::deleteRecord(Request $request): void` | deletes the record in a transaction and prunes files after the commit (see [Deleting records](#deleting-records)) |
| route `larsadmin.files.show` | `GET {path}/files/{entity}/{id}/{field}/{token}`, signed |
| limiter `larsadmin-files` | `files.throttle` requests per minute |

On the index: a single file is shown as is, `multiple()` shows up to three thumbnails or names and a
`+N` counter. Thumbnails are originals scaled down by CSS.

## Configuration

The `files` section of `config/larsadmin.php`. A config published before 1.1 works without it: every
key has a default.

| Key | Default | Description |
| --- | --- | --- |
| `disk` | `env('LARSADMIN_FILES_DISK', 'local')` | the disk of fields without `disk()` |
| `directory` | `'larsadmin'` | the base directory; a field's directory is `{directory}/{uriKey}/{attribute}` |
| `max_size` | `10240` | the maximum file size, KB |
| `max_files` | `10` | the maximum number of files for `multiple()` without `maxFiles()` |
| `prune` | `true` | delete replaced and removed files and files of deleted records |
| `url_ttl` | `30` | the lifetime of signed and temporary URLs, minutes |
| `throttle` | `600` | private file downloads per minute per user; `null` for no limit |
| `blocked_extensions` | server-side scripts, Windows executables, browser markup and scripts, server configuration | extensions that are never accepted (see [Blocked extensions](#blocked-extensions)); a backstop, the main control is `types()`. Markup, scripts and PHP are always blocked on public disks |

## Limitations

- **No server-side image processing.** Thumbnails are originals scaled down by CSS. If you have your
  own thumbnails (a resizing CDN, Glide and so on), return their URL from `->thumbnail(...)`:

  ```php
  Image::make('Cover', 'cover')
      ->thumbnail(fn (StoredFile $file, Model $record) => "https://img.example.com/{$file->path}?w=160"),
  ```

- **No reordering** of files by drag and drop: new files are appended.
- **No pre-upload or chunked upload.** Files are sent with the form, so the total size is bounded by
  `post_max_size`.
- File fields are **not supported in actions** and cannot be sorted.
- A file can be left without an owner if the process dies between writing the file and the commit,
  or if an outer transaction of your code rolls back after the save. There is no cleanup command for
  such files in 1.1.
- Pruning does not count references: a path shared by several records needs `prunable(false)`
  (see [Pruning](#pruning)).
- `spatie/laravel-medialibrary` is not used by these fields. Its collections are supported by the separate add-on
  [`maxcxam/larsadmin-medialibrary`](https://github.com/maxcxam/larsadmin-medialibrary) (`Media`, `MediaGallery`),
  built on the public extension API and `Files\FileSafety`.

Next: [Global search](16-search.md).
