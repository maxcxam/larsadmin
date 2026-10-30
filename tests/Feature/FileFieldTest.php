<?php

use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use League\Flysystem\Filesystem;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Fields\File;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Image;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Files\FileTypes;
use Maxcxam\Larsadmin\Files\StoredFile;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Rules\FileFieldState;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\Document;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\DocumentPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\Entities\Document as DocumentEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\RawDocument;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\ThrowingAdapter;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\Uploads;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([DocumentEntity::class]);
    DocumentEntity::reset();
    DocumentPolicy::$denied = [];

    Storage::fake('public');
    Storage::fake('local');

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
});

afterEach(function () {
    DocumentEntity::reset();
    DocumentPolicy::$denied = [];
});

/**
 * A document whose files already exist on the fake disks.
 */
function fileDocument(array $attributes = []): Document
{
    Storage::disk('public')->put('larsadmin/documents/cover/old.png', base64_decode(Uploads::PNG));
    Storage::disk('local')->put('larsadmin/documents/attachments/a.pdf', '%PDF-1.4');
    Storage::disk('local')->put('larsadmin/documents/attachments/b.txt', 'b');

    return Document::create(array_merge([
        'title' => 'Doc',
        'cover' => 'larsadmin/documents/cover/old.png',
        'cover_name' => 'sunset.png',
        'attachments' => [
            ['path' => 'larsadmin/documents/attachments/a.pdf', 'name' => 'A.pdf', 'size' => 8, 'mime' => 'application/pdf'],
            ['path' => 'larsadmin/documents/attachments/b.txt', 'name' => 'B.txt', 'size' => 1, 'mime' => 'text/plain'],
        ],
    ], $attributes));
}

function fileToken(string $disk, string $path): string
{
    return (new StoredFile($path, basename($path)))->token($disk);
}

/**
 * The tokens of the attachments fileDocument() stores, as the edit form sends them in "known".
 *
 * @return list<string>
 */
function fileKnown(): array
{
    return [
        fileToken('local', 'larsadmin/documents/attachments/a.pdf'),
        fileToken('local', 'larsadmin/documents/attachments/b.txt'),
    ];
}

/**
 * The bound prototype of a field of the document entity.
 */
function documentField(string $attribute): File
{
    return DocumentEntity::make()->fieldPrototypes(request())->first(fn (Field $field) => $field->attribute === $attribute);
}

// Definition-time checks (AC-10)

it('rejects an unknown disk when the field is bound (AC-10)', function () {
    DocumentEntity::$factory = fn () => [File::make('Secret')->disk('missing')];

    expect(fn () => DocumentEntity::make()->fieldPrototypes(request()))
        ->toThrow(InvalidArgumentException::class, 'The disk [missing]');
});

it('rejects unsafe static paths (AC-10)', function (string $path) {
    expect(fn () => File::make('Secret')->path($path))->toThrow(InvalidArgumentException::class, 'Invalid path');
})->with([
    'empty' => [''],
    'absolute' => ['/etc'],
    'parent' => ['docs/../../etc'],
    'dot' => ['docs/./x'],
    'backslash' => ['docs\\x'],
    'nul' => ["docs\0x"],
    'empty segment' => ['docs//x'],
    'trailing slash' => ['docs/'],
    'space' => ['my docs'],
    'drive' => ['C:/docs'],
]);

it('accepts a safe relative path', function () {
    expect(File::make('Secret')->path('posts/attachments-2026_v1.0'))->toBeInstanceOf(File::class);
});

