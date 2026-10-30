<?php

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Audit\AuditActor;
use Maxcxam\Larsadmin\Audit\AuditEntry;
use Maxcxam\Larsadmin\Audit\AuditRecorder;
use Maxcxam\Larsadmin\Events\AuditRecorded;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Files\StoredFile;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\AuditedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\AuditPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\AuditSchema;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\SecretCastEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\SecretCastPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\SoftAuditedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\SoftAuditPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\UnauditedTag;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\User as UserEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Files\Uploads;
use Maxcxam\Larsadmin\Tests\Fixtures\Tag;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    config(['larsadmin.audit.enabled' => true]);
    AuditSchema::create();
    AuditedPost::reset();
    Storage::fake('local');

    Larsadmin::auth(fn () => true);
    Larsadmin::entities([AuditedPost::class, SoftAuditedPost::class, UnauditedTag::class, UserEntity::class]);

    $this->admin = User::create(['name' => 'Олена Коваль', 'email' => 'olena@example.com', 'password' => 'secret']);
    $this->actingAs($this->admin);
});

afterEach(fn () => AuditedPost::reset());

function auditEntries()
{
    return AuditEntry::query()->orderBy('id')->get();
}

function auditPost(array $attributes = []): AuditPost
{
    return AuditPost::create($attributes + ['title' => 'Київ', 'status' => 'draft']);
}

function auditRequest(string $method, array $data = [], array $server = []): Request
{
    $request = Request::create('/admin/entities/audited-posts', $method, $data, [], [], $server + ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_USER_AGENT' => 'Mozilla/5.0 test']);
    $request->setUserResolver(fn () => auth()->user());

    return $request;
}

// AC-1 / AC-2: create, update, delete

it('records a create with the actor, the entity, the key and the non-null values (AC-1, AC-2)', function () {
    $author = User::create(['name' => 'Іван', 'email' => 'ivan@example.com', 'password' => 'x']);

    $this->post('/admin/entities/audited-posts', [
        'title' => 'Київ',
        'status' => 'live',
        'published' => true,
        'published_at' => '2026-10-30T14:05:11',
        'published_on' => '2026-10-30',
        'author' => $author->id,
        'attachments' => ['present' => 1, 'upload' => [Uploads::text('a.txt')]],
    ], ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_USER_AGENT' => 'Mozilla/5.0 test'])->assertSessionHasNoErrors()->assertRedirect();

    $post = AuditPost::firstOrFail();
    $entry = auditEntries()->sole();

    expect($entry->event)->toBe('created')
        ->and($entry->entity)->toBe('audited-posts')
        ->and($entry->auditable_type)->toBe(AuditPost::class)
        ->and($entry->auditable_id)->toBe((string) $post->id)
        ->and($entry->batch_id)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($entry->actor_type)->toBe(User::class)
        ->and($entry->actor_id)->toBe((string) $this->admin->id)
        ->and($entry->actor_guard)->toBe('web')
        ->and($entry->actor_label)->toBe('Олена Коваль')
        ->and($entry->ip_address)->toBe('10.0.0.5')
        ->and($entry->user_agent)->toBe('Mozilla/5.0 test')
        ->and($entry->meta)->toBeNull()
        ->and($entry->created_at)->not->toBeNull()
        ->and($entry->changes)->toBe([
            'id' => ['old' => null, 'new' => $post->id],
            'title' => ['old' => null, 'new' => 'Київ'],
            'status' => ['old' => null, 'new' => 'live'],
            'published' => ['old' => null, 'new' => true],
            'published_at' => ['old' => null, 'new' => '2026-10-30T14:05:11+00:00'],
            'published_on' => ['old' => null, 'new' => '2026-10-30'],
            'author' => ['old' => null, 'new' => $author->id],
            'attachments' => ['old' => null, 'new' => [['name' => 'a.txt', 'path' => $post->attachments[0]['path']]]],
        ]);
});

it('records an update with only the changed field attributes (AC-1, AC-2)', function () {
    $post = auditPost(['body' => 'Old', 'published_on' => '2026-01-01', 'cover' => 'c/x1.jpg']);
    Storage::disk('local')->put('c/x1.jpg', 'x');

    $this->put("/admin/entities/audited-posts/{$post->id}", [
        'title' => 'Київ восени',
        'body' => 'Old',
        'status' => 'live',
        'published_on' => '2026-01-01',
        'cover' => ['present' => 1, 'known' => [(new StoredFile('c/x1.jpg', 'x1.jpg'))->token('local')], 'keep' => []],
    ])->assertSessionHasNoErrors();

    $entry = auditEntries()->sole();

    expect($entry->event)->toBe('updated')
        ->and($entry->changes)->toBe([
            'title' => ['old' => 'Київ', 'new' => 'Київ восени'],
            'status' => ['old' => 'draft', 'new' => 'live'],
            'cover' => ['old' => [['name' => 'Cover.jpg', 'path' => 'c/x1.jpg']], 'new' => []],
        ]);
});

it('writes nothing for an update without changes (AC-1)', function () {
    $post = auditPost();

    $this->put("/admin/entities/audited-posts/{$post->id}", ['title' => 'Київ', 'status' => 'draft'])->assertSessionHasNoErrors();

    expect(auditEntries())->toBeEmpty();
});

it('records the BelongsTo key change, not the title (AC-2)', function () {
    $a = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);
    $b = User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'x']);
    $post = auditPost(['author_id' => $a->id]);
    $post->load('author');

    AuditedPost::make($post)->save(auditRequest('PUT', ['title' => 'Київ', 'status' => 'draft', 'author' => $b->id]), Field::UPDATE);

    expect(auditEntries()->sole()->changes)->toBe(['author' => ['old' => $a->id, 'new' => $b->id]]);
});

