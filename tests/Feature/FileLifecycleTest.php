<?php

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Fields\File;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Image;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Files\StoredFile;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\Post as PostEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\Document;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\Entities\Document as DocumentEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\SoftDocument;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\Uploads;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([DocumentEntity::class, PostEntity::class]);
    DocumentEntity::reset();

    Storage::fake('public');
    Storage::fake('local');

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
});

afterEach(fn () => DocumentEntity::reset());

/**
 * A document with a stored cover, two attachments and a secret.
 */
function lifecycleDocument(string $model = Document::class): Document
{
    Storage::disk('public')->put('c/old.png', base64_decode(Uploads::PNG));
    Storage::disk('local')->put('a/one.pdf', '%PDF-1.4');
    Storage::disk('local')->put('a/two.txt', 'two');
    Storage::disk('local')->put('s/secret.txt', 'secret');

    return $model::create([
        'title' => 'Doc',
        'cover' => 'c/old.png',
        'attachments' => [['path' => 'a/one.pdf', 'name' => 'one.pdf'], ['path' => 'a/two.txt', 'name' => 'two.txt']],
        'secret' => 's/secret.txt',
    ]);
}

/**
 * Every file on the fake disks the tests use.
 *
 * @return list<string>
 */
function storedFiles(): array
{
    return [...Storage::disk('public')->allFiles(), ...Storage::disk('local')->allFiles()];
}

function lifecycleToken(string $path, string $disk = 'local'): string
{
    return (new StoredFile($path, basename($path)))->token($disk);
}

function documentUpdate(Document $document, array $data): Request
{
    $request = Request::create("/admin/entities/documents/{$document->id}", 'PUT', $data);
    $request->setUserResolver(fn () => auth()->user());

    return $request;
}

// AC-5: replace, remove and prune after commit

it('deletes a replaced file only after the commit (AC-5)', function () {
    $document = lifecycleDocument();

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'cover' => ['present' => 1, 'upload' => [Uploads::png('new.png')]],
        'attachments' => [
            'present' => 1,
            'known' => [lifecycleToken('a/one.pdf'), lifecycleToken('a/two.txt')],
            'keep' => [lifecycleToken('a/two.txt')],
        ],
    ])->assertSessionHasNoErrors();

    $fresh = $document->fresh();

    expect(Storage::disk('public')->exists('c/old.png'))->toBeFalse()
        ->and(Storage::disk('public')->exists($fresh->cover))->toBeTrue()
        ->and(Storage::disk('local')->exists('a/one.pdf'))->toBeFalse()
        ->and(Storage::disk('local')->exists('a/two.txt'))->toBeTrue()
        ->and(Storage::disk('local')->exists('s/secret.txt'))->toBeTrue();
});

it('purges only after the outermost commit and keeps files when it rolls back (AC-5)', function () {
    $document = lifecycleDocument();
    $request = documentUpdate($document, ['title' => 'Doc', 'cover' => ['present' => 1, 'known' => [lifecycleToken('c/old.png', 'public')]]]);

    DB::transaction(function () use ($document, $request) {
        DocumentEntity::make($document)->save($request, Field::UPDATE);

        expect(Storage::disk('public')->exists('c/old.png'))->toBeTrue();
    });

    expect(Storage::disk('public')->exists('c/old.png'))->toBeFalse();

    $other = lifecycleDocument();
    $request = documentUpdate($other, ['title' => 'Doc', 'cover' => ['present' => 1, 'known' => [lifecycleToken('c/old.png', 'public')]]]);

    try {
        DB::transaction(function () use ($other, $request) {
            DocumentEntity::make($other)->save($request, Field::UPDATE);

            throw new RuntimeException('Host rollback');
        });
    } catch (RuntimeException) {
    }

    expect(Storage::disk('public')->exists('c/old.png'))->toBeTrue()
        ->and($other->fresh()->cover)->toBe('c/old.png');
});