it('rejects invalid sizes, counts, types and option combinations (AC-10)', function () {
    expect(fn () => File::make('Secret')->maxSize(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => File::make('Secret')->maxFiles(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => File::make('Secret')->types(['p df']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => File::make('Secret')->types([]))->toThrow(InvalidArgumentException::class, 'at least one extension')
        ->and(fn () => Image::make('Cover')->types([]))->toThrow(InvalidArgumentException::class, 'at least one extension')
        ->and(fn () => Image::make('Cover')->types(['png', 'pdf']))->toThrow(InvalidArgumentException::class, 'accepts only')
        ->and(fn () => File::make('Secret')->multiple()->storeOriginalName('name'))->toThrow(LogicException::class)
        ->and(fn () => File::make('Secret')->storeOriginalName('name')->multiple())->toThrow(LogicException::class)
        ->and(fn () => File::make('Secret')->sortable())->toThrow(LogicException::class, 'cannot be sortable')
        ->and(fn () => Image::make('Cover')->types(['png', '.SVG']))->toThrow(InvalidArgumentException::class, 'SVG')
        ->and(File::make('Secret')->sortable(false)->sortable)->toBeFalse();
});

// Rules

it('builds nested rules with the upload rules applied per file', function () {
    $field = File::make('Attachments')->multiple()->maxFiles(4)->maxSize(512)->types(['pdf', '.TXT'])
        ->rules('required', 'nullable', 'dimensions:min_width=10');

    $rules = $field->rulesFor(Field::CREATE);

    expect(array_keys($rules))->toBe([
        'attachments', 'attachments.present', 'attachments.known', 'attachments.known.*',
        'attachments.keep', 'attachments.keep.*', 'attachments.upload', 'attachments.upload.*',
    ])
        ->and($rules['attachments'][0])->toBe('array:present,known,keep,upload')
        ->and($rules['attachments.known'])->toBe(['sometimes', 'array', 'max:4'])
        ->and($rules['attachments.known.*'])->toBe(['string', 'regex:/^[a-f0-9]{32}$/'])
        ->and($rules['attachments'][1])->toBeInstanceOf(FileFieldState::class)
        ->and($rules['attachments.keep'])->toBe(['sometimes', 'array', 'max:4'])
        ->and($rules['attachments.upload'])->toBe(['sometimes', 'array', 'max:4'])
        ->and($rules['attachments.upload.*'])->toContain('file', 'max:512', 'mimes:pdf,txt', 'extensions:pdf,txt', 'dimensions:min_width=10')
        ->and($rules['attachments.upload.*'])->not->toContain('required')
        ->and($rules['attachments.upload.*'])->not->toContain('nullable')
        ->and($field->isRequired())->toBeTrue()
        ->and(File::make('Secret')->updateRules('required')->isRequired())->toBeFalse()
        ->and(File::make('Secret')->isRequiredOn(Field::UPDATE))->toBeFalse();
});

it('restricts Image fields to the raster types by content and name, without the image rule', function () {
    $rules = Image::make('Cover')->rulesFor(Field::CREATE)['cover.upload.*'];

    expect($rules)->toContain('mimes:jpg,jpeg,png,gif,webp,avif', 'extensions:jpg,jpeg,png,gif,webp,avif')
        ->and($rules)->not->toContain('image');
});

it('accepts a real AVIF upload in an Image field', function () {
    $this->post('/admin/entities/documents', [
        'title' => 'Avif',
        'cover' => ['present' => 1, 'upload' => [Uploads::avif()]],
    ])->assertSessionHasNoErrors();

    expect(Document::firstOrFail()->cover)->toMatch('#^larsadmin/documents/cover/[A-Za-z0-9]{40}\.avif$#');
});

it('names every validated key after the field', function () {
    expect(File::make('Вкладення', 'attachments')->validationAttributeNames())->toBe([
        'attachments' => 'Вкладення',
        'attachments.present' => 'Вкладення',
        'attachments.known' => 'Вкладення',
        'attachments.known.*' => 'Вкладення',
        'attachments.keep' => 'Вкладення',
        'attachments.keep.*' => 'Вкладення',
        'attachments.upload' => 'Вкладення',
        'attachments.upload.*' => 'Вкладення',
    ])->and(Text::make('Title')->validationAttributeNames())->toBe(['title' => 'Title']);
});

it('converts php.ini sizes to bytes', function () {
    expect(File::iniBytes('2M'))->toBe(2 * 1024 * 1024)
        ->and(File::iniBytes('512k'))->toBe(512 * 1024)
        ->and(File::iniBytes('1G'))->toBe(1024 ** 3)
        ->and(File::iniBytes('100'))->toBe(100)
        ->and(File::iniBytes('0'))->toBeNull()
        ->and(File::iniBytes('-1'))->toBeNull()
        ->and(File::iniBytes(''))->toBeNull()
        ->and(File::iniBytes('lots'))->toBeNull();
});

// Storage (AC-1, AC-3)

it('stores a single upload under a random name with the guessed extension (AC-1)', function () {
    $this->post('/admin/entities/documents', [
        'title' => 'New',
        'cover' => ['present' => 1, 'upload' => [Uploads::png('My "holiday".PNG')]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $document = Document::firstOrFail();

    expect($document->cover)->toMatch('#^larsadmin/documents/cover/[A-Za-z0-9]{40}\.png$#')
        ->and($document->cover_name)->toBe('My holiday.PNG')
        ->and(Storage::disk('public')->exists($document->cover))->toBeTrue()
        ->and(Storage::disk('public')->getVisibility($document->cover))->toBe('public');
});

it('takes the stored extension from the content, not the client name (AC-1)', function () {
    $this->post('/admin/entities/documents', [
        'title' => 'New',
        'attachments' => ['present' => 1, 'upload' => [Uploads::pdf('report.txt')]],
    ])->assertSessionHasNoErrors();

    $entry = Document::firstOrFail()->attachments[0];

    expect($entry['path'])->toMatch('#^larsadmin/documents/attachments/[A-Za-z0-9]{40}\.pdf$#')
        ->and($entry['name'])->toBe('report.txt')
        ->and($entry['mime'])->toBe('application/pdf')
        ->and($entry['size'])->toBeGreaterThan(0);
});

it('stores uploads under a static or a closure path', function () {
    DocumentEntity::$factory = fn () => [
        Text::make('Title'),
        File::make('Secret')->path('vault/docs'),
        File::make('Attachments')->multiple()->path(fn (Request $request, Document $record) => 'by-title/'.$record->title),
    ];

    $this->post('/admin/entities/documents', [
        'title' => 'quarterly',
        'secret' => ['present' => 1, 'upload' => [Uploads::text()]],
        'attachments' => ['present' => 1, 'upload' => [Uploads::text()]],
    ])->assertSessionHasNoErrors();

    $document = Document::firstOrFail();

    expect($document->secret)->toStartWith('vault/docs/')
        ->and($document->attachments[0]['path'])->toStartWith('by-title/quarterly/');
});

it('keeps the kept files in their order, then appends uploads, and removes the rest (AC-3)', function () {
    $document = fileDocument();

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'attachments' => [
            'present' => 1,
            'known' => fileKnown(),
            'keep' => [fileToken('local', 'larsadmin/documents/attachments/b.txt')],
            'upload' => [Uploads::pdf('new.pdf')],
        ],
    ])->assertSessionHasNoErrors();

    $attachments = $document->fresh()->attachments;

    expect(array_column($attachments, 'name'))->toBe(['B.txt', 'new.pdf'])
        ->and($attachments[0]['path'])->toBe('larsadmin/documents/attachments/b.txt')
        ->and($document->fresh()->cover)->toBe('larsadmin/documents/cover/old.png');
});

it('reorders kept files and ignores duplicates', function () {
    $document = fileDocument();
    $a = fileToken('local', 'larsadmin/documents/attachments/a.pdf');
    $b = fileToken('local', 'larsadmin/documents/attachments/b.txt');

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'attachments' => ['present' => 1, 'keep' => [$b, $a, $b]],
    ])->assertSessionHasNoErrors();

    expect(array_column($document->fresh()->attachments, 'name'))->toBe(['B.txt', 'A.pdf']);
});

it('writes and reads multiple files as a JSON string without an array cast (AC-3)', function () {
    DocumentEntity::$model = RawDocument::class;

    $this->post('/admin/entities/documents', [
        'title' => 'Raw',
        'attachments' => ['present' => 1, 'upload' => [Uploads::text('звіт/../жовтень.txt')]],
    ])->assertSessionHasNoErrors();

    $raw = RawDocument::firstOrFail()->attachments;
    $decoded = json_decode($raw, true);

    expect($raw)->toBeString()->toContain('жовтень.txt')->not->toContain('\\/')
        ->and($decoded[0]['name'])->toBe('жовтень.txt')
        ->and(documentField('attachments')->filesOf(RawDocument::firstOrFail()))->toHaveCount(1);
});

it('reads stored values tolerantly', function () {
    $field = documentField('attachments');

    $files = $field->filesOf(new RawDocument(['attachments' => json_encode([
        'legacy/one.pdf',
        ['path' => 'two.png', 'name' => "bad\nname\".png", 'size' => 5, 'mime' => 'image/png'],
        ['path' => '../escape.txt'],
        ['path' => '/abs.txt'],
        ['name' => 'no-path'],
        ['path' => 'two.png'],
        42,
    ])]));

    expect(array_map(fn (StoredFile $file) => [$file->path, $file->name], $files))->toBe([
        ['legacy/one.pdf', 'Attachments (1).pdf'],
        ['two.png', 'badname.png'],
    ])
        ->and($field->filesOf(new RawDocument(['attachments' => ''])))->toBe([])
        ->and($field->filesOf(new RawDocument(['attachments' => null])))->toBe([])
        ->and($field->filesOf(new RawDocument(['attachments' => 'not json'])))->toBe([])
        ->and(documentField('cover')->filesOf(new Document(['cover' => '../../.env'])))->toBe([])
        ->and(documentField('cover')->filesOf(new Document(['cover' => 'c.png']))[0]->name)->toBe('Cover.png')
        ->and(documentField('cover')->filesOf(new Document(['cover' => 'c.png', 'cover_name' => '']))[0]->name)->toBe('Cover.png')
        ->and(documentField('secret')->filesOf(new Document(['secret' => 'x/noext']))[0]->name)->toBe('Secret');
});

it('names files without a stored name after the field label (CRF-6)', function () {
    $field = File::make('Вкладення', 'attachments')->multiple();

    $files = $field->filesOf(new Document(['attachments' => [
        ['path' => 'a/x1.pdf'],
        ['path' => 'a/x2.png', 'name' => 'photo.png'],
        ['path' => 'a/x3.txt', 'name' => "\u{202E}"],
    ]]));

    expect(array_map(fn (StoredFile $file) => $file->name, $files))->toBe(['Вкладення (1).pdf', 'photo.png', 'Вкладення (3).txt']);
});

it('names an upload with an empty client name after the field label (CRF-6)', function () {
    $this->post('/admin/entities/documents', [
        'title' => 'Nameless',
        'attachments' => ['present' => 1, 'upload' => [Uploads::pdf('<>|'), Uploads::pdf('ok.pdf')]],
    ])->assertSessionHasNoErrors();

    expect(array_column(Document::firstOrFail()->attachments, 'name'))->toBe(['Attachments.pdf', 'ok.pdf']);
});

it('reads collections, array objects and objects', function () {
    $field = documentField('attachments');
    $entries = [['path' => 'a.pdf', 'name' => 'A.pdf']];

    foreach ([collect($entries), new ArrayObject($entries), (object) ['first' => (object) $entries[0]]] as $raw) {
        $record = new RawDocument;
        $record->setRawAttributes(['attachments' => $raw]);

        expect($field->filesOf($record)[0]->name)->toBe('A.pdf');
    }
});

it('assigns an array to columns cast as a collection', function () {
    $model = new class extends Document
    {
        protected function casts(): array
        {
            return ['attachments' => AsCollection::class];
        }
    };

    DocumentEntity::$model = $model::class;

    $this->post('/admin/entities/documents', [
        'title' => 'Collection',
        'attachments' => ['present' => 1, 'upload' => [Uploads::text('c.txt')]],
    ])->assertSessionHasNoErrors();

    $stored = $model::query()->firstOrFail()->attachments;

    expect($stored)->toBeInstanceOf(Collection::class)
        ->and($stored->first()['name'])->toBe('c.txt');
});

it('replaces a single file even when its token is kept', function () {
    $document = fileDocument();

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'cover' => [
            'present' => 1,
            'keep' => [fileToken('public', 'larsadmin/documents/cover/old.png')],
            'upload' => [Uploads::png('new.png')],
        ],
    ])->assertSessionHasNoErrors();

    expect($document->fresh()->cover)->not->toBe('larsadmin/documents/cover/old.png')
        ->and($document->fresh()->cover_name)->toBe('new.png');
});