it('treats a posted numeric string equal to the stored number as no change', function () {
    $author = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);
    $post = auditPost(['author_id' => $author->id]);

    $this->put("/admin/entities/audited-posts/{$post->id}", ['title' => 'Київ', 'status' => 'draft', 'author' => (string) $author->id])->assertSessionHasNoErrors();

    expect(auditEntries())->toBeEmpty();
});

it('records a delete with the full before snapshot (AC-1, AC-2)', function () {
    $post = auditPost(['body' => 'Text', 'published_at' => '2026-10-30 14:05:11', 'attachments' => [['path' => 'a/1.pdf', 'name' => '1.pdf']]]);

    $this->delete("/admin/entities/audited-posts/{$post->id}")->assertRedirect();

    $entry = auditEntries()->sole();

    expect($entry->event)->toBe('deleted')
        ->and($entry->auditable_id)->toBe((string) $post->id)
        ->and($entry->meta)->toBeNull()
        ->and($entry->changes)->toBe([
            'id' => ['old' => $post->id, 'new' => null],
            'title' => ['old' => 'Київ', 'new' => null],
            'body' => ['old' => 'Text', 'new' => null],
            'status' => ['old' => 'draft', 'new' => null],
            'published' => ['old' => false, 'new' => null],
            'published_at' => ['old' => '2026-10-30T14:05:11+00:00', 'new' => null],
            'attachments' => ['old' => [['name' => '1.pdf', 'path' => 'a/1.pdf']], 'new' => null],
        ]);
});

it('marks a soft delete', function () {
    $post = SoftAuditPost::create(['title' => 'Soft']);

    $this->delete("/admin/entities/soft-audited-posts/{$post->id}")->assertRedirect();

    expect(auditEntries()->sole()->meta)->toBe(['soft' => true])
        ->and(SoftAuditPost::withTrashed()->find($post->id)->trashed())->toBeTrue();
});

it('writes nothing when a model event cancels the delete', function () {
    $post = auditPost();
    AuditPost::deleting(fn () => false);

    AuditedPost::make($post)->deleteRecord(auditRequest('DELETE'));

    expect(auditEntries())->toBeEmpty()->and(AuditPost::count())->toBe(1);
});

