<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Maxcxam\Larsadmin\Fields\File;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Image;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Files\FileResponse;
use Maxcxam\Larsadmin\Files\StoredFile;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\Document;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\DocumentPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\Entities\Document as DocumentEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\SoftDocument;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\Uploads;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

/*
 * T1: the File/Image security properties seen from an attacker, end-to-end through HTTP
 * (design §6, §9 AC-4/6/7/8, §11 security checklist; ADR-016..018).
 */

beforeEach(function () {
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([DocumentEntity::class]);
    DocumentEntity::reset();
    DocumentPolicy::$denied = [];
    Gate::policy(Document::class, DocumentPolicy::class);
    Gate::policy(SoftDocument::class, DocumentPolicy::class);

    Storage::fake('public');
    Storage::fake('local');

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
});

afterEach(function () {
    DocumentEntity::reset();
    DocumentPolicy::$denied = [];
});

/**
 * GIF magic bytes followed by an HTML payload: a classic image/HTML polyglot.
 */
const FILE_SEC_POLYGLOT = 'GIF89a<html><body><script>alert(document.cookie)</script></body></html>';

const FILE_SEC_XHTML = '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></html>';

/**
 * A document with a public cover, two private attachments and a private secret on the fake disks.
 */
function fileSecDocument(string $model = Document::class, string $prefix = ''): Document
{
    Storage::disk('public')->put("{$prefix}c/cover.png", base64_decode(Uploads::PNG));
    Storage::disk('local')->put("{$prefix}a/report.pdf", '%PDF-1.4 report');
    Storage::disk('local')->put("{$prefix}a/notes.txt", 'notes');
    Storage::disk('local')->put("{$prefix}s/secret.txt", 'top secret');

    return $model::create([
        'title' => 'Doc',
        'cover' => "{$prefix}c/cover.png",
        'cover_name' => 'cover.png',
        'attachments' => [
            ['path' => "{$prefix}a/report.pdf", 'name' => 'report.pdf'],
            ['path' => "{$prefix}a/notes.txt", 'name' => 'notes.txt'],
        ],
        'secret' => "{$prefix}s/secret.txt",
    ]);
}

function fileSecToken(string $path, string $disk = 'local'): string
{
    return (new StoredFile($path, basename($path)))->token($disk);
}

/**
 * A relative signed download URL, as FileUrls issues it.
 */
function fileSecUrl(Document|int|string $document, string $field, string $path, array $query = [], string $disk = 'local', int $minutes = 30, bool $absolute = false): string
{
    return URL::temporarySignedRoute('larsadmin.files.show', now()->addMinutes($minutes), [
        'entity' => 'documents',
        'id' => $document instanceof Document ? $document->getKey() : $document,
        'field' => $field,
        'token' => fileSecToken($path, $disk),
        ...$query,
    ], $absolute);
}

/**
 * Fields with a File on a public disk: the worst case, served by the web server from the app origin.
 */
function fileSecPublicFields(): Closure
{
    return fn () => [
        ID::make(),
        Text::make('Title'),
        Image::make('Cover')->disk('public')->storeOriginalName('cover_name'),
        File::make('Attachments')->multiple()->maxFiles(3)->disk('public'),
        File::make('Secret')->disk('local'),
    ];
}

/**
 * A File made public on the local disk, which then needs a base URL.
 */
function fileSecPublicLocal(): File
{
    config(['filesystems.disks.local.url' => 'https://files.test']);

    return File::make('Secret')->disk('local')->public();
}

/**
 * Every file on both fake disks.
 *
 * @return list<string>
 */
function fileSecStoredFiles(): array
{
    return [...Storage::disk('public')->allFiles(), ...Storage::disk('local')->allFiles()];
}

/**
 * The serialized files of a field on the detail page.
 *
 * @return list<array<string, mixed>>
 */
function fileSecDetailFiles(Document $document, string $attribute): array
{
    return DocumentEntity::make($document)->serializeRecord(request(), 'detail')['fields']
        ->firstWhere('attribute', $attribute)
        ->jsonSerialize()['files'];
}

/**
 * Assert the response is a sandboxed attachment that a browser can neither sniff nor render.
 */