it('removes a single file when present is sent without keep or upload', function () {
    $document = fileDocument();

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'cover' => ['present' => 1, 'known' => [fileToken('public', 'larsadmin/documents/cover/old.png')]],
    ])->assertSessionHasNoErrors();

    expect($document->fresh()->cover)->toBeNull()
        ->and($document->fresh()->cover_name)->toBeNull();
});

it('leaves the files unchanged when the field is not sent', function () {
    $document = fileDocument();

    $this->put("/admin/entities/documents/{$document->id}", ['title' => 'Renamed'])->assertSessionHasNoErrors();

    $fresh = $document->fresh();

    expect($fresh->title)->toBe('Renamed')
        ->and($fresh->cover)->toBe('larsadmin/documents/cover/old.png')
        ->and($fresh->attachments)->toHaveCount(2);
});

it('runs a fillUsing() callback instead of storing files', function () {
    DocumentEntity::$factory = fn () => [
        Text::make('Title'),
        File::make('Secret')->fillUsing(function (Request $request, Document $record) {
            $record->secret = 'custom/'.$request->file('secret.upload.0')->getClientOriginalName();
        }),
    ];

    $this->post('/admin/entities/documents', [
        'title' => 'Custom',
        'secret' => ['present' => 1, 'upload' => [Uploads::text('x.txt')]],
    ])->assertSessionHasNoErrors();

    expect(Document::firstOrFail()->secret)->toBe('custom/x.txt')
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

// Validation (AC-6, AC-7)

it('requires a file when the field is required', function () {
    DocumentEntity::$factory = fn () => [Text::make('Title'), Image::make('Cover')->disk('public')->rules('required')];

    $this->post('/admin/entities/documents', ['title' => 'X'])->assertSessionHasErrors('cover');
    $this->post('/admin/entities/documents', ['title' => 'X', 'cover' => ['present' => 1]])->assertSessionHasErrors('cover');

    $document = fileDocument();

    $this->put("/admin/entities/documents/{$document->id}", ['title' => 'Y'])->assertSessionHasNoErrors();
    $this->put("/admin/entities/documents/{$document->id}", ['title' => 'Y', 'cover' => ['present' => 1]])->assertSessionHasNoErrors();
    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Y',
        'cover' => ['present' => 1, 'known' => [fileToken('public', 'larsadmin/documents/cover/old.png')]],
    ])->assertSessionHasErrors(['cover' => __('larsadmin::messages.file_required', ['attribute' => 'Cover'])]);
});