it('writes nothing when a model event cancels the save', function () {
    AuditPost::saving(fn () => false);

    AuditedPost::make()->save(auditRequest('POST', ['title' => 'X', 'status' => 'draft']), Field::CREATE);

    expect(auditEntries())->toBeEmpty();
});

// AC-5: atomicity

it('rolls back the change and the entry when an after-save callback throws (AC-5)', function () {
    $post = auditPost();
    AuditedPost::$afterSave = fn () => throw new RuntimeException('callback failed');

    expect(fn () => AuditedPost::make($post)->save(auditRequest('PUT', ['title' => 'New', 'status' => 'draft']), Field::UPDATE))
        ->toThrow(RuntimeException::class, 'callback failed');

    expect(auditEntries())->toBeEmpty()->and($post->fresh()->title)->toBe('Київ');
});

it('records the changes made by after-save callbacks', function () {
    $post = auditPost();
    AuditedPost::$afterSave = fn (AuditPost $record) => $record->forceFill(['body' => 'From callback'])->save();

    AuditedPost::make($post)->save(auditRequest('PUT', ['title' => 'Київ', 'status' => 'draft']), Field::UPDATE);

    expect(auditEntries()->sole()->changes)->toBe(['body' => ['old' => null, 'new' => 'From callback']]);
});

it('rolls the change back when the entry cannot be written (AC-5)', function () {
    $post = auditPost();
    config(['larsadmin.audit.table' => 'missing_audit_table']);

    expect(fn () => AuditedPost::make($post)->save(auditRequest('PUT', ['title' => 'New', 'status' => 'draft']), Field::UPDATE))
        ->toThrow(RuntimeException::class, 'run the larsadmin migration');

    expect($post->fresh()->title)->toBe('Київ');
});

it('rolls a delete back when the entry cannot be written (AC-5)', function () {
    $post = auditPost();
    config(['larsadmin.audit.table' => 'missing_audit_table']);

    expect(fn () => AuditedPost::make($post)->deleteRecord(auditRequest('DELETE')))->toThrow(RuntimeException::class);

    expect(AuditPost::find($post->id))->not->toBeNull();
});

it('rethrows other insert failures unchanged', function () {
    $post = auditPost();
    DB::statement('CREATE TABLE broken_audit (id INTEGER PRIMARY KEY, created_at TEXT)');
    config(['larsadmin.audit.table' => 'broken_audit']);

    expect(fn () => AuditedPost::make($post)->save(auditRequest('PUT', ['title' => 'New', 'status' => 'draft']), Field::UPDATE))
        ->toThrow(QueryException::class);

    expect($post->fresh()->title)->toBe('Київ');
});

it('discards the entry when the host transaction rolls back (AC-5)', function () {
    $post = auditPost();

    try {
        DB::transaction(function () use ($post) {
            AuditedPost::make($post)->save(auditRequest('PUT', ['title' => 'New', 'status' => 'draft']), Field::UPDATE);

            expect(auditEntries())->toHaveCount(1);

            throw new RuntimeException('host rollback');
        });
    } catch (RuntimeException) {
    }

    expect(auditEntries())->toBeEmpty()->and($post->fresh()->title)->toBe('Київ');
});

// AC-6: masking

it('never stores masked values, only that they changed (AC-6)', function () {
    $post = auditPost(['secret' => 'old-hash', 'note' => 'old note', 'token' => 'old-token', 'private' => 'old private']);

    AuditedPost::make($post)->save(auditRequest('PUT', [
        'title' => 'Київ',
        'status' => 'draft',
        'secret' => 'n3w-p4ssw0rd',
        'note' => 'top secret note',
        'token' => 'tok-123456',
        'private' => 'private plaintext',
    ]), Field::UPDATE);

    $entry = auditEntries()->sole();
    $raw = json_encode(DB::table('larsadmin_audit_entries')->first());

    expect($entry->changes)->toBe([
        'secret' => ['masked' => true],
        'note' => ['masked' => true],
        'token' => ['masked' => true],
        'private' => ['masked' => true],
    ]);

    foreach (['n3w-p4ssw0rd', 'old-hash', 'top secret note', 'old note', 'tok-123456', 'old-token', 'private plaintext', 'old private', '$2y$'] as $secret) {
        expect($raw)->not->toContain($secret);
    }
});