it('leaves no new file and keeps the old one when record->save() fails (AC-5)', function () {
    DocumentEntity::$factory = fn () => [
        Text::make('Title'),
        Text::make('Slug')->nullable(),
        Image::make('Cover')->disk('public'),
    ];

    $document = lifecycleDocument();
    Document::create(['title' => 'Taken', 'slug' => 'taken']);

    $this->withoutExceptionHandling();

    expect(fn () => $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'slug' => 'taken',
        'cover' => ['present' => 1, 'upload' => [Uploads::png('new.png')]],
    ]))->toThrow(QueryException::class);

    expect(Storage::disk('public')->allFiles())->toBe(['c/old.png'])
        ->and($document->fresh()->cover)->toBe('c/old.png');
});

it('leaves no new file when an after-save callback throws (AC-5)', function () {
    DocumentEntity::$factory = fn () => [
        Text::make('Title')->fillUsing(function (Request $request, Document $record) {
            $record->title = $request->input('title');

            return fn () => throw new RuntimeException('After save failed');
        }),
        File::make('Attachments')->multiple()->disk('local'),
    ];

    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/admin/entities/documents', [
        'title' => 'Doc',
        'attachments' => ['present' => 1, 'upload' => [Uploads::text('a.txt'), Uploads::text('b.txt')]],
    ]))->toThrow(RuntimeException::class, 'After save failed');

    expect(storedFiles())->toBe([])->and(Document::count())->toBe(0);
});

it('keeps committed uploads when an after-commit callback throws (CRF-2)', function () {
    DocumentEntity::$factory = fn () => [
        Text::make('Title')->fillUsing(function (Request $request, Document $record) {
            $record->title = $request->input('title');

            return fn () => DB::afterCommit(fn () => throw new RuntimeException('Queue down'));
        }),
        File::make('Attachments')->multiple()->disk('local'),
    ];

    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/admin/entities/documents', [
        'title' => 'Doc',
        'attachments' => ['present' => 1, 'upload' => [Uploads::text('kept.txt')]],
    ]))->toThrow(RuntimeException::class, 'Queue down');

    $path = Document::firstOrFail()->attachments[0]['path'];

    expect(Storage::disk('local')->exists($path))->toBeTrue();
});

it('discards the files already written when a later upload fails', function () {
    DocumentEntity::$factory = fn () => [
        Text::make('Title'),
        File::make('Attachments')->multiple()->disk('local'),
    ];

    $source = Uploads::text('broken.txt');
    $broken = new class($source->getPathname(), 'broken.txt', null, null, true) extends UploadedFile
    {
        public function storeAs($path, $name = null, $options = [])
        {
            throw new RuntimeException('Disk full');
        }
    };

    $document = Document::create(['title' => 'Doc']);
    $request = documentUpdate($document, ['title' => 'Doc']);
    $request->files->set('attachments', ['upload' => [Uploads::text('ok.txt'), $broken]]);

    expect(fn () => DocumentEntity::make($document)->save($request, Field::UPDATE))->toThrow(RuntimeException::class, 'Disk full')
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    $field = DocumentEntity::make()->fieldPrototypes($request)->last();

    expect(fn () => $field->fill($request, $document))->toThrow(RuntimeException::class, 'Disk full')
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('rejects an invalid path() closure result without leaving files', function () {
    DocumentEntity::$factory = fn () => [
        Text::make('Title'),
        File::make('Secret')->disk('local')->path(fn () => '../outside'),
    ];

    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/admin/entities/documents', [
        'title' => 'Doc',
        'secret' => ['present' => 1, 'upload' => [Uploads::text()]],
    ]))->toThrow(InvalidArgumentException::class, 'Invalid path [../outside]');

    expect(storedFiles())->toBe([]);

    DocumentEntity::$factory = fn () => [Text::make('Title'), File::make('Secret')->disk('local')->path(fn () => 42)];

    expect(fn () => $this->post('/admin/entities/documents', [
        'title' => 'Doc',
        'secret' => ['present' => 1, 'upload' => [Uploads::text()]],
    ]))->toThrow(InvalidArgumentException::class, 'must return a string');
});