function fileSecAssertAttachment($response): void
{
    expect($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe(FileResponse::CONTENT_SECURITY_POLICY)
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox')->toContain("default-src 'none'");
}

// AC-6: stored XSS through uploads

it('rejects script-capable uploads before storing anything (AC-6)', function (string $field, Closure $upload) {
    DocumentEntity::$factory = fileSecPublicFields();

    $this->post('/admin/entities/documents', ['title' => 'X', $field => ['present' => 1, 'upload' => [$upload()]]])
        ->assertSessionHasErrors("{$field}.upload.0");

    expect(Document::count())->toBe(0)->and(fileSecStoredFiles())->toBe([]);
})->with([
    'svg by extension' => ['attachments', fn () => Uploads::svg('logo.svg')],
    'svgz by extension' => ['attachments', fn () => Uploads::text('logo.svgz', 'compressed')],
    'html by extension' => ['attachments', fn () => Uploads::text('page.html', 'hello')],
    'htm by extension' => ['attachments', fn () => Uploads::text('page.htm', 'hello')],
    'xhtml by extension' => ['attachments', fn () => Uploads::text('page.xhtml', 'hello')],
    'shtml by extension' => ['attachments', fn () => Uploads::text('page.shtml', 'hello')],
    'xml by extension' => ['attachments', fn () => Uploads::text('feed.xml', 'hello')],
    'xsl by extension' => ['attachments', fn () => Uploads::text('style.xsl', 'hello')],
    'js by extension' => ['attachments', fn () => Uploads::text('app.js', 'alert(1)')],
    'mjs by extension' => ['attachments', fn () => Uploads::text('app.mjs', 'alert(1)')],
    'upper-case extension' => ['attachments', fn () => Uploads::text('PAGE.HTML', 'hello')],
    'mixed-case extension' => ['attachments', fn () => Uploads::text('logo.SvG', 'hello')],
    'trailing dot' => ['attachments', fn () => Uploads::text('page.html.', 'hello')],
    'trailing space' => ['attachments', fn () => Uploads::text('page.html ', 'hello')],
    'NUL byte before a safe extension' => ['attachments', fn () => Uploads::text("page.html\0.txt", 'hello')],
    'double extension html.pdf' => ['attachments', fn () => Uploads::pdf('report.html.pdf')],
    'double extension svg.png' => ['attachments', fn () => Uploads::png('logo.svg.png')],
    'html content named .txt' => ['attachments', fn () => Uploads::html('notes.txt')],
    'svg content named .txt' => ['attachments', fn () => Uploads::svg('logo.txt')],
    'xhtml content named .txt' => ['attachments', fn () => Uploads::make('page.txt', FILE_SEC_XHTML)],
    'xml content named .txt' => ['attachments', fn () => Uploads::make('data.txt', '<?xml version="1.0"?><root/>')],
    'html content without an extension' => ['attachments', fn () => Uploads::html('README')],
    'html with a spoofed image/png client type' => ['attachments', fn () => new UploadedFile(Uploads::html()->getPathname(), 'photo.png', 'image/png', null, true)],
    'GIF/HTML polyglot named .html' => ['attachments', fn () => Uploads::make('cat.html', FILE_SEC_POLYGLOT)],
    'image: svg by extension' => ['cover', fn () => Uploads::svg('logo.svg')],
    'image: svg content named .png' => ['cover', fn () => Uploads::svg('logo.png')],
    'image: html content named .jpg' => ['cover', fn () => Uploads::html('photo.jpg')],
    'image: html with a spoofed image/jpeg client type' => ['cover', fn () => new UploadedFile(Uploads::html()->getPathname(), 'photo.jpg', 'image/jpeg', null, true)],
    'image: xhtml content named .gif' => ['cover', fn () => Uploads::make('photo.gif', FILE_SEC_XHTML)],
    'image: GIF/HTML polyglot named .html' => ['cover', fn () => Uploads::make('cat.html', FILE_SEC_POLYGLOT)],
    'image: php content named .jpg' => ['cover', fn () => Uploads::php('photo.jpg')],
    'private file: html content named .txt' => ['secret', fn () => Uploads::html('notes.txt')],
    'private file: php by extension' => ['secret', fn () => Uploads::php('shell.php')],
    'private file: phar renamed .pdf.phar' => ['secret', fn () => Uploads::pdf('x.pdf.phar')],
]);

it('rejects a blocked extension hidden behind characters the name sanitizer strips (AC-6, SECF-1)', function (string $name) {
    DocumentEntity::$factory = fileSecPublicFields();

    $this->post('/admin/entities/documents', ['title' => 'X', 'secret' => ['upload' => [Uploads::text($name, "@echo off\r\n")]]])
        ->assertSessionHasErrors('secret.upload.0');

    expect(Document::count())->toBe(0)->and(fileSecStoredFiles())->toBe([]);
})->with([
    'quote after the extension' => 'run.bat"',
    'angle bracket after the extension' => 'run.bat>',
    'question mark after the extension' => 'run.bat?',
    'control character inside the extension' => "run.b\x01at",
    'NUL inside the extension' => "page.ht\0ml",
    'bidi override inside the extension' => "run.b\u{202E}at",
    'trailing dot and space' => 'run.bat. ',
]);

it('stores a GIF/HTML polyglot only as a raster image, served inline with nosniff and a sandbox (AC-6, AC-8)', function () {
    DocumentEntity::$factory = fn () => [
        ID::make(),
        Text::make('Title'),
        Image::make('Cover')->disk('public'),
        Image::make('Secret')->disk('local'),
    ];

    $this->post('/admin/entities/documents', [
        'title' => 'Polyglot',
        'cover' => ['upload' => [Uploads::make('cat.gif', FILE_SEC_POLYGLOT)]],
        'secret' => ['upload' => [Uploads::make('cat.gif', FILE_SEC_POLYGLOT)]],
    ])->assertSessionHasNoErrors();

    $document = Document::firstOrFail();

    expect($document->cover)->toMatch('#^larsadmin/documents/cover/[A-Za-z0-9]{40}\.gif$#')
        ->and($document->secret)->toMatch('#^larsadmin/documents/secret/[A-Za-z0-9]{40}\.gif$#');

    $response = $this->get(fileSecDetailFiles($document, 'secret')[0]['url'])->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/gif')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin');
});