it('does not report a masked field as changed when its value stayed the same', function () {
    $post = auditPost(['private' => 'same', 'note' => 'same']);

    // The encrypted cast produces a new ciphertext, the plaintext is unchanged.
    AuditedPost::make($post)->save(auditRequest('PUT', ['title' => 'Київ', 'status' => 'draft', 'private' => 'same', 'note' => 'same']), Field::UPDATE);

    expect(auditEntries())->toBeEmpty()
        ->and(Crypt::decryptString($post->fresh()->getRawOriginal('private')))->toBe('same');
});

it('masks values in create and delete snapshots', function () {
    AuditedPost::make()->save(auditRequest('POST', ['title' => 'X', 'status' => 'draft', 'secret' => 'pw-create', 'token' => 'tok']), Field::CREATE);

    $post = AuditPost::firstOrFail();
    AuditedPost::make($post)->deleteRecord(auditRequest('DELETE'));

    [$created, $deleted] = auditEntries()->all();

    expect($created->changes['secret'])->toBe(['masked' => true])
        ->and($created->changes['token'])->toBe(['masked' => true])
        ->and($deleted->changes['secret'])->toBe(['masked' => true])
        ->and($deleted->changes['token'])->toBe(['masked' => true])
        ->and(json_encode(DB::table('larsadmin_audit_entries')->get()))->not->toContain('pw-create');
});

// AC-12: disabled / opt-out

it('writes nothing and never queries the audit table while disabled (AC-12)', function () {
    config(['larsadmin.audit.enabled' => false]);
    Schema::drop('larsadmin_audit_entries');
    $post = auditPost();

    DB::enableQueryLog();

    $this->put("/admin/entities/audited-posts/{$post->id}", ['title' => 'New', 'status' => 'draft'])->assertSessionHasNoErrors();
    $this->delete("/admin/entities/audited-posts/{$post->id}")->assertRedirect();

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'larsadmin_audit_entries')))->toBeEmpty()
        ->and(AuditedPost::audited())->toBeFalse()
        ->and(Larsadmin::auditEnabled())->toBeFalse();
});

it('skips entities that opt out (AC-12)', function () {
    $tag = Tag::create(['name' => 'News']);

    $this->put("/admin/entities/unaudited-tags/{$tag->id}", ['name' => 'Changed'])->assertSessionHasNoErrors();
    $this->delete("/admin/entities/unaudited-tags/{$tag->id}")->assertRedirect();

    expect(auditEntries())->toBeEmpty()
        ->and(UnauditedTag::audited())->toBeFalse()
        ->and(AuditedPost::audited())->toBeTrue();
});

it('records seams inside a recorder batch unless the record is covered by an action', function () {
    $post = auditPost();
    $other = auditPost(['title' => 'Other']);
    $recorder = app(AuditRecorder::class);

    $batch = $recorder->batch(function (string $batch) use ($recorder, $post, $other) {
        $recorder->cover(AuditedPost::class, [$post]);

        AuditedPost::make($post)->save(auditRequest('PUT', ['title' => 'Covered', 'status' => 'draft']), Field::UPDATE);
        AuditedPost::make($other)->save(auditRequest('PUT', ['title' => 'Logged', 'status' => 'draft']), Field::UPDATE);

        return $batch;
    });

    $entry = auditEntries()->sole();

    expect($entry->auditable_id)->toBe((string) $other->id)
        ->and($entry->batch_id)->toBe($batch)
        ->and($post->fresh()->title)->toBe('Covered')
        ->and($recorder->covers(AuditedPost::class, $post))->toBeFalse();
});

// Equality (CRA-01, CRA-02, CRA-10)

