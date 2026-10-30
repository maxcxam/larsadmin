<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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
use Maxcxam\Larsadmin\Tests\Fixtures\Files\Uploads;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([DocumentEntity::class]);
    DocumentEntity::reset();
    DocumentPolicy::$denied = [];
    Gate::policy(Document::class, DocumentPolicy::class);

    Storage::fake('public');
    Storage::fake('local');

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
});

afterEach(function () {
    DocumentEntity::reset();
    DocumentPolicy::$denied = [];
});

/**
 * A document with a private PDF attachment (with a hostile name), a private PNG and a cover.
 */
function downloadDocument(array $attributes = []): Document
{
    Storage::disk('local')->put('a/report.pdf', '%PDF-1.4 report');
    Storage::disk('local')->put('a/photo.png', base64_decode(Uploads::PNG));
    Storage::disk('local')->put('s/secret.txt', 'top secret');

    return Document::create(array_merge([
        'title' => 'Doc',
        'attachments' => [
            ['path' => 'a/report.pdf', 'name' => "Звіт \"Q3\"\r\n.pdf"],
            ['path' => 'a/photo.png', 'name' => 'photo.png'],
        ],
        'secret' => 's/secret.txt',
    ], $attributes));
}

/**
 * A relative signed download URL, as the field emits it.
 */
function fileUrl(Document|int|string $document, string $field, string $path, array $query = [], string $disk = 'local', int $minutes = 30): string
{
    return URL::temporarySignedRoute('larsadmin.files.show', now()->addMinutes($minutes), [
        'entity' => 'documents',
        'id' => $document instanceof Document ? $document->id : $document,
        'field' => $field,
        'token' => (new StoredFile($path, basename($path)))->token($disk),
        ...$query,
    ], false);
}