it('serves script-capable types unlocked on a private disk only as sandboxed attachments (AC-8)', function (Closure $upload, string $extension) {
    config(['larsadmin.files.blocked_extensions' => ['php']]);
    DocumentEntity::$factory = fn () => [ID::make(), Text::make('Title'), File::make('Secret')->disk('local')];

    $this->post('/admin/entities/documents', ['title' => 'X', 'secret' => ['upload' => [$upload()]]])
        ->assertSessionHasNoErrors();

    $document = Document::firstOrFail();
    $file = fileSecDetailFiles($document, 'secret')[0];

    expect($document->secret)->toEndWith(".{$extension}")
        ->and($file['url'])->toBe($file['downloadUrl'])
        ->and($file['isImage'])->toBeFalse();

    fileSecAssertAttachment($this->get($file['url'])->assertOk());

    // Even a URL signed without download=1 (as an inline one would be) never renders.
    fileSecAssertAttachment($this->get(fileSecUrl($document, 'secret', $document->secret))->assertOk());
})->with([
    'svg' => [fn () => Uploads::svg(), 'svg'],
    'html' => [fn () => Uploads::html(), 'html'],
    'xhtml' => [fn () => Uploads::make('page.xhtml', FILE_SEC_XHTML), 'xml'],
]);

it('keeps markup and script types blocked on public fields even when the config unlocks them (AC-6, SECF-5)', function (Closure $field, Closure $upload) {
    config(['larsadmin.files.blocked_extensions' => []]);
    DocumentEntity::$factory = fn () => [ID::make(), Text::make('Title'), $field()];

    $this->post('/admin/entities/documents', ['title' => 'X', 'secret' => ['upload' => [$upload()]]])
        ->assertSessionHasErrors('secret.upload.0');

    expect(fileSecStoredFiles())->toBe([]);
})->with([
    'public() on a private disk' => [fn () => fileSecPublicLocal(), fn () => Uploads::svg()],
    'public() with svg content named .txt' => [fn () => fileSecPublicLocal(), fn () => Uploads::svg('logo.txt')],
    'html on a public disk' => [fn () => File::make('Secret')->disk('public'), fn () => Uploads::html()],
    'xhtml on a public disk' => [fn () => File::make('Secret')->disk('public'), fn () => Uploads::text('page.xht', 'x')],
    'js on a public disk' => [fn () => File::make('Secret')->disk('public'), fn () => Uploads::text('app.js', 'alert(1)')],
    'php content on a public disk' => [fn () => fileSecPublicLocal(), fn () => Uploads::php('notes.txt')],
    'image on a public disk' => [fn () => Image::make('Secret')->disk('public'), fn () => Uploads::svg()],
    'svgz on a public disk' => [fn () => File::make('Secret')->disk('public'), fn () => Uploads::text('logo.svgz', 'x')],
]);

// AC-7: path traversal and path injection

it('never derives the stored path from the client file name (AC-7)', function (string $name) {
    DocumentEntity::$factory = fileSecPublicFields();

    $this->post('/admin/entities/documents', ['title' => 'X', 'cover' => ['upload' => [Uploads::png($name)]]])
        ->assertSessionHasNoErrors();

    $document = Document::firstOrFail();

    expect($document->cover)->toMatch('#^larsadmin/documents/cover/[A-Za-z0-9]{40}\.png$#')
        ->and(Storage::disk('public')->allFiles())->toBe([$document->cover])
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($document->cover_name)->not->toContain('/')->not->toContain('\\')
        ->and(preg_match('/[\x00-\x1F\x7F"]/', $document->cover_name))->toBe(0);
})->with([
    'dot-dot segments' => '../../../evil.png',
    'absolute unix path' => '/etc/cron.d/evil.png',
    'absolute windows path' => 'C:\\Windows\\System32\\evil.png',
    'windows separators' => '..\\..\\evil.png',
    'mixed separators' => '..\\../..\\evil.png',
    'NUL byte' => "evil\0.png",
    'CRLF' => "evil\r\n.png",
    'url-encoded traversal' => '%2e%2e%2f%2e%2e%2fevil.png',
    'fullwidth solidus' => '..／..／evil.png',
    'one-dot leaders' => '․․/․․/evil.png',
    'quotes and colons' => 'C:"evil".png',
    'very long' => str_repeat('a', 5000).'.png',
]);