it('rejects invalid uploads and stores nothing (AC-6)', function (Closure $payload, string $error) {
    DocumentEntity::$factory = fn () => [
        Text::make('Title'),
        Image::make('Cover')->disk('public')->maxSize(1),
        File::make('Attachments')->multiple()->maxFiles(2)->disk('local'),
        File::make('Secret')->disk('local')->types(['pdf']),
    ];

    $this->post('/admin/entities/documents', ['title' => 'X'] + $payload())->assertSessionHasErrors($error);

    expect(Document::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'oversize' => [fn () => ['cover' => ['upload' => [Uploads::make('big.png', base64_decode(Uploads::PNG).str_repeat('0', 2048))]]], 'cover.upload.0'],
    'wrong type for an image' => [fn () => ['cover' => ['upload' => [Uploads::pdf('doc.png')]]], 'cover.upload.0'],
    'php renamed to jpg in an image' => [fn () => ['cover' => ['upload' => [Uploads::php('photo.jpg')]]], 'cover.upload.0'],
    'double extension' => [fn () => ['attachments' => ['upload' => [Uploads::png('x.php.png')]]], 'attachments.upload.0'],
    'php content as txt' => [fn () => ['attachments' => ['upload' => [Uploads::php('notes.txt')]]], 'attachments.upload.0'],
    'svg' => [fn () => ['attachments' => ['upload' => [Uploads::svg()]]], 'attachments.upload.0'],
    'html' => [fn () => ['attachments' => ['upload' => [Uploads::html()]]], 'attachments.upload.0'],
    'htaccess' => [fn () => ['attachments' => ['upload' => [Uploads::text('.htaccess')]]], 'attachments.upload.0'],
    'type outside types()' => [fn () => ['secret' => ['upload' => [Uploads::text('a.pdf')]]], 'secret.upload.0'],
    'too many uploads' => [fn () => ['attachments' => ['upload' => [Uploads::text('1.txt'), Uploads::text('2.txt'), Uploads::text('3.txt')]]], 'attachments.upload'],
    'two uploads for a single field' => [fn () => ['secret' => ['upload' => [Uploads::pdf('1.pdf'), Uploads::pdf('2.pdf')]]], 'secret.upload'],
    'non-file upload' => [fn () => ['attachments' => ['upload' => ['not a file']]], 'attachments.upload.0'],
    'string instead of array' => [fn () => ['cover' => 'larsadmin/documents/cover/x.png'], 'cover'],
    'traversal path' => [fn () => ['cover' => '../../.env'], 'cover'],
    'unknown key' => [fn () => ['cover' => ['present' => 1, 'path' => 'x.png']], 'cover'],
    'invalid present' => [fn () => ['cover' => ['present' => 2]], 'cover.present'],
    'malformed token' => [fn () => ['cover' => ['keep' => ['../../.env']]], 'cover.keep.0'],
]);

it('stores a non-test upload whose validity follows the real is_uploaded_file semantics', function () {
    $source = Uploads::text('Тест браузера.txt', str_repeat('x', 47));

    // test=false; isValid() stands in for is_uploaded_file(): the temp file must still be there, untouched.
    $upload = new class($source->getPathname(), 'Тест браузера.txt', 'text/plain', UPLOAD_ERR_OK, false) extends UploadedFile
    {
        public function isValid(): bool
        {
            return $this->getError() === UPLOAD_ERR_OK && is_file($this->getPathname());
        }
    };

    $this->post('/admin/entities/documents', [
        'title' => 'Real',
        'attachments' => ['present' => 1, 'known' => [], 'upload' => [$upload]],
    ])->assertSessionHasNoErrors();

    $entry = Document::firstOrFail()->attachments[0];

    expect($entry['name'])->toBe('Тест браузера.txt')
        ->and($entry['size'])->toBe(47)
        ->and(Storage::disk('local')->get($entry['path']))->toBe(str_repeat('x', 47))
        ->and($upload->isValid())->toBeTrue();
});

it('reports uploads PHP could not receive on the server, and stores nothing', function (int $error, bool $reported) {
    Exceptions::fake();

    $upload = new UploadedFile(Uploads::text()->getPathname(), 'notes.txt', 'text/plain', $error, true);

    $this->post('/admin/entities/documents', ['title' => 'X', 'attachments' => ['present' => 1, 'upload' => [$upload]]])
        ->assertSessionHasErrors(['attachments.upload.0' => __('validation.uploaded', ['attribute' => 'Attachments'])]);

    expect(Document::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);

    $reported ? Exceptions::assertReported(RuntimeException::class) : Exceptions::assertNothingReported();

    if ($reported) {
        expect(Exceptions::reported()[0]->getMessage())->toContain('[attachments]')->toContain('upload_tmp_dir');
    }
})->with([
    'missing temporary directory' => [UPLOAD_ERR_NO_TMP_DIR, true],
    'cannot write to disk' => [UPLOAD_ERR_CANT_WRITE, true],
    'blocked by an extension' => [UPLOAD_ERR_EXTENSION, true],
    'larger than upload_max_filesize' => [UPLOAD_ERR_INI_SIZE, false],
    'partial upload' => [UPLOAD_ERR_PARTIAL, false],
]);

it('reports a blocked extension with a translated message', function () {
    $this->post('/admin/entities/documents', [
        'title' => 'X',
        'attachments' => ['upload' => [Uploads::png('x.phtml.png')]],
    ])->assertSessionHasErrors([
        'attachments.upload.0' => __('larsadmin::messages.file_blocked', ['attribute' => 'Attachments', 'extension' => 'phtml']),
    ]);
});

it('counts kept files and uploads against maxFiles', function () {
    $document = fileDocument();

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'attachments' => [
            'present' => 1,
            'keep' => [fileToken('local', 'larsadmin/documents/attachments/a.pdf'), fileToken('local', 'larsadmin/documents/attachments/b.txt')],
            'upload' => [Uploads::text('3.txt'), Uploads::text('4.txt')],
        ],
    ])->assertSessionHasErrors(['attachments' => __('larsadmin::messages.file_too_many', ['attribute' => 'Attachments', 'max' => 3])]);

    expect($document->fresh()->attachments)->toHaveCount(2);
});