it('keeps replaced files when pruning is off (AC-5)', function (Closure $configure) {
    $configure();
    $document = lifecycleDocument();

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'cover' => ['present' => 1, 'known' => [lifecycleToken('c/old.png', 'public')]],
    ])->assertSessionHasNoErrors();

    expect(Document::find($document->id)->cover)->toBeNull();

    $this->delete("/admin/entities/documents/{$document->id}");

    expect($document->fresh())->toBeNull()
        ->and(Storage::disk('public')->exists('c/old.png'))->toBeTrue()
        ->and(Storage::disk('local')->exists('a/one.pdf'))->toBe(! config('larsadmin.files.prune'));
})->with([
    'per field' => [fn () => DocumentEntity::$factory = fn () => [
        Text::make('Title'),
        Image::make('Cover')->disk('public')->prunable(false),
        File::make('Attachments')->multiple()->disk('local'),
    ]],
    'by config' => [fn () => config(['larsadmin.files.prune' => false])],
]);

it('prunes every file field of a deleted record after the commit, hidden ones too (AC-5)', function () {
    DocumentEntity::$factory = fn () => [
        ID::make(),
        Text::make('Title'),
        Image::make('Cover')->disk('public'),
        File::make('Attachments')->multiple()->disk('local')->hideFromDetail()->hideFromIndex(),
        File::make('Secret')->disk('local')->canSee(fn () => false),
    ];

    $document = lifecycleDocument();
    Storage::disk('local')->put('keep/unrelated.txt', 'x');

    $this->delete("/admin/entities/documents/{$document->id}")->assertRedirect();

    expect(Document::count())->toBe(0)
        ->and(storedFiles())->toBe(['keep/unrelated.txt']);
});

it('keeps the files of a soft-deleted record (AC-5)', function () {
    DocumentEntity::$model = SoftDocument::class;
    $document = lifecycleDocument(SoftDocument::class);

    $this->delete("/admin/entities/documents/{$document->id}")->assertRedirect();

    expect(SoftDocument::withTrashed()->find($document->id)->trashed())->toBeTrue()
        ->and(storedFiles())->toHaveCount(4);

    DocumentEntity::make(SoftDocument::withTrashed()->find($document->id))->deleteRecord(request());

    expect(storedFiles())->toHaveCount(4);
});

it('keeps the files when the delete transaction rolls back', function () {
    $document = lifecycleDocument();

    try {
        DB::transaction(function () use ($document) {
            DocumentEntity::make($document)->deleteRecord(request());

            throw new RuntimeException('Rollback');
        });
    } catch (RuntimeException) {
    }

    expect(Document::count())->toBe(1)->and(storedFiles())->toHaveCount(4);
});

it('deletes records of entities without file fields as before', function () {
    $post = Post::create(['title' => 'Hello', 'status' => 'draft']);

    $this->delete("/admin/entities/posts/{$post->id}")->assertRedirect('/admin/entities/posts');

    expect(Post::count())->toBe(0);
});

// CRF-1 / SECF-4: stale and concurrent forms

it('keeps files added by a concurrent save that the stale form never saw (CRF-1)', function () {
    $document = lifecycleDocument();
    $known = [lifecycleToken('a/one.pdf'), lifecycleToken('a/two.txt')];

    // Tab A adds a file.
    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'attachments' => ['present' => 1, 'known' => $known, 'keep' => $known, 'upload' => [Uploads::pdf('new.pdf')]],
    ])->assertSessionHasNoErrors();

    $added = $document->fresh()->attachments[2]['path'];

    // Tab B, opened before, removes "two" only.
    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'attachments' => ['present' => 1, 'known' => $known, 'keep' => [lifecycleToken('a/one.pdf')]],
    ])->assertSessionHasNoErrors();

    expect(array_column($document->fresh()->attachments, 'path'))->toBe(['a/one.pdf', $added])
        ->and(Storage::disk('local')->exists($added))->toBeTrue()
        ->and(Storage::disk('local')->exists('a/one.pdf'))->toBeTrue()
        ->and(Storage::disk('local')->exists('a/two.txt'))->toBeFalse();
});