it('rejects keep values that are not tokens (AC-7)', function (mixed $keep) {
    $document = fileSecDocument();

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'attachments' => ['present' => 1, 'keep' => [$keep]],
    ])->assertSessionHasErrors('attachments.keep.0');

    expect($document->fresh()->attachments)->toBe($document->attachments)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(3);
})->with([
    'dot-dot path' => '../../.env',
    'absolute path' => '/etc/passwd',
    'windows path' => 'C:\\Windows\\win.ini',
    'stored path instead of a token' => 'a/report.pdf',
    'NUL byte' => "a/report.pdf\0",
    'upper-case token' => fn () => strtoupper(fileSecToken('a/report.pdf')),
    'too long token' => fn () => fileSecToken('a/report.pdf').'0',
    'full HMAC instead of the token' => fn () => hash_hmac('sha256', "local\0a/report.pdf", (string) config('app.key')),
    'path object' => [['path' => 'a/report.pdf', 'name' => 'x.pdf']],
    'unicode lookalike digits' => '٠١٢٣٤٥٦٧٨٩abcdef٠١٢٣٤٥٦٧٨٩abcdef',
]);

it('ignores keep tokens bound to another record, field or disk (AC-7)', function (string $field, Closure $token) {
    $document = fileSecDocument();
    $other = fileSecDocument(prefix: 'other/');

    // The form knew the record's own files and kept only the foreign token.
    $known = [
        'attachments' => [fileSecToken('a/report.pdf'), fileSecToken('a/notes.txt')],
        'secret' => [fileSecToken('s/secret.txt')],
        'cover' => [fileSecToken('c/cover.png', 'public')],
    ][$field];

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        $field => ['present' => 1, 'known' => $known, 'keep' => [$token($document, $other)]],
    ])->assertSessionHasNoErrors();

    $fresh = $document->fresh();

    expect($field === 'attachments' ? $fresh->attachments : $fresh->{$field})->toBeIn([null, []])
        ->and($other->fresh()->only('cover', 'attachments', 'secret'))->toBe($other->only('cover', 'attachments', 'secret'))
        ->and(Storage::disk('local')->exists('other/a/report.pdf'))->toBeTrue()
        ->and(Storage::disk('local')->exists('other/s/secret.txt'))->toBeTrue()
        ->and(Storage::disk('public')->exists('other/c/cover.png'))->toBeTrue();
})->with([
    'attachment of another record' => ['attachments', fn ($d, $o) => fileSecToken('other/a/report.pdf')],
    'secret of another record' => ['attachments', fn ($d, $o) => fileSecToken('other/s/secret.txt')],
    'secret of the same record (another field)' => ['attachments', fn ($d, $o) => fileSecToken('s/secret.txt')],
    'cover of the same record (another disk and field)' => ['attachments', fn ($d, $o) => fileSecToken('c/cover.png', 'public')],
    'same path on another disk' => ['attachments', fn ($d, $o) => fileSecToken('a/report.pdf', 'public')],
    'single field: secret of another record' => ['secret', fn ($d, $o) => fileSecToken('other/s/secret.txt')],
    'single field: attachment of the same record' => ['secret', fn ($d, $o) => fileSecToken('a/report.pdf')],
    'image: cover of another record' => ['cover', fn ($d, $o) => fileSecToken('other/c/cover.png', 'public')],
    'image: same path on the private disk' => ['cover', fn ($d, $o) => fileSecToken('c/cover.png', 'local')],
]);

it('ignores keep tokens replayed after the file was removed or replaced (AC-7)', function () {
    $document = fileSecDocument();
    $report = fileSecToken('a/report.pdf');
    $notes = fileSecToken('a/notes.txt');
    $secret = fileSecToken('s/secret.txt');

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'attachments' => ['present' => 1, 'known' => [$report, $notes], 'keep' => [$notes]],
        'secret' => ['present' => 1, 'known' => [$secret], 'upload' => [Uploads::text('new.txt')]],
    ])->assertSessionHasNoErrors();

    $replaced = $document->fresh()->secret;

    expect(Storage::disk('local')->exists('a/report.pdf'))->toBeFalse()
        ->and(Storage::disk('local')->exists('s/secret.txt'))->toBeFalse();

    // Replay the stale tokens, e.g. from a second browser tab: they neither resurrect the old
    // files nor remove the file that replaced them, which that form never saw (CRF-1).
    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'attachments' => ['present' => 1, 'known' => [$report, $notes], 'keep' => [$report, $notes]],
        'secret' => ['present' => 1, 'known' => [$secret], 'keep' => [$secret]],
    ])->assertSessionHasNoErrors();

    $fresh = $document->fresh();

    expect(array_column($fresh->attachments, 'path'))->toBe(['a/notes.txt'])
        ->and($fresh->secret)->toBe($replaced)
        ->and(Storage::disk('local')->exists($replaced))->toBeTrue();
});