it('ignores forged keep tokens and never stores client input as a path (AC-7)', function () {
    $document = fileDocument();
    $other = fileToken('local', 'larsadmin/other/secret.pdf');

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'attachments' => ['present' => 1, 'known' => [...fileKnown(), $other], 'keep' => [str_repeat('a', 32), $other]],
        'cover' => ['present' => 1, 'upload' => [Uploads::png('../../evil.png')]],
    ])->assertSessionHasNoErrors();

    $fresh = $document->fresh();

    expect($fresh->attachments)->toBe([])
        ->and($fresh->cover)->toMatch('#^larsadmin/documents/cover/[A-Za-z0-9]{40}\.png$#')
        ->and($fresh->cover_name)->toBe('evil.png');
});

it('unlocks SVG only on private disks when removed from the block list', function () {
    config(['larsadmin.files.blocked_extensions' => ['php']]);

    DocumentEntity::$factory = fn () => [
        Text::make('Title'),
        File::make('Secret')->disk('local'),
        File::make('Attachments')->disk('public'),
    ];

    $this->post('/admin/entities/documents', ['title' => 'X', 'attachments' => ['upload' => [Uploads::svg()]]])
        ->assertSessionHasErrors('attachments.upload.0');

    $this->post('/admin/entities/documents', ['title' => 'X', 'secret' => ['upload' => [Uploads::svg()]]])
        ->assertSessionHasNoErrors();

    expect(Document::firstOrFail()->secret)->toEndWith('.svg');
});