it('records a change between numeric-looking strings', function (string $from, string $to) {
    $post = auditPost(['title' => $from]);

    AuditedPost::make($post)->save(auditRequest('PUT', ['title' => $to, 'status' => 'draft']), Field::UPDATE);

    expect(auditEntries()->sole()->changes)->toBe(['title' => ['old' => $from, 'new' => $to]]);
})->with([
    'leading zero' => ['007', '7'],
    'trailing zero' => ['1.10', '1.1'],
    'exponent' => ['1e3', '1000'],
]);

it('stores a posted foreign key with the related key type', function () {
    $a = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);
    $b = User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'x']);
    $post = auditPost(['author_id' => $a->id]);

    $this->put("/admin/entities/audited-posts/{$post->id}", ['title' => 'Київ', 'status' => 'draft', 'author' => (string) $b->id])->assertSessionHasNoErrors();

    expect(auditEntries()->sole()->changes)->toBe(['author' => ['old' => $a->id, 'new' => $b->id]]);
});

it('writes nothing for an unchanged boolean on an uncast column (CRA-02)', function () {
    Larsadmin::entities([SecretCastEntity::class]);
    $post = SecretCastPost::create(['title' => 'X', 'published' => 1]);

    $this->put("/admin/entities/secret-cast-entities/{$post->id}", ['title' => 'X', 'published' => true])->assertSessionHasNoErrors();
    expect(auditEntries())->toBeEmpty();

    $this->put("/admin/entities/secret-cast-entities/{$post->id}", ['title' => 'X', 'published' => false])->assertSessionHasNoErrors();
    expect(auditEntries()->sole()->changes)->toBe(['published' => ['old' => 1, 'new' => false]]);
});

// SECA-01: more masking rules

it('masks attributes outside $visible, hashed casts and host encryption casts in the raw row (SECA-01)', function () {
    Larsadmin::entities([SecretCastEntity::class]);

    $this->post('/admin/entities/secret-cast-entities', [
        'title' => 'Visible',
        'token' => 'tok-PLAIN-123',
        'note' => 'pin-PLAIN-456',
        'private' => 'enc-PLAIN-789',
    ])->assertSessionHasNoErrors();

    $raw = json_encode(DB::table('larsadmin_audit_entries')->get());
    $changes = auditEntries()->sole()->changes;

    expect($changes['title'])->toBe(['old' => null, 'new' => 'Visible'])
        ->and($changes['token'])->toBe(['masked' => true])
        ->and($changes['note'])->toBe(['masked' => true])
        ->and($changes['private'])->toBe(['masked' => true])
        ->and($raw)->not->toContain('tok-PLAIN')->not->toContain('pin-PLAIN')->not->toContain('enc-PLAIN')->not->toContain('$2y$');
});

it('saves a record whose masked value cannot be decrypted (CRA-15)', function () {
    $post = auditPost();
    DB::table('audit_posts')->where('id', $post->id)->update(['private' => 'not-a-ciphertext']);

    AuditedPost::make(AuditPost::findOrFail($post->id))->save(auditRequest('PUT', ['title' => 'New', 'status' => 'draft']), Field::UPDATE);

    expect(auditEntries()->sole()->changes)->toBe(['title' => ['old' => 'Київ', 'new' => 'New']]);
});

// AC-13: truncation

it('truncates long values (AC-13)', function () {
    config(['larsadmin.audit.max_value_length' => 100]);
    $post = auditPost();

    AuditedPost::make($post)->save(auditRequest('PUT', ['title' => 'Київ', 'status' => 'draft', 'body' => str_repeat('ж', 300)]), Field::UPDATE);

    $change = auditEntries()->sole()->changes['body'];

    expect($change['new'])->toBe(str_repeat('ж', 100))
        ->and($change['truncated'])->toBe(['new'])
        ->and(auditEntries()->sole()->meta)->toBeNull();
});

it('caps the changes at 64 KB and marks the entry (AC-13)', function () {
    config(['larsadmin.audit.max_value_length' => 100000]);
    $post = auditPost();

    AuditedPost::make($post)->save(auditRequest('PUT', ['title' => 'Київ', 'status' => 'draft', 'body' => str_repeat('b', 70000)]), Field::UPDATE);

    $entry = auditEntries()->sole();

    expect($entry->changes['body'])->toBe(['old' => null, 'new' => null, 'omitted' => true])
        ->and($entry->meta)->toBe(['truncated' => true]);
});