// AC-6/AC-7, NFR-022: mass assignment of paths

it('refuses file field values that are not the upload shape (AC-6, AC-7)', function (string $field, mixed $value, string $error) {
    $document = fileSecDocument();

    $this->put("/admin/entities/documents/{$document->id}", ['title' => 'Doc', $field => $value])
        ->assertSessionHasErrors($error);

    expect($document->fresh()->only('cover', 'cover_name', 'attachments', 'secret'))->toBe($document->only('cover', 'cover_name', 'attachments', 'secret'))
        ->and(fileSecStoredFiles())->toHaveCount(4);
})->with([
    'single: path string' => ['secret', '../../.env', 'secret'],
    'single: path of another file' => ['secret', 'a/report.pdf', 'secret'],
    'single: list of paths' => ['secret', ['a/report.pdf'], 'secret'],
    'single: path object' => ['secret', ['path' => 'a/report.pdf', 'name' => 'x.pdf'], 'secret'],
    'image: path string' => ['cover', 'c/other.png', 'cover'],
    'multiple: list of paths' => ['attachments', ['/etc/passwd', '../.env'], 'attachments'],
    'multiple: list of stored entries' => ['attachments', [['path' => '../../.env', 'name' => 'env', 'size' => 1, 'mime' => 'text/plain']], 'attachments'],
    'multiple: entries next to the upload keys' => ['attachments', ['present' => 1, 'keep' => [], 'files' => [['path' => '../../.env']]], 'attachments'],
    'keep as a string' => ['attachments', ['present' => 1, 'keep' => 'a/report.pdf'], 'attachments.keep'],
    'upload as a path string' => ['attachments', ['present' => 1, 'upload' => '../../.env'], 'attachments.upload'],
    'upload as path objects' => ['attachments', ['present' => 1, 'upload' => [['path' => '../../.env', 'name' => 'env']]], 'attachments.upload.0'],
    'upload as a data URI' => ['secret', ['present' => 1, 'upload' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==']], 'secret.upload.0'],
]);

it('refuses stored paths sent as JSON by API clients (AC-7)', function () {
    $document = fileSecDocument();

    $this->putJson("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'attachments' => [['path' => '../../.env', 'name' => 'env']],
        'secret' => '/etc/passwd',
    ])->assertUnprocessable()->assertJsonValidationErrors(['attachments', 'secret']);

    expect($document->fresh()->only('attachments', 'secret'))->toBe($document->only('attachments', 'secret'));
});

it('never writes the original name column from the request (AC-7)', function () {
    $document = fileSecDocument();

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'cover_name' => '../../evil.html',
        'cover' => ['present' => 1, 'keep' => [fileSecToken('c/cover.png', 'public')]],
    ])->assertSessionHasNoErrors();

    expect($document->fresh()->cover_name)->toBe('cover.png');
});

it('ignores uploads for file fields the user may not edit (AC-7)', function (string $context, Closure $field) {
    DocumentEntity::$factory = fn () => [ID::make(), Text::make('Title'), $field()];
    $document = fileSecDocument();
    $before = fileSecStoredFiles();

    $payload = ['title' => 'Doc', 'secret' => ['present' => 1, 'upload' => [Uploads::php('shell.php')]]];

    $context === 'create'
        ? $this->post('/admin/entities/documents', $payload)->assertSessionHasNoErrors()
        : $this->put("/admin/entities/documents/{$document->id}", $payload)->assertSessionHasNoErrors();

    expect(fileSecStoredFiles())->toBe($before)
        ->and($document->fresh()->secret)->toBe('s/secret.txt')
        ->and(Document::whereKeyNot($document->id)->pluck('secret')->filter()->all())->toBe([]);

    if ($context === 'create') {
        return;
    }

    // Removing the file of a field the user may not edit is ignored as well.
    $this->put("/admin/entities/documents/{$document->id}", ['title' => 'Doc', 'secret' => ['present' => 1]]);

    expect($document->fresh()->secret)->toBe('s/secret.txt')
        ->and(Storage::disk('local')->exists('s/secret.txt'))->toBeTrue();
})->with([
    'readonly on update' => ['update', fn () => File::make('Secret')->disk('local')->readonly()],
    'readonly by a closure' => ['update', fn () => File::make('Secret')->disk('local')->readonly(fn () => true)],
    'canSee false on update' => ['update', fn () => File::make('Secret')->disk('local')->canSee(fn () => false)],
    'hidden when updating' => ['update', fn () => File::make('Secret')->disk('local')->hideWhenUpdating()],
    'detail only' => ['update', fn () => File::make('Secret')->disk('local')->onlyOnDetail()],
    'readonly on create' => ['create', fn () => File::make('Secret')->disk('local')->readonly()],
    'canSee false on create' => ['create', fn () => File::make('Secret')->disk('local')->canSee(fn () => false)],
    'hidden when creating' => ['create', fn () => File::make('Secret')->disk('local')->hideWhenCreating()],
]);