// Serialization (§4.1)

it('serializes the create form payload', function () {
    $this->get('/admin/entities/documents/create')->assertInertia(fn (Assert $page) => $page
        ->where('fields.1.component', 'image-field')
        ->where('fields.1.context', 'create')
        ->where('fields.1.value', ['present' => 1, 'known' => [], 'keep' => [], 'upload' => []])
        ->where('fields.1.files', [])
        ->where('fields.1.multiple', false)
        ->where('fields.1.maxFiles', 1)
        ->where('fields.1.types', Image::DEFAULT_TYPES)
        ->where('fields.1.accept', fn (string $accept) => str_starts_with($accept, '.jpg,.jpeg,.png') && str_contains($accept, 'image/jpeg'))
        ->where('fields.1.urlsExpireAt', null)
        ->where('fields.1.sortable', false)
        ->has('fields.1.uploadLimit', 3)
        ->missing('fields.1.count')
        ->where('fields.2.component', 'file-field')
        ->where('fields.2.multiple', true)
        ->where('fields.2.maxFiles', 3)
        ->where('fields.2.types', null)
        ->where('fields.2.accept', null)
        ->where('fields.2.removable', true));
});

it('caps maxSize by the PHP upload limit', function () {
    $limit = File::iniBytes((string) ini_get('upload_max_filesize'));
    $field = documentField('cover')->maxSize(10 ** 7);
    $field->context = Field::CREATE;
    $field->resolveDefault(request());

    expect($field->jsonSerialize()['maxSize'])->toBe($limit ?? 10 ** 7 * 1024);
});

it('serializes stored files with public and signed URLs on the detail page', function () {
    $document = fileDocument();
    $token = fileToken('local', 'larsadmin/documents/attachments/a.pdf');

    $this->get("/admin/entities/documents/{$document->id}")->assertInertia(fn (Assert $page) => $page
        ->where('record.fields.2.value', null)
        ->where('record.fields.2.displayedAs', 'sunset.png')
        ->where('record.fields.2.files.0.token', fileToken('public', 'larsadmin/documents/cover/old.png'))
        ->where('record.fields.2.files.0.name', 'sunset.png')
        ->where('record.fields.2.files.0.size', null)
        ->where('record.fields.2.files.0.mime', 'image/png')
        ->where('record.fields.2.files.0.isImage', true)
        ->where('record.fields.2.files.0.url', Storage::disk('public')->url('larsadmin/documents/cover/old.png'))
        ->where('record.fields.2.files.0.downloadUrl', Storage::disk('public')->url('larsadmin/documents/cover/old.png'))
        ->where('record.fields.2.files.0.thumbnailUrl', Storage::disk('public')->url('larsadmin/documents/cover/old.png'))
        ->where('record.fields.2.urlsExpireAt', null)
        ->where('record.fields.3.displayedAs', 'A.pdf, B.txt')
        ->where('record.fields.3.files.0.token', $token)
        ->where('record.fields.3.files.0.size', 8)
        ->where('record.fields.3.files.0.mime', 'application/pdf')
        ->where('record.fields.3.files.0.isImage', false)
        ->where('record.fields.3.files.0.url', fn (string $url) => str_starts_with($url, "/admin/files/documents/{$document->id}/attachments/{$token}?download=1&expires=") && str_contains($url, '&signature='))
        ->where('record.fields.3.files.0.downloadUrl', fn (string $url) => str_contains($url, 'download=1'))
        ->where('record.fields.3.files.0.thumbnailUrl', null)
        ->where('record.fields.3.urlsExpireAt', fn (string $at) => strtotime($at) >= now()->addMinutes(30)->getTimestamp()
            && strtotime($at) <= now()->addMinutes(30)->addSeconds(450)->getTimestamp())
        ->missing('record.fields.3.count'));
});