it('keeps a single file replaced meanwhile when the stale form removes the old one (CRF-1)', function () {
    $document = lifecycleDocument();
    $old = lifecycleToken('c/old.png', 'public');

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'cover' => ['present' => 1, 'known' => [$old], 'keep' => [$old], 'upload' => [Uploads::png('new.png')]],
    ])->assertSessionHasNoErrors();

    $replaced = $document->fresh()->cover;

    $this->put("/admin/entities/documents/{$document->id}", ['title' => 'Doc', 'cover' => ['present' => 1, 'known' => [$old]]])
        ->assertSessionHasNoErrors();

    expect($document->fresh()->cover)->toBe($replaced)
        ->and(Storage::disk('public')->exists($replaced))->toBeTrue();
});

it('removes nothing without "known", but an upload still replaces a single file (CRF-1)', function () {
    $document = lifecycleDocument();

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'attachments' => ['present' => 1, 'keep' => [lifecycleToken('a/two.txt')]],
        'secret' => ['present' => 1],
    ])->assertSessionHasNoErrors();

    $fresh = $document->fresh();

    expect(array_column($fresh->attachments, 'path'))->toBe(['a/two.txt', 'a/one.pdf'])
        ->and($fresh->secret)->toBe('s/secret.txt')
        ->and(storedFiles())->toHaveCount(4);

    $this->put("/admin/entities/documents/{$document->id}", ['title' => 'Doc', 'cover' => ['upload' => [Uploads::png()]]])
        ->assertSessionHasNoErrors();

    expect($document->fresh()->cover)->not->toBe('c/old.png')
        ->and(Storage::disk('public')->exists('c/old.png'))->toBeFalse();
});

it('keeps every file when the form tokens were signed with a rotated APP_KEY (CRF-1)', function () {
    $document = lifecycleDocument();
    $known = [lifecycleToken('a/one.pdf'), lifecycleToken('a/two.txt')];

    config(['app.key' => 'base64:'.base64_encode(str_repeat('r', 32))]);

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'attachments' => ['present' => 1, 'known' => $known, 'keep' => [$known[0]], 'upload' => [Uploads::text('new.txt')]],
    ])->assertSessionHasNoErrors();

    expect(array_column($document->fresh()->attachments, 'path'))->toHaveCount(3)->toContain('a/one.pdf', 'a/two.txt')
        ->and(storedFiles())->toHaveCount(5);
});

it('validates known tokens like keep tokens (CRF-1)', function (Closure $known, string $error) {
    $document = lifecycleDocument();

    $this->put("/admin/entities/documents/{$document->id}", ['title' => 'Doc', 'attachments' => ['present' => 1, 'known' => $known()]])
        ->assertSessionHasErrors($error);

    expect($document->fresh()->attachments)->toHaveCount(2);
})->with([
    'path instead of a token' => [fn () => ['a/one.pdf'], 'attachments.known.0'],
    'upper-case token' => [fn () => [strtoupper(lifecycleToken('a/one.pdf'))], 'attachments.known.0'],
    'not a list' => [fn () => 'a/one.pdf', 'attachments.known'],
    'more than maxFiles' => [fn () => array_fill(0, 4, lifecycleToken('a/one.pdf')), 'attachments.known'],
]);