// Orphans (AC-5): rejected requests never write to the disks

it('writes no file when the request is rejected (AC-5)', function (Closure $arrange, string $method, int $status) {
    $document = fileSecDocument();
    $arrange($document);
    $before = fileSecStoredFiles();

    $payload = [
        'title' => 'Doc',
        'cover' => ['present' => 1, 'upload' => [Uploads::png()]],
        'attachments' => ['present' => 1, 'upload' => [Uploads::pdf()]],
        'secret' => ['present' => 1],
    ];

    $response = $method === 'create'
        ? $this->post('/admin/entities/documents', $payload)
        : $this->put("/admin/entities/documents/{$document->id}", $payload);

    $response->assertStatus($status);

    expect(fileSecStoredFiles())->toBe($before)
        ->and($document->fresh()?->only('cover', 'attachments', 'secret'))->toBe($document->only('cover', 'attachments', 'secret'))
        ->and(Document::count())->toBe(1);
})->with([
    'create denied' => [fn () => DocumentPolicy::$denied = ['create'], 'create', 403],
    'update denied' => [fn () => DocumentPolicy::$denied = ['update'], 'update', 403],
    'record outside the detail scope' => [fn () => DocumentEntity::$detailScope = fn (Builder $query) => $query->where('title', 'Other'), 'update', 404],
    'panel access denied' => [fn () => Larsadmin::auth(fn () => false), 'update', 403],
]);

it('writes no file when another field fails validation (AC-5, AC-6)', function () {
    DocumentEntity::$factory = fn () => [
        ID::make(),
        Text::make('Title')->rules('required'),
        Image::make('Cover')->disk('public'),
        File::make('Attachments')->multiple()->maxFiles(3)->disk('local'),
    ];
    $document = fileSecDocument();
    $before = fileSecStoredFiles();

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'cover' => ['present' => 1, 'upload' => [Uploads::png()]],
        'attachments' => ['present' => 1, 'upload' => [Uploads::pdf(), Uploads::php('x.php')]],
    ])->assertSessionHasErrors('attachments.upload.1');

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => '',
        'cover' => ['present' => 1, 'upload' => [Uploads::png()]],
        'attachments' => ['present' => 1, 'upload' => [Uploads::pdf()]],
    ])->assertSessionHasErrors('title');

    expect(fileSecStoredFiles())->toBe($before)
        ->and($document->fresh()->cover)->toBe('c/cover.png');
});

// AC-4: IDOR on the signed download route

it('rejects signed URLs that were altered or replayed elsewhere (AC-4)', function (Closure $url) {
    $document = fileSecDocument();
    $other = Document::create(['title' => 'Other', 'secret' => 's/secret.txt']);

    $this->get($url($document, $other))->assertForbidden();
})->with([
    'record A signature on record B with the same file' => [fn ($d, $o) => str_replace("/documents/{$d->id}/", "/documents/{$o->id}/", fileSecUrl($d, 'secret', 's/secret.txt'))],
    'token swapped for another file' => [fn ($d, $o) => str_replace(fileSecToken('s/secret.txt'), fileSecToken('a/report.pdf'), fileSecUrl($d, 'secret', 's/secret.txt'))],
    'extra query parameter' => [fn ($d, $o) => fileSecUrl($d, 'secret', 's/secret.txt').'&download=1&x=1'],
    'expires extended' => [fn ($d, $o) => preg_replace('/expires=\d+/', 'expires='.(time() + 86400 * 365), fileSecUrl($d, 'secret', 's/secret.txt'))],
    'signature of another URL' => [fn ($d, $o) => preg_replace('/signature=\w+/', 'signature='.Str::after(fileSecUrl($d, 'attachments', 'a/report.pdf'), 'signature='), fileSecUrl($d, 'secret', 's/secret.txt'))],
    'truncated signature' => [fn ($d, $o) => substr(fileSecUrl($d, 'secret', 's/secret.txt'), 0, -2)],
    'absolute signature checked as relative' => [fn ($d, $o) => fileSecUrl($d, 'secret', 's/secret.txt', absolute: true)],
    'expired' => [fn ($d, $o) => fileSecUrl($d, 'secret', 's/secret.txt', minutes: -1)],
]);

it('expires signed URLs after url_ttl and never lets the browser store attachments (AC-4, AC-8, SECF-7)', function () {
    config(['larsadmin.files.url_ttl' => 5]);
    $document = fileSecDocument();

    $url = fileSecDetailFiles($document, 'secret')[0]['downloadUrl'];
    $response = $this->get($url)->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store')->not->toContain('max-age');

    // The expiry is rounded up to a quarter of the TTL (CRF-8): valid for 5 to 6.25 minutes.
    $this->travel(4)->minutes();
    $this->get($url)->assertOk();

    $this->travel(3)->minutes();
    $this->get($url)->assertForbidden();
});