it('streams a private file as an octet-stream attachment with safe headers (AC-8)', function () {
    $document = downloadDocument();

    $response = $this->get(fileUrl($document, 'attachments', 'a/report.pdf'))->assertOk();

    expect($response->streamedContent())->toBe('%PDF-1.4 report')
        ->and($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($response->headers->get('Content-Disposition'))
        ->toBe("attachment; filename=\"Zvit Q3.pdf\"; filename*=utf-8''%D0%97%D0%B2%D1%96%D1%82%20Q3.pdf")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe(FileResponse::CONTENT_SECURITY_POLICY)
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin')
        ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store')->not->toContain('max-age')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

it('serves raster images inline unless a download is requested (AC-8)', function () {
    $document = downloadDocument();

    $inline = $this->get(fileUrl($document, 'attachments', 'a/photo.png'))->assertOk();

    expect($inline->headers->get('Content-Type'))->toBe('image/png')
        ->and($inline->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=1800')
        ->and($inline->headers->get('Content-Disposition'))->toBe('inline; filename=photo.png')
        ->and($inline->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    $download = $this->get(fileUrl($document, 'attachments', 'a/photo.png', ['download' => 1]))->assertOk();

    expect($download->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($download->headers->get('Content-Disposition'))->toBe('attachment; filename=photo.png');
});

it('never serves non-raster types inline (AC-8)', function () {
    Storage::disk('local')->put('a/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $document = Document::create(['title' => 'Svg', 'attachments' => [['path' => 'a/logo.svg', 'name' => 'logo.svg']]]);

    $response = $this->get(fileUrl($document, 'attachments', 'a/logo.svg'))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;');
});

it('serves the URLs the detail page emits', function () {
    $document = downloadDocument();
    $fields = DocumentEntity::make($document)->serializeRecord(request(), 'detail')['fields'];
    $attachments = $fields->firstWhere('attribute', 'attachments')->jsonSerialize();

    $this->get($attachments['files'][0]['downloadUrl'])->assertOk()->assertDownload();
    $this->get($attachments['files'][1]['url'])->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('validates relative signatures behind another host', function () {
    $document = downloadDocument();

    $this->get('https://proxy.example.org'.fileUrl($document, 'secret', 's/secret.txt'))->assertOk();
});

it('rejects missing, tampered and expired signatures (AC-4)', function () {
    $document = downloadDocument();
    $url = fileUrl($document, 'secret', 's/secret.txt');
    $token = (new StoredFile('s/secret.txt', 'x'))->token('local');

    $this->get("/admin/files/documents/{$document->id}/secret/{$token}")->assertForbidden();
    $this->get($url.'&download=1')->assertForbidden();
    $this->get(str_replace('/secret/', '/attachments/', $url))->assertForbidden();

    $expiring = fileUrl($document, 'secret', 's/secret.txt', minutes: 1);
    $this->travel(2)->minutes();

    $this->get($expiring)->assertForbidden();
});

it('requires a panel login', function () {
    $document = downloadDocument();
    $url = fileUrl($document, 'secret', 's/secret.txt');

    Auth::logout();

    $this->get($url)->assertRedirect('/admin/login');
});

it('re-authorizes the record like the detail page (AC-4)', function (Closure $arrange, Closure $url, int $status) {
    $document = downloadDocument();
    $arrange($document);

    $this->get($url($document))->assertStatus($status);
})->with([
    'unknown entity' => [fn () => null, fn ($d) => str_replace('/documents/', '/missing/', fileUrl($d, 'secret', 's/secret.txt')), 403],
    'viewAny denied' => [fn () => DocumentPolicy::$denied = ['viewAny'], fn ($d) => fileUrl($d, 'secret', 's/secret.txt'), 403],
    'non-numeric id' => [fn () => null, fn ($d) => fileUrl('1x', 'secret', 's/secret.txt'), 404],
    'missing record' => [fn () => null, fn ($d) => fileUrl(999, 'secret', 's/secret.txt'), 404],
    'outside the detail scope' => [fn () => DocumentEntity::$detailScope = fn ($query) => $query->where('title', 'Other'), fn ($d) => fileUrl($d, 'secret', 's/secret.txt'), 404],
    'view denied' => [fn () => DocumentPolicy::$denied = ['view'], fn ($d) => fileUrl($d, 'secret', 's/secret.txt'), 403],
    'unknown field' => [fn () => null, fn ($d) => fileUrl($d, 'nothing', 's/secret.txt'), 404],
    'not a file field' => [fn () => null, fn ($d) => fileUrl($d, 'title', 's/secret.txt'), 404],
    'token of another field' => [fn () => null, fn ($d) => fileUrl($d, 'secret', 'a/report.pdf'), 404],
    'token of another disk' => [fn () => null, fn ($d) => fileUrl($d, 'secret', 's/secret.txt', disk: 'public'), 404],
    'file missing on disk' => [fn () => Storage::disk('local')->delete('s/secret.txt'), fn ($d) => fileUrl($d, 'secret', 's/secret.txt'), 404],
    'allowed' => [fn () => null, fn ($d) => fileUrl($d, 'secret', 's/secret.txt'), 200],
]);

it('answers 404 for an unknown entity before checking abilities', function () {
    $document = downloadDocument();

    $url = URL::temporarySignedRoute('larsadmin.files.show', now()->addMinutes(5), [
        'entity' => 'missing', 'id' => $document->id, 'field' => 'secret', 'token' => str_repeat('a', 32),
    ], false);

    $this->get($url)->assertNotFound();
});

it('does not serve a token of another record or a removed file (AC-4)', function () {
    $document = downloadDocument();
    Storage::disk('local')->put('s/other.txt', 'other');
    $other = Document::create(['title' => 'Other', 'secret' => 's/other.txt']);

    $this->get(fileUrl($other, 'secret', 's/secret.txt'))->assertNotFound();
    $this->get(fileUrl($document, 'secret', 's/other.txt'))->assertNotFound();

    $url = fileUrl($document, 'secret', 's/secret.txt');
    $document->update(['secret' => null]);

    $this->get($url)->assertNotFound();
    expect(Storage::disk('local')->exists('s/secret.txt'))->toBeTrue();
});

it('rejects malformed tokens at the route', function () {
    $document = downloadDocument();

    $this->get("/admin/files/documents/{$document->id}/secret/..%2F..%2F.env")->assertNotFound();
    $this->get("/admin/files/documents/{$document->id}/secret/".str_repeat('A', 32))->assertNotFound();
});

it('honours canSee() and context visibility (AC-4)', function () {
    $document = downloadDocument();

    DocumentEntity::$factory = fn () => [ID::make(), Text::make('Title'), File::make('Secret')->disk('local')->canSee(fn () => false)];
    $this->get(fileUrl($document, 'secret', 's/secret.txt'))->assertNotFound();

    DocumentEntity::$factory = fn () => [ID::make(), File::make('Secret')->disk('local')->onlyOnForms()];
    $this->get(fileUrl($document, 'secret', 's/secret.txt'))->assertOk();

    DocumentPolicy::$denied = ['update'];
    $this->get(fileUrl($document, 'secret', 's/secret.txt'))->assertNotFound();

    DocumentEntity::$factory = fn () => [ID::make(), File::make('Secret')->disk('local')->onlyOnIndex()];
    $this->get(fileUrl($document, 'secret', 's/secret.txt'))->assertOk();

    DocumentEntity::$factory = fn () => [ID::make(), File::make('Secret')->disk('local')->showOnDetail(fn ($request, $record) => $record->title === 'Doc')->hideFromIndex()->hideWhenUpdating()];
    $this->get(fileUrl($document, 'secret', 's/secret.txt'))->assertOk();

    $document->update(['title' => 'Changed']);
    $this->get(fileUrl($document, 'secret', 's/secret.txt'))->assertNotFound();
});

it('serves files of other disks through the route with the same headers', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('img/photo.png', base64_decode(Uploads::PNG));

    DocumentEntity::$factory = fn () => [ID::make(), Image::make('Cover')->disk('s3')];
    $document = Document::create(['title' => 'S3', 'cover' => 'img/photo.png']);

    $this->get(fileUrl($document, 'cover', 'img/photo.png', disk: 's3'))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('throttles downloads per user', function () {
    config(['larsadmin.files.throttle' => 2]);
    $document = downloadDocument();
    $url = fileUrl($document, 'secret', 's/secret.txt');

    $this->get($url)->assertOk();
    $this->get($url)->assertOk();
    $this->get($url)->assertTooManyRequests();

    config(['larsadmin.files.throttle' => null]);

    foreach (range(1, 3) as $i) {
        $this->get($url)->assertOk();
    }
});