// Actor

it('can skip the IP address and the user agent', function () {
    config(['larsadmin.audit.store_ip' => false, 'larsadmin.audit.store_user_agent' => false]);

    AuditedPost::make()->save(auditRequest('POST', ['title' => 'X', 'status' => 'draft']), Field::CREATE);

    $entry = auditEntries()->sole();

    expect($entry->ip_address)->toBeNull()->and($entry->user_agent)->toBeNull()->and($entry->actor_id)->not->toBeNull();
});

it('uses the actor label callback, else the name, the email or the id', function () {
    Larsadmin::auditActorLabelUsing(fn (User $user) => 'Custom '.$user->id);
    expect(AuditActor::fromRequest(auditRequest('POST'))->label)->toBe('Custom '.$this->admin->id);

    Larsadmin::auditActorLabelUsing(fn () => '');
    expect(AuditActor::fromRequest(auditRequest('POST'))->label)->toBeNull();

    Larsadmin::auditActorLabelUsing(null);
    $this->admin->name = '';
    expect(AuditActor::fromRequest(auditRequest('POST'))->label)->toBe('olena@example.com');

    $this->admin->email = '';
    expect(AuditActor::fromRequest(auditRequest('POST'))->label)->toBe('#'.$this->admin->id);

    $this->admin->name = str_repeat('я', 300);
    expect(mb_strlen(AuditActor::fromRequest(auditRequest('POST'))->label))->toBe(255);

    Larsadmin::auditActorLabelUsing(fn () => 'x');
    Larsadmin::flushState();
    $this->admin->name = 'Back';
    expect(AuditActor::fromRequest(auditRequest('POST'))->label)->toBe('Back');
});

it('records the panel guard and the system without a user', function () {
    config(['larsadmin.guard' => 'web']);
    expect(AuditActor::fromRequest(auditRequest('POST'))->guard)->toBe('web');

    $request = Request::create('/');
    $actor = AuditActor::fromRequest($request);

    expect($actor->toArray())->toBe(['type' => null, 'id' => null, 'guard' => null, 'label' => null, 'ip' => null, 'userAgent' => null]);
});

it('truncates the user agent and serializes the actor', function () {
    $actor = AuditActor::fromRequest(auditRequest('POST', [], ['HTTP_USER_AGENT' => str_repeat('u', 600)]));

    expect(strlen($actor->userAgent))->toBe(512)
        ->and(AuditActor::fromArray($actor->toArray()))->toEqual($actor)
        ->and(AuditActor::fromArray(['type' => 5, 'id' => ['x']])->toArray())->toBe(['type' => null, 'id' => null, 'guard' => null, 'label' => null, 'ip' => null, 'userAgent' => null]);
});

it('records the system when the save runs without a user', function () {
    auth()->logout();

    AuditedPost::make()->save(Request::create('/', 'POST', ['title' => 'Seeded', 'status' => 'draft']), Field::CREATE);

    $entry = auditEntries()->sole();

    expect($entry->actor_type)->toBeNull()->and($entry->actor_id)->toBeNull()->and($entry->actor_label)->toBeNull();
});

// Connection and events

it('writes after the commit on another connection', function () {
    config([
        'database.connections.audit' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'larsadmin.audit.connection' => 'audit',
    ]);
    Schema::connection('audit')->create('larsadmin_audit_entries', function ($table) {
        $table->id();
        $table->uuid('batch_id');
        $table->string('event');
        $table->string('entity');
        $table->string('auditable_type')->nullable();
        $table->string('auditable_id')->nullable();
        $table->string('actor_type')->nullable();
        $table->string('actor_id')->nullable();
        $table->string('actor_guard')->nullable();
        $table->string('actor_label')->nullable();
        $table->json('changes')->nullable();
        $table->json('meta')->nullable();
        $table->string('ip_address')->nullable();
        $table->string('user_agent')->nullable();
        $table->timestamp('created_at');
    });
    $post = auditPost();

    DB::transaction(function () use ($post) {
        AuditedPost::make($post)->save(auditRequest('PUT', ['title' => 'New', 'status' => 'draft']), Field::UPDATE);

        expect(DB::connection('audit')->table('larsadmin_audit_entries')->count())->toBe(0);
    });

    expect(DB::connection('audit')->table('larsadmin_audit_entries')->count())->toBe(1)
        ->and(AuditEntry::count())->toBe(1);
});