it('answers the download route like the detail page, without an existence oracle (AC-4)', function (Closure $arrange, int $status) {
    DocumentEntity::$model = SoftDocument::class;
    $document = fileSecDocument(SoftDocument::class);
    $arrange($document);

    $this->get("/admin/entities/documents/{$document->id}")->assertStatus($status);
    $this->get(fileSecUrl($document, 'secret', 's/secret.txt'))->assertStatus($status);
})->with([
    'allowed' => [fn () => null, 200],
    'viewAny denied' => [fn () => DocumentPolicy::$denied = ['viewAny'], 403],
    'view denied' => [fn () => DocumentPolicy::$denied = ['view'], 403],
    'outside the detail scope' => [fn () => DocumentEntity::$detailScope = fn (Builder $query) => $query->whereKey(0), 404],
    'missing record' => [fn ($d) => $d->forceDelete(), 404],
    'soft-deleted record' => [fn ($d) => $d->delete(), 404],
    'panel access denied' => [fn () => Larsadmin::auth(fn () => false), 403],
]);

it('scopes downloads by the detail query, not the index query (AC-4)', function () {
    $entity = new class(new Document) extends DocumentEntity
    {
        public static function indexQuery(Request $request, Builder $query): Builder
        {
            return $query->whereKey(0);
        }
    };
    Larsadmin::entities([$entity::class]);
    $document = fileSecDocument();

    $this->get("/admin/entities/documents/{$document->id}")->assertOk();
    $this->get(fileSecUrl($document, 'secret', 's/secret.txt'))->assertOk();
});

it('keeps the files of a soft-deleted record but stops serving them (AC-4, AC-5)', function () {
    DocumentEntity::$model = SoftDocument::class;
    $document = fileSecDocument(SoftDocument::class);
    $url = fileSecUrl($document, 'secret', 's/secret.txt');

    $this->get($url)->assertOk();
    $this->delete("/admin/entities/documents/{$document->id}")->assertRedirect();

    $this->get($url)->assertNotFound();

    expect(fileSecStoredFiles())->toHaveCount(4);
});

it('sends guests to the login page whatever the signature (AC-4)', function (Closure $url) {
    $document = fileSecDocument();
    $target = $url($document);

    Auth::logout();

    $this->get($target)->assertRedirect('/admin/login');
})->with([
    'valid' => [fn ($d) => fileSecUrl($d, 'secret', 's/secret.txt')],
    'tampered' => [fn ($d) => fileSecUrl($d, 'secret', 's/secret.txt').'x'],
    'missing record' => [fn ($d) => fileSecUrl(999, 'secret', 's/secret.txt')],
]);