it('keeps the files of a field that is not removable (CRF-9)', function () {
    DocumentEntity::$factory = fn () => [
        Text::make('Title'),
        Image::make('Cover')->disk('public')->removable(false),
        File::make('Attachments')->multiple()->disk('local')->removable(false),
    ];

    $document = lifecycleDocument();

    $this->put("/admin/entities/documents/{$document->id}", [
        'title' => 'Doc',
        'cover' => ['present' => 1, 'known' => [lifecycleToken('c/old.png', 'public')]],
        'attachments' => [
            'present' => 1,
            'known' => [lifecycleToken('a/one.pdf'), lifecycleToken('a/two.txt')],
            'keep' => [lifecycleToken('a/two.txt')],
            'upload' => [Uploads::text('new.txt')],
        ],
    ])->assertSessionHasNoErrors();

    $fresh = $document->fresh();

    expect($fresh->cover)->toBe('c/old.png')
        ->and(array_column($fresh->attachments, 'path'))->toHaveCount(3)->toContain('a/one.pdf', 'a/two.txt')
        ->and($fresh->attachments[0]['path'])->toBe('a/two.txt')
        ->and(storedFiles())->toHaveCount(5);

    $this->put("/admin/entities/documents/{$document->id}", ['title' => 'Doc', 'cover' => ['present' => 1, 'upload' => [Uploads::png()]]])
        ->assertSessionHasNoErrors();

    expect($document->fresh()->cover)->not->toBe('c/old.png')
        ->and(Storage::disk('public')->exists('c/old.png'))->toBeFalse();
});

// AC-9: multipart transport

it('round-trips a full form sent as multipart with method spoofing (AC-9)', function () {
    $post = Post::create(['title' => 'Old', 'status' => 'draft', 'views' => 1, 'published' => true]);

    $this->post("/admin/entities/posts/{$post->id}", [
        '_method' => 'PUT',
        'title' => 'Multipart',
        'body' => '',
        'views' => '42',
        'published' => '0',
        'status' => 'live',
        'published_at' => '',
        'subtitle' => 'Sub',
    ])->assertRedirect("/admin/entities/posts/{$post->id}")->assertSessionHasNoErrors();

    $fresh = $post->fresh();

    expect($fresh->title)->toBe('Multipart')
        ->and($fresh->body)->toBeNull()
        ->and($fresh->views)->toBe(42)
        ->and($fresh->published)->toBeFalse()
        ->and($fresh->status)->toBe('live')
        ->and($fresh->published_at)->toBeNull();

    $this->post("/admin/entities/posts/{$post->id}", ['_method' => 'PUT', 'title' => 'Again', 'status' => 'draft', 'published' => '1'])
        ->assertSessionHasNoErrors();

    expect($post->fresh()->published)->toBeTrue();
});

it('updates file fields through a spoofed PUT (AC-9)', function () {
    $document = lifecycleDocument();

    $this->post("/admin/entities/documents/{$document->id}", [
        '_method' => 'PUT',
        'title' => 'Doc',
        'cover' => ['present' => '1', 'upload' => [Uploads::png()]],
    ])->assertRedirect("/admin/entities/documents/{$document->id}")->assertSessionHasNoErrors();

    expect($document->fresh()->cover)->not->toBe('c/old.png');
});

it('names validation errors of nested file keys after the field', function () {
    $this->post('/admin/entities/documents', [
        'title' => 'Doc',
        'attachments' => ['upload' => [Uploads::php('x.php')]],
    ])->assertSessionHasErrors(['attachments.upload.0' => __('larsadmin::messages.file_blocked', ['attribute' => 'Attachments', 'extension' => 'php'])]);
});

it('resolves the view ability once per record and adds no queries per file field', function () {
    $count = function (int $records) {
        Document::query()->forceDelete();

        foreach (range(1, $records) as $i) {
            Document::create(['title' => "Doc {$i}", 'cover' => "c/{$i}.png", 'attachments' => [['path' => "a/{$i}.pdf"]]]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/admin/entities/documents')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($count(25))->toBe($count(1));
});

// D13: no file fields in actions

it('refuses file fields in action fields (AC-10)', function () {
    $action = new class extends Action
    {
        public function fields(Request $request): array
        {
            return [Text::make('Note'), File::make('Upload')];
        }

        public function handle(ActionFields $fields, Collection $models): ?ActionResponse
        {
            return null;
        }
    };

    expect(fn () => $action->bindTo(DocumentEntity::class)->fieldPrototypes(request()))
        ->toThrow(LogicException::class, 'file fields are not supported in actions');
});