it('reports a failed write on another connection without undoing the change', function () {
    Exceptions::fake();
    config([
        'database.connections.audit' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'larsadmin.audit.connection' => 'audit',
    ]);
    $post = auditPost();

    AuditedPost::make($post)->save(auditRequest('PUT', ['title' => 'New', 'status' => 'draft']), Field::UPDATE);

    expect($post->fresh()->title)->toBe('New');
    Exceptions::assertReported(RuntimeException::class);
});

it('logs a failed write on another connection at critical (SECA-10)', function () {
    Exceptions::fake();
    Log::spy();
    config([
        'database.connections.audit' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'larsadmin.audit.connection' => 'audit',
    ]);
    $post = auditPost();

    AuditedPost::make($post)->save(auditRequest('PUT', ['title' => 'New', 'status' => 'draft']), Field::UPDATE);

    Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'audit')
        && $context['entity'] === 'audited-posts'
        && $context['keys'] === [(string) $post->id]);
});

it('reports a throwing AuditRecorded listener without breaking the response or the file purge (CRA-05)', function () {
    Exceptions::fake();
    Event::listen(AuditRecorded::class, fn () => throw new RuntimeException('SIEM down'));
    $post = auditPost(['cover' => 'c/x1.jpg']);
    Storage::disk('local')->put('c/x1.jpg', 'x');

    $this->put("/admin/entities/audited-posts/{$post->id}", [
        'title' => 'Київ',
        'status' => 'draft',
        'cover' => ['present' => 1, 'known' => [(new StoredFile('c/x1.jpg', 'x1.jpg'))->token('local')], 'keep' => []],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Storage::disk('local')->exists('c/x1.jpg'))->toBeFalse()
        ->and(auditEntries())->toHaveCount(1);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'SIEM down');
});

it('dispatches AuditRecorded after the commit only', function () {
    $received = [];
    Event::listen(AuditRecorded::class, function (AuditRecorded $event) use (&$received) {
        $received[] = $event->entries;
    });
    $post = auditPost();

    DB::transaction(function () use ($post, &$received) {
        AuditedPost::make($post)->save(auditRequest('PUT', ['title' => 'New', 'status' => 'draft']), Field::UPDATE);

        expect($received)->toBeEmpty();
    });

    expect($received)->toHaveCount(1)
        ->and($received[0]->sole()->event)->toBe('updated')
        ->and($received[0]->sole()->id)->toBe(AuditEntry::sole()->id)
        ->and($received[0]->sole()->changes)->toBe(['title' => ['old' => 'Київ', 'new' => 'New']]);

    try {
        DB::transaction(function () use ($post) {
            AuditedPost::make($post->fresh())->save(auditRequest('PUT', ['title' => 'Rolled back', 'status' => 'draft']), Field::UPDATE);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($received)->toHaveCount(1);
});

// AuditEntry

it('keeps entries immutable', function () {
    AuditedPost::make()->save(auditRequest('POST', ['title' => 'X', 'status' => 'draft']), Field::CREATE);
    $entry = AuditEntry::sole();

    expect(fn () => $entry->update(['event' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and($entry->auditable)->toBeInstanceOf(AuditPost::class);
});

it('logs neither login nor logout (AC-11)', function () {
    auth()->logout();

    $this->post('/admin/login', ['email' => 'olena@example.com', 'password' => 'secret']);
    $this->post('/admin/logout');

    expect(auditEntries())->toBeEmpty();
});