it('never serves or prunes unsafe paths found in the database (AC-7)', function (string $field, mixed $stored, string $path) {
    Storage::disk('local')->put('victim.txt', 'victim');
    Storage::disk('local')->put('a/victim.txt', 'victim');

    $document = Document::create(['title' => 'Doc', $field => $stored]);

    $this->get(fileSecUrl($document, $field, $path))->assertNotFound();
    expect(collect(fileSecDetailFiles($document, $field)))->toBeEmpty();

    $this->delete("/admin/entities/documents/{$document->id}")->assertRedirect();

    expect(Document::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toEqualCanonicalizing(['victim.txt', 'a/victim.txt']);
})->with([
    'dot-dot single' => ['secret', 'a/../victim.txt', 'a/../victim.txt'],
    'leading dot-dot single' => ['secret', '../victim.txt', '../victim.txt'],
    'absolute single' => ['secret', '/victim.txt', '/victim.txt'],
    'windows drive single' => ['secret', 'C:/victim.txt', 'C:/victim.txt'],
    'backslash single' => ['secret', 'a\\..\\victim.txt', 'a\\..\\victim.txt'],
    'dot segment single' => ['secret', './victim.txt', './victim.txt'],
    'double slash single' => ['secret', 'a//victim.txt', 'a//victim.txt'],
    'control char single' => ['secret', "a/victim.txt\n", "a/victim.txt\n"],
    'dot-dot multiple' => ['attachments', [['path' => 'a/../victim.txt', 'name' => 'v.txt']], 'a/../victim.txt'],
    'absolute multiple' => ['attachments', [['path' => '/victim.txt', 'name' => 'v.txt']], '/victim.txt'],
    'legacy string multiple' => ['attachments', ['../victim.txt'], '../victim.txt'],
]);

// AC-8: response headers

it('encodes hostile original names safely in Content-Disposition (AC-8)', function (string $name, string $disposition) {
    DocumentEntity::$factory = fn () => [ID::make(), Text::make('Title'), File::make('Attachments')->multiple()->disk('local')];

    $this->post('/admin/entities/documents', ['title' => 'X', 'attachments' => ['upload' => [Uploads::pdf($name)]]])
        ->assertSessionHasNoErrors();

    $document = Document::firstOrFail();
    $response = $this->get(fileSecDetailFiles($document, 'attachments')[0]['downloadUrl'])->assertOk();

    expect($response->headers->all('content-disposition'))->toBe([$disposition])
        ->and($disposition)->not->toMatch('/[\r\n\x00]/');

    fileSecAssertAttachment($response);
})->with([
    'CRLF header injection' => ["Invoice\r\nSet-Cookie: session=evil\r\n.pdf", 'attachment; filename="InvoiceSet-Cookie session=evil.pdf"'],
    'quotes closing the parameter' => ['a"; filename="evil.pdf', 'attachment; filename="a; filename=evil.pdf"'],
    'parameter injection' => ['a.pdf; filename*=utf-8\'\'evil.pdf', "attachment; filename=\"a.pdf; filename=utf-8''evil.pdf\""],
    'backslash path' => ['100% ..\\done.pdf', 'attachment; filename=done.pdf'],
    'percent sign' => ['100% done.pdf', "attachment; filename=\"100 done.pdf\"; filename*=utf-8''100%25%20done.pdf"],
    'unicode' => ['Звіт "Q3".pdf', "attachment; filename=\"Zvit Q3.pdf\"; filename*=utf-8''%D0%97%D0%B2%D1%96%D1%82%20Q3.pdf"],
    'only stripped characters' => ['<>:*?|.pdf', "attachment; filename=download.pdf; filename*=utf-8''.pdf"],
]);

it('strips bidirectional overrides that disguise the download name (AC-8)', function () {
    DocumentEntity::$factory = fn () => [ID::make(), Text::make('Title'), File::make('Attachments')->multiple()->disk('local')];

    $this->post('/admin/entities/documents', ['title' => 'X', 'attachments' => ['upload' => [Uploads::pdf("invoice\u{202E}fdp.txt")]]])
        ->assertSessionHasNoErrors();

    $document = Document::firstOrFail();
    $response = $this->get(fileSecDetailFiles($document, 'attachments')[0]['downloadUrl'])->assertOk();

    expect($response->headers->get('Content-Disposition'))->not->toContain('%E2%80%AE')
        ->and(fileSecDetailFiles($document, 'attachments')[0]['name'])->toBe('invoicefdp.txt');
});

// Resource abuse

it('caps the number of keep tokens before matching them (AC-6)', function () {
    $document = fileSecDocument();
    $token = fileSecToken('a/report.pdf');

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'attachments' => ['present' => 1, 'keep' => array_fill(0, 1000, $token)],
    ])->assertSessionHasErrors('attachments.keep');

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'secret' => ['present' => 1, 'keep' => [fileSecToken('s/secret.txt'), fileSecToken('s/secret.txt')]],
    ])->assertSessionHasErrors('secret.keep');

    expect($document->fresh()->attachments)->toBe($document->attachments);
});

it('counts kept files against maxFiles so uploads cannot exceed it (AC-6)', function () {
    $document = fileSecDocument();

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'attachments' => [
            'present' => 1,
            'keep' => [fileSecToken('a/report.pdf'), fileSecToken('a/notes.txt')],
            'upload' => [Uploads::text('3.txt'), Uploads::text('4.txt')],
        ],
    ])->assertSessionHasErrors('attachments');

    expect(fileSecStoredFiles())->toHaveCount(4);
});

it('rejects a body larger than post_max_size before the package runs (413)', function () {
    $limit = File::iniBytes((string) ini_get('post_max_size'));

    if ($limit === null) {
        $this->markTestSkipped('post_max_size is unlimited.');
    }

    $this->call('POST', '/admin/entities/documents', ['title' => 'X'], [], [], ['CONTENT_LENGTH' => (string) ($limit + 1)])
        ->assertStatus(413);

    expect(Document::count())->toBe(0)->and(fileSecStoredFiles())->toBe([]);
});

it('throttles downloads per user across every file (AC-4)', function () {
    config(['larsadmin.files.throttle' => 2]);
    $document = fileSecDocument();

    $this->get(fileSecUrl($document, 'secret', 's/secret.txt'))->assertOk();
    $this->get(fileSecUrl($document, 'attachments', 'a/report.pdf'))->assertOk();
    $this->get(fileSecUrl($document, 'attachments', 'a/notes.txt'))->assertTooManyRequests();

    $this->actingAs(User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'secret']));

    $this->get(fileSecUrl($document, 'secret', 's/secret.txt'))->assertOk();
});

it('throttles signature guessing as well as downloads (AC-4)', function () {
    config(['larsadmin.files.throttle' => 2]);
    $document = fileSecDocument();

    $this->get(fileSecUrl($document, 'secret', 's/secret.txt').'0')->assertForbidden();
    $this->get(fileSecUrl($document, 'secret', 's/secret.txt').'1')->assertForbidden();
    $this->get(fileSecUrl($document, 'secret', 's/secret.txt').'2')->assertTooManyRequests();
    $this->get(fileSecUrl($document, 'secret', 's/secret.txt'))->assertTooManyRequests();
});