it('sends inline URLs for private images and a custom thumbnail', function () {
    DocumentEntity::$factory = fn () => [
        ID::make(),
        Image::make('Cover')->disk('local')->thumbnail(fn (StoredFile $file, Document $record) => "/thumbs/{$record->id}/".basename($file->path)),
        Image::make('Secret')->disk('local'),
    ];

    Storage::disk('local')->put('img/a.png', base64_decode(Uploads::PNG));
    $document = Document::create(['title' => 'Img', 'cover' => 'img/a.png', 'secret' => 'img/a.png']);

    $this->get("/admin/entities/documents/{$document->id}")->assertInertia(fn (Assert $page) => $page
        ->where('record.fields.1.files.0.thumbnailUrl', "/thumbs/{$document->id}/a.png")
        ->where('record.fields.1.files.0.url', fn (string $url) => ! str_contains($url, 'download=1') && str_contains($url, 'signature='))
        ->where('record.fields.1.files.0.downloadUrl', fn (string $url) => str_contains($url, 'download=1'))
        ->where('record.fields.2.files.0.thumbnailUrl', fn (string $url) => ! str_contains($url, 'download=1')));
});

it('sends the kept tokens as the edit form value', function () {
    $document = fileDocument();

    $this->get("/admin/entities/documents/{$document->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('record.fields.2.context', 'update')
        ->where('record.fields.2.value', ['present' => 1, 'known' => fileKnown(), 'keep' => fileKnown(), 'upload' => []])
        ->has('record.fields.2.files', 2));
});

it('sends compact index cells with the first file and the count', function () {
    fileDocument(['attachments' => array_map(fn ($i) => ['path' => "f/{$i}.pdf", 'name' => "{$i}.pdf"], range(1, 5))]);

    $this->get('/admin/entities/documents')->assertInertia(fn (Assert $page) => $page
        ->where('records.data.0.fields.3.count', 5)
        ->has('records.data.0.fields.3.files', 1)
        ->where('records.data.0.fields.3.files.0', fn ($file) => $file->keys()->all() === ['name', 'isImage', 'url', 'thumbnailUrl'])
        ->where('records.data.0.fields.2.count', 1)
        ->has('records.data.0.fields.2.files', 1));
});

it('emits no URLs when the user may not view the record (D14)', function () {
    Gate::policy(Document::class, DocumentPolicy::class);
    DocumentPolicy::$denied = ['view'];
    fileDocument();

    $this->get('/admin/entities/documents')->assertInertia(fn (Assert $page) => $page
        ->where('records.data.0.fields.2.files.0.name', 'sunset.png')
        ->where('records.data.0.fields.2.files.0.url', null)
        ->where('records.data.0.fields.2.files.0.thumbnailUrl', null)
        ->where('records.data.0.fields.3.files.0.url', null)
        ->where('records.data.0.fields.3.urlsExpireAt', null));
});

it('emits no URLs when the record key is not a safe path segment', function () {
    $field = documentField('cover')->withUrls();
    $field->context = Field::DETAIL;

    $record = new Document(['cover' => 'x/a.png']);
    $record->setKeyType('string');
    $record->id = 'a/b';

    expect($field->resolve($record)->jsonSerialize()['files'][0]['url'])->toBeNull();
});

it('issues S3 temporary URLs with safe response overrides', function () {
    Storage::fake('s3');
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn (string $path, DateTimeInterface $expires, array $options) => 'https://bucket.test/'.$path.'?'.http_build_query($options)
    );

    DocumentEntity::$factory = fn () => [
        ID::make(),
        File::make('Secret')->disk('s3'),
        Image::make('Cover')->disk('s3')->storeOriginalName('cover_name'),
    ];

    $document = Document::create(['title' => 'S3', 'secret' => 'docs/report.pdf', 'cover' => 'img/photo.png', 'cover_name' => 'Звіт.png']);

    $this->get("/admin/entities/documents/{$document->id}")->assertInertia(fn (Assert $page) => $page
        ->where('record.fields.1.files.0.url', fn (string $url) => str_starts_with($url, 'https://bucket.test/docs/report.pdf?')
            && str_contains(urldecode($url), 'ResponseContentType=application/octet-stream')
            && str_contains(urldecode($url), 'ResponseCacheControl=private, no-store')
            && str_contains(urldecode($url), 'ResponseContentDisposition=attachment; filename=Secret.pdf'))
        ->where('record.fields.2.files.0.url', fn (string $url) => str_contains(urldecode($url), 'ResponseContentType=image/png')
            && str_contains(urldecode($url), 'ResponseCacheControl=private, max-age=1800')
            && str_contains(urldecode($url), 'ResponseContentDisposition=inline; filename=Zvit.png; filename*=utf-8\'\''))
        ->where('record.fields.2.files.0.downloadUrl', fn (string $url) => str_contains(urldecode($url), 'ResponseContentDisposition=attachment'))
        ->where('record.fields.2.urlsExpireAt', fn ($at) => $at !== null));
});

it('does no disk IO while resolving and serializing (index and detail)', function () {
    config(['filesystems.disks.spy' => ['driver' => 'local', 'root' => sys_get_temp_dir(), 'url' => 'https://cdn.test']]);
    $adapter = new ThrowingAdapter;
    Storage::set('spy', new FilesystemAdapter(new Filesystem($adapter), $adapter));

    DocumentEntity::$factory = fn () => [
        ID::make(),
        Image::make('Cover')->disk('spy')->public(),
        File::make('Attachments')->multiple()->disk('spy'),
        File::make('Secret')->disk('spy'),
    ];

    $document = Document::create([
        'title' => 'Spy',
        'cover' => 'a.png',
        'attachments' => [['path' => 'b.pdf', 'name' => 'b.pdf']],
        'secret' => 'c.txt',
    ]);

    $this->get('/admin/entities/documents')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('records.data.0.fields.1.files.0.url', 'https://cdn.test/a.png'));
    $this->get("/admin/entities/documents/{$document->id}")->assertOk();
    $this->get("/admin/entities/documents/{$document->id}/edit")->assertOk();
});

it('keeps signed URLs stable within an expiry bucket and never shorter than url_ttl (CRF-8)', function () {
    config(['larsadmin.files.url_ttl' => 20]);
    $this->travelTo(now()->setTime(10, 0, 30));
    $document = fileDocument();

    $url = fn () => DocumentEntity::make($document->fresh())->serializeRecord(request(), 'detail')['fields']
        ->firstWhere('attribute', 'attachments')->jsonSerialize()['files'][0]['url'];

    $first = $url();
    $expires = (int) Str::between($first, 'expires=', '&');

    expect($expires)->toBe(now()->setTime(10, 25, 0)->getTimestamp());

    $this->travel(4)->minutes();
    expect($url())->toBe($first);

    $this->travel(2)->minutes();
    $second = $url();
    expect($second)->not->toBe($first)
        ->and((int) Str::between($second, 'expires=', '&'))->toBe(now()->setTime(10, 30, 0)->getTimestamp())
        ->and(File::urlExpiry()->getTimestamp())->toBeGreaterThanOrEqual(now()->addMinutes(20)->getTimestamp());

    config(['larsadmin.files.url_ttl' => 1]);
    expect(File::urlExpiry()->getTimestamp() - now()->getTimestamp())->toBeGreaterThanOrEqual(60)->toBeLessThanOrEqual(120);
});

it('refuses a public field on a local disk without a URL (CRF-14)', function () {
    DocumentEntity::$factory = fn () => [File::make('Secret')->disk('local')->public()];

    expect(fn () => DocumentEntity::make()->fieldPrototypes(request()))
        ->toThrow(InvalidArgumentException::class, 'has no "url"');

    config(['filesystems.disks.local.url' => 'https://files.test']);
    DocumentEntity::$factory = fn () => [File::make('Secret')->disk('local')->public(), File::make('Attachments')->disk('local')];

    expect(DocumentEntity::make()->fieldPrototypes(Request::create('/')))->toHaveCount(2);
});

it('passes the file names to displayUsing()', function () {
    $field = File::make('Attachments')->multiple()->displayUsing(fn (array $names) => count($names).' files');
    $field->context = Field::DETAIL;

    expect($field->resolve(new Document(['attachments' => [['path' => 'a.pdf'], ['path' => 'b.pdf']]]))->displayedAs)->toBe('2 files');
});

it('keeps the fake uploads helper honest', function () {
    expect(Uploads::png())->toBeInstanceOf(UploadedFile::class)
        ->and(Uploads::png()->getMimeType())->toBe('image/png')
        ->and(Uploads::pdf()->getMimeType())->toBe('application/pdf')
        ->and(FileTypes::detectedExtension(Uploads::php('a.jpg')))->toBe('php');
});
