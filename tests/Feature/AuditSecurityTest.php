<?php

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Audit\AuditEntry;
use Maxcxam\Larsadmin\Audit\AuditValues;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Http\Middleware\HandleInertiaRequests;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\Actions\AuditAction;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\AuditPostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\AuditSchema;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\Security\OwnRecordPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\Security\SecureAuditPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\Security\SecurePost;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\Security\SecureUser;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\Note as NoteEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\TagEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\TaggedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Note;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\Security\DenyAllPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Tag;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

/*
 * T1: the audit log seen from an attacker, end to end through HTTP
 * (design 1.3 §7.3, §8, §9, §13 AC-6..AC-9, AC-12..AC-14; ADR-024, ADR-025; NFR-025..NFR-028).
 * Two tenants: Alice (the victim) and Bob (the signed-in viewer). Fail closed everywhere.
 */

beforeEach(function () {
    config(['larsadmin.audit.enabled' => true, 'larsadmin.locale' => 'en', 'app.debug' => false]);
    AuditSchema::create();
    asecReset();
    asecEntities([SecurePost::class, SecureUser::class, TaggedPost::class, TagEntity::class, NoteEntity::class]);
    Gate::define('viewLarsadminAudit', fn () => true);

    $this->alice = User::create(['name' => 'Alice Owner', 'email' => 'alice@tenant.test', 'password' => 'secret']);
    $this->bob = User::create(['name' => 'Bob Viewer', 'email' => 'bob@tenant.test', 'password' => 'secret']);
    $this->actingAs($this->bob);
});

afterEach(fn () => asecReset());

/**
 * Register exactly these entities (Larsadmin::entities() appends).
 */
function asecEntities(array $entities): void
{
    Larsadmin::flushState();
    Larsadmin::auth(fn () => true);
    Larsadmin::entities($entities);
}

function asecReset(): void
{
    SecurePost::reset();
    SecureUser::reset();
    SecureAuditPost::reset();
    AuditPostPolicy::reset();
    TagEntity::$indexUsing = null;
    TagEntity::$relatableUsing = null;
    TrustProxies::flushState();
}

/**
 * Forget every gate definition and policy: "viewLarsadminAudit" becomes undefined.
 */
function asecForgetGate(): void
{
    app()->forgetInstance(Illuminate\Contracts\Auth\Access\Gate::class);
    Gate::clearResolvedInstances();
}

/**
 * Only Bob's posts are listed (tenant scope by author).
 */
function asecTenantScope(): void
{
    SecurePost::$scope = fn ($query) => $query->where('author_id', auth()->id());
}

function asecPost(User $author, string $title, array $attributes = []): SecureAuditPost
{
    return SecureAuditPost::create($attributes + ['title' => $title, 'status' => 'draft', 'author_id' => $author->id]);
}

function asecHistory(Model|string $record, string $query = '', string $entity = 'secure-posts'): TestResponse
{
    $id = $record instanceof Model ? $record->getKey() : $record;

    return test()->getJson("/admin/api/entities/{$entity}/{$id}/audit".($query !== '' ? "?{$query}" : ''));
}

function asecGlobal(string $query = '', array $headers = []): TestResponse
{
    return test()->get('/admin/audit'.($query !== '' ? "?{$query}" : ''), $headers);
}

/**
 * @return array<string, mixed>
 */
function asecProps(TestResponse $response): array
{
    return $response->assertOk()->viewData('page')['props'];
}

/**
 * Every raw row of the audit table, as stored.
 */
function asecRaw(): string
{
    return (string) json_encode(DB::table('larsadmin_audit_entries')->get(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Insert an entry directly (an entry written earlier, under other rules).
 */
function asecRow(Model $record, array $attributes = []): int
{
    return DB::table('larsadmin_audit_entries')->insertGetId(array_merge([
        'batch_id' => '00000000-0000-0000-0000-0000000000a1',
        'event' => 'updated',
        'entity' => 'secure-posts',
        'auditable_type' => $record->getMorphClass(),
        'auditable_id' => (string) $record->getKey(),
        'actor_type' => User::class,
        'actor_id' => (string) test()->bob->id,
        'actor_guard' => 'web',
        'actor_label' => 'Bob Viewer',
        'changes' => null,
        'meta' => null,
        'ip_address' => '10.0.0.5',
        'user_agent' => 'Mozilla/5.0',
        'created_at' => '2026-10-30 12:00:00',
    ], array_map(fn ($value) => is_array($value) ? json_encode($value) : $value, $attributes)));
}

/**
 * The change rows of one entry, keyed by attribute.
 *
 * @return array<string, array<string, mixed>>
 */
function asecChanges(array $entry): array
{
    return collect($entry['changes'])->keyBy('attribute')->all();
}

/**
 * Every link (url, prevUrl, nextUrl) anywhere in a payload.
 *
 * @return list<string>
 */
function asecLinks(mixed $data): array
{
    $links = [];

    if (is_array($data)) {
        foreach ($data as $key => $value) {
            if (in_array($key, ['url', 'prevUrl', 'nextUrl'], true) && is_string($value)) {
                $links[] = $value;
            }

            $links = [...$links, ...asecLinks($value)];
        }
    }

    return $links;
}

// --- Gate "viewLarsadminAudit" (AC-7, NFR-025) ------------------------------------------------------

it('AC-7: denies both routes and hides the menu and the panel without the gate', function (string $gate) {
    $post = asecPost($this->bob, 'Bob post');
    asecRow($post, ['changes' => ['title' => ['old' => 'Gate leak', 'new' => 'Bob post']]]);

    match ($gate) {
        'undefined' => asecForgetGate(),
        'false' => Gate::define('viewLarsadminAudit', fn () => false),
        'null' => Gate::define('viewLarsadminAudit', fn () => null),
        'Gate::before denies' => Gate::before(fn () => false),
        'granted to another user' => Gate::define('viewLarsadminAudit', fn (User $user) => $user->is($this->alice)),
    };

    $history = asecHistory($post)->assertForbidden();
    $page = asecGlobal()->assertForbidden();

    expect($history->getContent())->not->toContain('Gate leak')
        ->and($page->getContent())->not->toContain('Gate leak');

    $this->get("/admin/entities/secure-posts/{$post->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('larsadmin.audit', null)
        ->where('audit', null));
})->with([
    'undefined (default)' => ['undefined'],
    'returns false' => ['false'],
    'returns null' => ['null'],
    'Gate::before denies' => ['Gate::before denies'],
    'granted to another user only' => ['granted to another user'],
]);

it('AC-7: lets nobody read the log while the gate is undefined, not even the panel admin', function () {
    asecForgetGate();

    expect(Gate::has('viewLarsadminAudit'))->toBeFalse();

    foreach ([$this->bob, $this->alice] as $user) {
        $this->actingAs($user);

        asecGlobal()->assertForbidden();
        asecHistory(asecPost($user, 'Own post'))->assertForbidden();
    }
});

it('AC-7: grants the gate per user (positive control)', function () {
    Gate::define('viewLarsadminAudit', fn (User $user) => $user->is($this->bob));
    $post = asecPost($this->bob, 'Bob post');

    asecHistory($post)->assertOk();
    asecGlobal()->assertOk()->assertInertia(fn (Assert $page) => $page->where('larsadmin.audit', ['url' => '/admin/audit']));

    $this->actingAs($this->alice);

    asecHistory($post)->assertForbidden();
    asecGlobal()->assertForbidden();
});

it('AC-7: keeps guests and users without panel access out', function () {
    $post = asecPost($this->bob, 'Bob post');

    Larsadmin::auth(fn () => false);
    asecHistory($post)->assertForbidden();
    asecGlobal()->assertForbidden();

    Larsadmin::auth(fn () => true);
    auth()->logout();

    asecHistory($post)->assertUnauthorized();
    asecGlobal()->assertRedirect('/admin/login');
});

// --- Entity access on the global page (AC-8, NFR-025) ----------------------------------------------

it('AC-8: shows no entry of an entity the viewer may not list, whatever the filters', function (string $query) {
    Gate::policy(SecureAuditPost::class, AuditPostPolicy::class);
    AuditPostPolicy::$viewAny = false;

    $mallory = User::create(['name' => 'Mallory Hidden', 'email' => 'mallory@tenant.test', 'password' => 'x']);
    $hidden = asecPost($this->alice, 'Hidden headline');
    $tag = Tag::create(['name' => 'Visible tag']);

    asecRow($hidden, [
        'actor_id' => (string) $mallory->id,
        'actor_label' => 'Mallory Hidden',
        'changes' => ['title' => ['old' => 'Draft headline', 'new' => 'Hidden headline']],
    ]);
    asecRow($tag, ['entity' => 'tags', 'event' => 'created']);

    $response = asecGlobal(str_replace('{mallory}', (string) $mallory->id, $query));
    $props = asecProps($response);

    expect(collect($props['entries']['data'])->pluck('entity.uriKey')->unique()->values()->all())->toBeIn([['tags'], []])
        ->and(collect($props['filters'][0]['options'])->pluck('value')->all())->not->toContain('secure-posts')
        ->and(collect($props['filters'][2]['options'])->pluck('label')->all())->not->toContain('Mallory Hidden')
        ->and($response->getContent())->not->toContain('Hidden headline')->not->toContain('Draft headline')->not->toContain('Mallory Hidden');
})->with([
    'no filter' => [''],
    'forged entity key' => ['filters[entity]=secure-posts'],
    'forged entity array' => ['filters[entity][]=secure-posts'],
    'forged entity case' => ['filters[entity]=SECURE-POSTS'],
    'forged entity with a null byte' => ['filters[entity]=secure-posts%00'],
    'forged entity SQL' => ['filters[entity]='.urlencode("tags' OR entity='secure-posts")],
    'forged actor of the hidden entity' => ['filters[actor]='.urlencode(User::class.'|').'{mallory}'],
    'forged event' => ['filters[entity]=secure-posts&filters[event]=updated'],
]);

it('AC-8: does not show entries of another model through a swapped {entity} with the same id', function () {
    $post = asecPost($this->bob, 'Bob post');
    $tag = Tag::create(['name' => 'Tag with the same id']);

    expect($tag->id)->toBe($post->id);

    asecRow($post, ['changes' => ['title' => ['old' => 'Swapped secret', 'new' => 'Bob post']]]);

    $response = asecHistory($tag, entity: 'tags')->assertOk();

    expect($response->json('entries'))->toBe([])
        ->and($response->getContent())->not->toContain('Swapped secret');
});

it('AC-8: rejects a forged entity key in the record history route', function (string $entity) {
    $post = asecPost($this->bob, 'Bob post');
    asecRow($post);

    $this->getJson("/admin/api/entities/{$entity}/{$post->id}/audit")->assertNotFound();
})->with([
    'unknown' => ['secret-posts'],
    'uppercase' => ['SECURE-POSTS'],
    'class name' => [urlencode(SecurePost::class)],
    'traversal' => ['..%2Fsecure-posts'],
    'model morph class' => [urlencode(SecureAuditPost::class)],
]);

it('AC-8: rejects the record history without viewAny or view, and checks the scope first', function () {
    asecTenantScope();
    Gate::policy(SecureAuditPost::class, AuditPostPolicy::class);
    $own = asecPost($this->bob, 'Bob post');
    $foreign = asecPost($this->alice, 'Alice plan');

    AuditPostPolicy::$view = false;
    asecHistory($own)->assertForbidden();
    // Outside the scope: 404, not 403 — the view ability is not an existence oracle.
    asecHistory($foreign)->assertNotFound();

    AuditPostPolicy::$viewAny = false;
    asecHistory($own)->assertForbidden();
    asecHistory($foreign)->assertForbidden();
});

// --- Record history: IDOR and existence oracle (AC-8, NFR-027) -------------------------------------

it('AC-8: answers another tenant\'s record id exactly like a missing one (no existence oracle)', function (string $id) {
    asecTenantScope();
    $alicePost = asecPost($this->alice, 'Alice plan', ['body' => 'Alice confidential body']);
    $own = asecPost($this->bob, 'Bob post');
    asecRow($alicePost, ['changes' => ['body' => ['old' => null, 'new' => 'Alice confidential body']]]);
    asecRow($own);

    $id = $id === '{alice}' ? (string) $alicePost->id : $id;
    $missing = asecHistory('999999')->assertNotFound();
    $response = asecHistory($id)->assertNotFound();

    expect(str_replace($id, '{id}', $response->getContent()))->toBe(str_replace('999999', '{id}', $missing->getContent()))
        ->and($response->headers->get('Content-Type'))->toBe($missing->headers->get('Content-Type'))
        ->and($response->getContent())->not->toContain('Alice');
})->with([
    'another tenant\'s record' => ['{alice}'],
    'missing' => ['424242'],
    'zero' => ['0'],
]);

it('AC-8: answers a malformed record id with a plain 404', function (string $id) {
    asecTenantScope();
    $alicePost = asecPost($this->alice, 'Alice plan');
    asecRow($alicePost, ['changes' => ['title' => ['old' => null, 'new' => 'Alice plan']]]);

    $response = asecHistory(rawurlencode($id))->assertNotFound();

    expect($response->getContent())->not->toContain('Alice')->not->toContain('SQLSTATE')->not->toContain('Exception');
})->with([
    'non-numeric' => ['abc'],
    'SQL' => ['1 OR 1=1'],
    'SQL quote' => ["1' OR '1'='1"],
    'negative' => ['-1'],
    'decimal' => ['1.0'],
    'overlong' => [str_repeat('9', 300)],
    'unicode digits' => ['١'],
    'null byte' => ["1\0"],
]);

it('AC-8: never sends the exception message or the model class of a 404', function () {
    $content = asecHistory('999999')->assertNotFound()->getContent();

    expect($content)->not->toContain('SecureAuditPost')->not->toContain('No query results');
});

// --- Field visibility (AC-9, NFR-027) ---------------------------------------------------------------

it('AC-9: never shows fields hidden from the viewer by canSee, nor unknown attributes', function () {
    $post = asecPost($this->bob, 'Bob post');

    // Written by someone who could see the field.
    $this->put("/admin/entities/secure-posts/{$post->id}", ['title' => 'Bob post v2', 'status' => 'draft', 'note' => 'n0te-for-admins'])
        ->assertSessionHasNoErrors();
    asecRow($post, ['changes' => ['ghost' => ['old' => 'gh0st-old', 'new' => 'gh0st-new'], 'title' => ['old' => 'a', 'new' => 'b']]]);

    expect(asecRaw())->toContain('n0te-for-admins');

    SecurePost::$seeNote = false;

    $history = asecHistory($post)->assertOk();
    $global = asecGlobal();
    $attributes = collect($history->json('entries'))->flatMap(fn (array $entry) => array_column($entry['changes'], 'attribute'))->all();

    expect($attributes)->toBe(['title', 'title'])
        ->and(collect(asecProps($global)['entries']['data'])->flatMap(fn (array $entry) => array_column($entry['changes'], 'attribute'))->all())->toBe(['title', 'title']);

    foreach ([$history, $global] as $response) {
        expect($response->getContent())->not->toContain('n0te-for-admins')->not->toContain('gh0st')->not->toContain('"note"');
    }
});

it('AC-9: never shows action field values hidden by canSee, and re-masks fields that became sensitive', function () {
    $post = asecPost($this->bob, 'Bob post');
    $action = fn (bool $codeSensitive) => new AuditAction('review', fn (ActionFields $fields) => null, [
        Text::make('Reason')->canSee(fn () => SecurePost::$seeNote),
        Text::make('Code')->sensitive($codeSensitive),
        Text::make('Comment'),
    ], 'Review');

    SecurePost::$actions = [$action(false)];

    $this->post('/admin/entities/secure-posts/actions/review', [
        'resources' => [$post->id],
        'reason' => 'r34son-for-admins',
        'code' => 'c0de-1234',
        'comment' => 'Looks fine',
    ])->assertSessionHas('larsadmin.toast.type', 'success');

    expect(asecRaw())->toContain('r34son-for-admins')->toContain('c0de-1234');

    SecurePost::$seeNote = false;
    SecurePost::$actions = [$action(true)];

    $history = asecHistory($post)->assertOk();
    $global = asecGlobal();

    expect($history->json('entries.0.action'))->toBe(['name' => 'Review', 'fields' => [
        ['label' => 'Code', 'masked' => true],
        ['label' => 'Comment', 'value' => ['kind' => 'text', 'text' => 'Looks fine']],
    ]])->and(asecProps($global)['entries']['data'][0]['action'])->toBe($history->json('entries.0.action'));

    foreach ([$history, $global] as $response) {
        expect($response->getContent())->not->toContain('r34son-for-admins')->not->toContain('c0de-1234');
    }

    // The action no longer exists: no field value at all.
    SecurePost::$actions = [];

    expect(asecHistory($post)->json('entries.0.action'))->toBe(['name' => 'Review', 'fields' => []]);
});

// --- Masking (AC-6, ADR-024) ------------------------------------------------------------------------

it('AC-6: never stores nor returns masked values, their ciphertext or their hash', function (string $rule, string $attribute) {
    match ($rule) {
        'sensitive' => SecurePost::$sensitive = [$attribute],
        'hidden' => SecureAuditPost::$extraHidden = [$attribute],
        'encrypted' => SecureAuditPost::$extraCasts = [$attribute => 'encrypted'],
        default => null,
    };

    $this->post('/admin/entities/secure-posts', ['title' => 'Masked post', 'status' => 'draft', $attribute => 'pl41n-create'])->assertSessionHasNoErrors();
    $post = SecureAuditPost::sole();
    $this->put("/admin/entities/secure-posts/{$post->id}", ['title' => 'Masked post', 'status' => 'draft', $attribute => 'pl41n-update'])->assertSessionHasNoErrors();

    $stored = (string) $post->fresh()->getRawOriginal($attribute);
    $history = asecHistory($post)->assertOk();

    $this->delete("/admin/entities/secure-posts/{$post->id}")->assertRedirect();

    $global = asecGlobal();
    $entries = AuditEntry::query()->orderBy('id')->get();
    $raw = asecRaw();

    // Hashed (Password) and encrypted columns hold another form than the plaintext: neither is logged.
    if (in_array($rule, ['password', 'encrypted', 'encrypted-default'], true)) {
        expect($stored)->not->toBe('')->not->toContain('pl41n');
    } else {
        expect($stored)->toBe('pl41n-update');
    }

    expect($entries->pluck('event')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($entries->pluck("changes.{$attribute}")->all())->toBe([AuditValues::MASKED, AuditValues::MASKED, AuditValues::MASKED])
        ->and($raw)->not->toContain('pl41n')->not->toContain($stored)->not->toContain('$2y$')
        ->and(asecChanges($history->json('entries.0'))[$attribute])->toBe(['attribute' => $attribute, 'label' => ucfirst($attribute), 'masked' => true])
        ->and(asecChanges(asecProps($global)['entries']['data'][0])[$attribute]['masked'])->toBeTrue();

    foreach ([$history, $global] as $response) {
        expect($response->getContent())->not->toContain('pl41n')->not->toContain($stored);
    }
})->with([
    'Password field' => ['password', 'secret'],
    'sensitive() field' => ['sensitive', 'body'],
    'model $hidden' => ['hidden-default', 'token'],
    '$hidden added by the host' => ['hidden', 'body'],
    'encrypted cast' => ['encrypted-default', 'private'],
    'encrypted cast added by the host' => ['encrypted', 'body'],
]);

it('AC-6: re-applies masking when a field became sensitive after the entry was written', function (string $rule) {
    $post = asecPost($this->bob, 'Bob post', ['body' => 'v1s1ble-old']);
    $this->put("/admin/entities/secure-posts/{$post->id}", ['title' => 'Bob post', 'status' => 'draft', 'body' => 'v1s1ble-new'])->assertSessionHasNoErrors();

    expect(asecRaw())->toContain('v1s1ble-new');
    expect(asecChanges(asecHistory($post)->json('entries.0'))['body']['new'])->toBe(['kind' => 'text', 'text' => 'v1s1ble-new']);

    match ($rule) {
        'sensitive' => SecurePost::$sensitive = ['body'],
        'hidden' => SecureAuditPost::$extraHidden = ['body'],
        'encrypted' => SecureAuditPost::$extraCasts = ['body' => 'encrypted'],
    };

    $history = asecHistory($post)->assertOk();
    $global = asecGlobal();

    expect(asecChanges($history->json('entries.0'))['body'])->toBe(['attribute' => 'body', 'label' => 'Body', 'masked' => true])
        ->and(asecChanges(asecProps($global)['entries']['data'][0])['body'])->toBe(['attribute' => 'body', 'label' => 'Body', 'masked' => true]);

    foreach ([$history, $global] as $response) {
        expect($response->getContent())->not->toContain('v1s1ble');
    }
})->with([
    'became sensitive()' => ['sensitive'],
    'added to $hidden' => ['hidden'],
    'cast became encrypted' => ['encrypted'],
]);

it('AC-6: shows a stored masked change as masked even when the field is no longer sensitive', function () {
    $post = asecPost($this->bob, 'Bob post');
    asecRow($post, ['changes' => ['body' => ['masked' => true, 'old' => 'smuggled-old', 'new' => 'smuggled-new']]]);

    $response = asecHistory($post)->assertOk();

    expect(asecChanges($response->json('entries.0'))['body'])->toBe(['attribute' => 'body', 'label' => 'Body', 'masked' => true])
        ->and($response->getContent())->not->toContain('smuggled');
});

// --- Related titles (AC-9) --------------------------------------------------------------------------

it('AC-9: leaks no BelongsTo title of a related record outside the viewer\'s scope', function (string $rule, bool $bobVisible) {
    $post = asecPost($this->alice, 'Shared post');
    $this->put("/admin/entities/secure-posts/{$post->id}", ['title' => 'Shared post', 'status' => 'draft', 'author' => $this->bob->id])->assertSessionHasNoErrors();

    match ($rule) {
        'scope' => SecureUser::$scope = fn ($query) => $query->whereKey(auth()->id()),
        'view' => Gate::policy(User::class, OwnRecordPolicy::class),
        'viewAny' => Gate::policy(User::class, DenyAllPolicy::class),
        'unregistered' => asecEntities([SecurePost::class]),
    };

    $history = asecHistory($post)->assertOk();
    $author = asecChanges($history->json('entries.0'))['author'];

    expect($author['old'])->toBe(['kind' => 'record', 'id' => $this->alice->id, 'title' => null, 'url' => null])
        ->and($author['new'])->toBe($bobVisible
            ? ['kind' => 'record', 'id' => $this->bob->id, 'title' => 'Bob Viewer', 'url' => "/admin/entities/secure-users/{$this->bob->id}"]
            : ['kind' => 'record', 'id' => $this->bob->id, 'title' => null, 'url' => null])
        ->and($history->getContent())->not->toContain('Alice Owner')->not->toContain('alice@tenant.test');

    if ($rule !== 'unregistered') {
        $global = asecGlobal();

        expect(asecChanges(asecProps($global)['entries']['data'][0])['author'])->toBe($author)
            ->and($global->getContent())->not->toContain('Alice Owner');
    }
})->with([
    'outside the related detail scope' => ['scope', true],
    'view denied by the policy' => ['view', true],
    'related entity not listable' => ['viewAny', false],
    'related entity no longer registered' => ['unregistered', false],
]);

it('AC-9: leaks no attached record title outside the related scope', function (string $rule) {
    $post = Post::create(['title' => 'Hello', 'status' => 'draft']);
    $tag = Tag::create(['name' => 'Classified tag']);

    $this->post("/admin/entities/posts/{$post->id}/attach/tags", ['related' => $tag->id])->assertSessionHasNoErrors();
    $this->delete("/admin/entities/posts/{$post->id}/attach/tags/{$tag->id}")->assertRedirect();

    match ($rule) {
        'scope' => TagEntity::$indexUsing = fn ($query) => $query->whereKeyNot($tag->id),
        'policy' => Gate::policy(Tag::class, DenyAllPolicy::class),
    };

    $history = asecHistory($post, entity: 'posts')->assertOk();
    $global = asecGlobal();
    // Without viewAny on the related entity even its label is not shown (SECA-11).
    $label = $rule === 'policy' ? 'tags' : TagEntity::label();
    $expected = ['entity' => 'tags', 'entityLabel' => $label, 'id' => (string) $tag->id, 'title' => null, 'url' => null];

    expect(array_column($history->json('entries'), 'related'))->toBe([$expected, $expected])
        ->and(array_column(asecProps($global)['entries']['data'], 'related'))->toBe([$expected, $expected]);

    foreach ([$history, $global] as $response) {
        expect($response->getContent())->not->toContain('Classified tag');
    }
})->with([
    'outside the related detail scope' => ['scope'],
    'related entity denied by a policy' => ['policy'],
]);

it('AC-9: shows no title of a record the viewer may not view on the global page', function () {
    asecTenantScope();
    $foreign = asecPost($this->alice, 'Alice plan');
    asecRow($foreign, ['event' => 'created']);

    $props = asecProps(asecGlobal());

    expect($props['entries']['data'][0]['record'])->toBe(['id' => (string) $foreign->id, 'title' => null, 'url' => null])
        ->and(json_encode($props))->not->toContain('Alice plan');
});

// --- Transactions (ADR-022, AC-5) -------------------------------------------------------------------

it('AC-5: rolls the change back and leaks nothing when the entry cannot be written', function (string $failure, string $operation) {
    $post = asecPost($this->bob, 'Original title');
    $taggable = Post::create(['title' => 'Hello', 'status' => 'draft']);
    $tag = Tag::create(['name' => 'News']);
    SecurePost::$actions = [new AuditAction('publish', function (ActionFields $fields, Collection $models) {
        $models->each->update(['status' => 'live']);

        return null;
    })];

    match ($failure) {
        'missing table' => config(['larsadmin.audit.table' => 'missing_audit_table']),
        'insert rejected' => DB::statement("CREATE TRIGGER reject_audit BEFORE INSERT ON larsadmin_audit_entries BEGIN SELECT RAISE(ABORT, 'audit storage refused'); END"),
    };

    $response = match ($operation) {
        'create' => $this->post('/admin/entities/secure-posts', ['title' => 'New title', 'status' => 'draft']),
        'update' => $this->put("/admin/entities/secure-posts/{$post->id}", ['title' => 'Changed title', 'status' => 'draft']),
        'delete' => $this->delete("/admin/entities/secure-posts/{$post->id}"),
        'attach' => $this->post("/admin/entities/posts/{$taggable->id}/attach/tags", ['related' => $tag->id]),
        'action' => $this->post('/admin/entities/secure-posts/actions/publish', ['resources' => [$post->id]]),
    };

    $response->assertServerError();

    expect($response->getContent())->not->toContain('missing_audit_table')->not->toContain('audit storage refused')
        ->not->toContain('SQLSTATE')->not->toContain('larsadmin migration')
        ->and(SecureAuditPost::query()->pluck('title')->all())->toBe(['Original title'])
        ->and($post->fresh()->status->value)->toBe('draft')
        ->and($taggable->tags()->count())->toBe(0)
        ->and(DB::table('larsadmin_audit_entries')->count())->toBe(0);
})->with([
    'missing table' => ['missing table'],
    'insert rejected' => ['insert rejected'],
])->with([
    'create' => ['create'],
    'update' => ['update'],
    'delete' => ['delete'],
    'attach' => ['attach'],
    'action' => ['action'],
]);

it('AC-5: leaves no entry when the change is rolled back after the entry was written', function () {
    $post = asecPost($this->bob, 'Original title');
    // Fails after the audit insert of the same transaction (the post update runs first).
    DB::statement("CREATE TRIGGER reject_after_audit AFTER INSERT ON larsadmin_audit_entries BEGIN SELECT RAISE(ABORT, 'late failure'); END");

    $this->put("/admin/entities/secure-posts/{$post->id}", ['title' => 'Changed title', 'status' => 'draft'])->assertServerError();

    expect($post->fresh()->title)->toBe('Original title')
        ->and(DB::table('larsadmin_audit_entries')->count())->toBe(0);
});

// --- Immutability (ADR-025 §8.10) -------------------------------------------------------------------

it('offers no route that edits or deletes entries', function (string $method, string $uri) {
    $post = asecPost($this->bob, 'Bob post');
    $id = asecRow($post, ['changes' => ['title' => ['old' => 'a', 'new' => 'b']]]);
    $before = (array) DB::table('larsadmin_audit_entries')->find($id);

    $uri = str_replace(['{post}', '{entry}'], [(string) $post->id, (string) $id], $uri);

    $status = $this->json($method, $uri, ['event' => 'forged', 'changes' => null])->status();

    expect($status)->toBeIn([404, 405])
        ->and((array) DB::table('larsadmin_audit_entries')->find($id))->toBe($before);
})->with(function () {
    $cases = [];

    foreach (['PUT', 'PATCH', 'DELETE', 'POST'] as $method) {
        foreach ([
            'global page' => '/admin/audit',
            'global entry' => '/admin/audit/{entry}',
            'record history' => '/admin/api/entities/secure-posts/{post}/audit',
            'record entry' => '/admin/api/entities/secure-posts/{post}/audit/{entry}',
            'api entry' => '/admin/api/audit/{entry}',
        ] as $name => $uri) {
            $cases["{$method} {$name}"] = [$method, $uri];
        }
    }

    return $cases;
});

it('keeps entry instances immutable', function () {
    $post = asecPost($this->bob, 'Bob post');
    $id = asecRow($post);

    expect(fn () => AuditEntry::findOrFail($id)->update(['event' => 'forged']))->toThrow(LogicException::class)
        ->and(fn () => AuditEntry::findOrFail($id)->delete())->toThrow(LogicException::class)
        ->and(fn () => AuditEntry::findOrFail($id)->forceDelete())->toThrow(LogicException::class)
        ->and(fn () => AuditEntry::findOrFail($id)->deleteQuietly())->toThrow(LogicException::class)
        ->and(fn () => AuditEntry::destroy($id))->toThrow(LogicException::class)
        ->and(DB::table('larsadmin_audit_entries')->where('id', $id)->value('event'))->toBe('updated');
});

it('rejects saving a changed entry instance', function () {
    $post = asecPost($this->bob, 'Bob post');
    $id = asecRow($post);

    $entry = AuditEntry::findOrFail($id);
    $entry->event = 'forged';

    expect(fn () => $entry->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->saveQuietly())->toThrow(LogicException::class)
        ->and(fn () => $entry->push())->toThrow(LogicException::class)
        ->and(fn () => AuditEntry::findOrFail($id)->increment('id'))->toThrow(LogicException::class)
        ->and(fn () => AuditEntry::findOrFail($id)->forceFill(['actor_label' => 'Forged'])->save())->toThrow(LogicException::class)
        ->and(DB::table('larsadmin_audit_entries')->where('id', $id)->value('event'))->toBe('updated')
        ->and(DB::table('larsadmin_audit_entries')->where('id', $id)->value('actor_label'))->not->toBe('Forged');
});

it('cannot forge an entry through Eloquent', function () {
    $forged = ['batch_id' => '00000000-0000-0000-0000-000000000009', 'event' => 'deleted', 'entity' => 'secure-posts', 'actor_label' => 'Alice', 'created_at' => now()];

    expect(fn () => AuditEntry::create($forged))->toThrow(MassAssignmentException::class)
        ->and(fn () => AuditEntry::forceCreate($forged))->toThrow(LogicException::class)
        ->and(fn () => (new AuditEntry)->forceFill($forged)->save())->toThrow(LogicException::class)
        ->and(DB::table('larsadmin_audit_entries')->count())->toBe(0);
});

// --- Bad input (AC-14, NFR-028) ---------------------------------------------------------------------

it('AC-14: ignores hostile filters and pages on the global page', function (string $query) {
    $post = asecPost($this->bob, 'Bob post');
    asecRow($post);
    asecRow($post, ['event' => 'created']);

    $props = asecProps(asecGlobal($query));

    expect($props['entries']['data'])->toHaveCount(2)
        ->and($props['entries']['page'])->toBe(1)
        ->and((array) $props['query']['filters'])->toBe([])
        ->and(Schema::hasTable('larsadmin_audit_entries'))->toBeTrue();
})->with([
    'event SQL' => ['filters[event]='.urlencode("updated' OR '1'='1")],
    'event with a suffix' => ['filters[event]=updated%20OR%201'],
    'event array' => ['filters[event][]=updated'],
    'nested entity' => ['filters[entity][a][b]=secure-posts'],
    'overlong entity' => ['filters[entity]='.str_repeat('a', 10000)],
    'actor without separator' => ['filters[actor]='.urlencode(User::class)],
    'actor array' => ['filters[actor][]=system'],
    'date impossible' => ['filters[date][from]=2026-02-31'],
    'date overflow' => ['filters[date][from]=9999-99-99&filters[date][to]=0000-00-00'],
    'date SQL' => ['filters[date][from]='.urlencode("2026-01-01' OR 1=1 --")],
    'date nested array' => ['filters[date][from][]=2026-01-01'],
    'date with time' => ['filters[date][from]='.urlencode('2026-10-30 00:00:00')],
    'overlong date' => ['filters[date][to]='.str_repeat('9', 5000)],
    'filters scalar' => ['filters=1'],
    'page SQL' => ['page='.urlencode('1; DROP TABLE larsadmin_audit_entries')],
    'page array' => ['page[]=2'],
    'page float' => ['page=1.5'],
    'page exponent' => ['page=1e3'],
    'page hex' => ['page=0x10'],
    'page negative' => ['page=-3'],
    'page overlong' => ['page='.str_repeat('9', 400)],
    'page unicode digits' => ['page=%D9%A2'],
]);

it('AC-14: applies a well-formed foreign or hostile actor only as a bound literal', function (string $actor) {
    $post = asecPost($this->bob, 'Bob post');
    asecRow($post);

    $props = asecProps(asecGlobal('filters[actor]='.urlencode($actor)));

    expect($props['entries']['data'])->toHaveCount(0)
        ->and((array) $props['query']['filters'])->toBe(['actor' => $actor])
        ->and(DB::table('larsadmin_audit_entries')->count())->toBe(1);
})->with([
    'actor SQL' => ["x|1' OR 1=1 --"],
    'forged actor type' => ['App\Models\Admin|1'],
]);

it('AC-14: applies a filter value with a NUL byte only as the exact option', function () {
    $post = asecPost($this->bob, 'Bob post');
    asecRow($post);
    asecRow($post, ['event' => 'created']);

    // The NUL byte is stripped before the filter sees it: the plain "updated" option, never "updated\0".
    $props = asecProps(asecGlobal('filters[event]=updated%00'));

    expect((array) $props['query']['filters'])->toBe(['event' => 'updated'])
        ->and(array_column($props['entries']['data'], 'event'))->toBe(['updated']);
});

it('AC-14: answers 422 to a hostile page on the record history', function (string $page) {
    $post = asecPost($this->bob, 'Bob post');
    asecRow($post);

    $response = asecHistory($post, $page)
        ->assertStatus(422)
        ->assertJsonValidationErrors('page')
        ->assertHeader('Cache-Control', 'no-store, private');

    expect(array_keys($response->json()))->toBe(['message', 'errors'])
        ->and($response->getContent())->not->toContain('Exception')->not->toContain('SQLSTATE');
})->with([
    'SQL' => ['page='.urlencode('1; DROP TABLE larsadmin_audit_entries')],
    'float' => ['page=1.5'],
    'exponent' => ['page=1e3'],
    'hex' => ['page=0x10'],
    'trailing text' => ['page=1abc'],
    'plus sign' => ['page=%2B1'],
    'empty' => ['page='],
    'overlong' => ['page='.str_repeat('9', 400)],
    'unicode digits' => ['page=%D9%A2'],
    'nested array' => ['page[a][b]=1'],
]);

// --- Responses --------------------------------------------------------------------------------------

it('marks every audit response no-store', function () {
    $post = asecPost($this->bob, 'Bob post');
    asecRow($post);

    asecHistory($post)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    asecGlobal()->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $version = app(HandleInertiaRequests::class)->version(request());

    $this->get('/admin/audit', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version])
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('never sends exception messages when the audit table is missing', function () {
    $post = asecPost($this->bob, 'Bob post');
    Schema::drop('larsadmin_audit_entries');

    $history = asecHistory($post)->assertServerError();
    $global = asecGlobal()->assertServerError();

    expect($history->json())->toBe(['message' => 'Server Error']);

    foreach ([$history, $global] as $response) {
        expect($response->getContent())->not->toContain('larsadmin_audit_entries')->not->toContain('SQLSTATE')
            ->not->toContain('no such table')->not->toContain('Exception');
    }
});

// --- Value limits (AC-13) ---------------------------------------------------------------------------

it('AC-13: truncates a huge value to max_value_length', function (string $value, int $length) {
    $post = asecPost($this->bob, 'Bob post');

    $this->put("/admin/entities/secure-posts/{$post->id}", ['title' => 'Bob post', 'status' => 'draft', 'body' => $value])->assertSessionHasNoErrors();

    $change = AuditEntry::sole()->changes['body'];
    $presented = asecChanges(asecHistory($post)->json('entries.0'))['body']['new'];

    expect(mb_strlen($change['new']))->toBe($length)
        ->and(mb_check_encoding($change['new'], 'UTF-8'))->toBeTrue()
        ->and($change['truncated'])->toBe(['new'])
        ->and(mb_strlen($presented['text']))->toBe($length)
        ->and($presented['truncated'])->toBeTrue()
        ->and(strlen((string) DB::table('larsadmin_audit_entries')->value('changes')))->toBeLessThan(5 * $length + 200);
})->with([
    'one megabyte of ASCII' => [str_repeat('x', 1_000_000), 1000],
    'four-byte characters' => [str_repeat('😀', 5000), 1000],
]);

it('AC-13: keeps the stored changes within 64 KB when every value is huge', function () {
    config(['larsadmin.audit.max_value_length' => 1_000_000]);
    $post = asecPost($this->bob, 'Bob post');

    $this->put("/admin/entities/secure-posts/{$post->id}", [
        'title' => str_repeat('t', 40000),
        'status' => 'draft',
        'body' => str_repeat('b', 40000),
        'note' => str_repeat('n', 40000),
    ])->assertSessionHasNoErrors();

    $entry = AuditEntry::sole();
    $response = asecHistory($post)->assertOk();

    expect(strlen((string) DB::table('larsadmin_audit_entries')->value('changes')))->toBeLessThanOrEqual(AuditValues::MAX_CHANGES_BYTES)
        ->and($entry->meta)->toBe(['truncated' => true])
        ->and(collect($entry->changes)->filter(fn (array $change) => ($change['omitted'] ?? false) === true))->not->toBeEmpty()
        ->and(strlen($response->getContent()))->toBeLessThan(2 * AuditValues::MAX_CHANGES_BYTES);
});

it('AC-13: truncates huge action field values', function () {
    $post = asecPost($this->bob, 'Bob post');
    SecurePost::$actions = [new AuditAction('review', fn () => null, [Text::make('Reason')], 'Review')];

    $this->post('/admin/entities/secure-posts/actions/review', ['resources' => [$post->id], 'reason' => str_repeat('r', 200000)])
        ->assertSessionHas('larsadmin.toast.type', 'success');

    expect(mb_strlen(AuditEntry::sole()->meta['fields']['reason']))->toBe(1000)
        ->and(strlen((string) DB::table('larsadmin_audit_entries')->value('meta')))->toBeLessThan(2000);
});

// --- IP address, user agent and actor spoofing ------------------------------------------------------

it('stores the IP address and the user agent only as configured', function (bool $ip, bool $agent) {
    config(['larsadmin.audit.store_ip' => $ip, 'larsadmin.audit.store_user_agent' => $agent]);
    $post = asecPost($this->bob, 'Bob post');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeader('User-Agent', 'Agent/1.0 <script>')
        ->put("/admin/entities/secure-posts/{$post->id}", ['title' => 'Changed', 'status' => 'draft'])
        ->assertSessionHasNoErrors();

    $row = DB::table('larsadmin_audit_entries')->sole();

    expect($row->ip_address)->toBe($ip ? '203.0.113.7' : null)
        ->and($row->user_agent)->toBe($agent ? 'Agent/1.0 <script>' : null)
        ->and($row->actor_id)->toBe((string) $this->bob->id);

    // The record panel never exposes them, only the global page.
    expect(asecHistory($post)->getContent())->not->toContain('203.0.113.7')->not->toContain('Agent/1.0');
})->with([
    'both stored' => [true, true],
    'IP off' => [false, true],
    'user agent off' => [true, false],
    'both off' => [false, false],
]);

/*
 * The IP is Request::ip(): forwarding headers count only from a proxy trusted through Laravel's
 * TrustProxies middleware (TrustProxies::at() / ->trustProxies()). By default no proxy is trusted.
 */
it('takes the IP from forwarding headers only behind a trusted proxy', function (?string $trusted, string $expected) {
    if ($trusted !== null) {
        TrustProxies::at($trusted);
    }

    $post = asecPost($this->bob, 'Bob post');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeaders([
            'X-Forwarded-For' => '198.51.100.9',
            'X-Real-IP' => '6.6.6.6',
            'Client-IP' => '6.6.6.7',
            'Forwarded' => 'for=6.6.6.8',
        ])
        ->put("/admin/entities/secure-posts/{$post->id}", ['title' => 'Changed', 'status' => 'draft'])
        ->assertSessionHasNoErrors();

    $row = DB::table('larsadmin_audit_entries')->sole();

    expect($row->ip_address)->toBe($expected)
        ->and($row->actor_id)->toBe((string) $this->bob->id)
        ->and($row->actor_label)->toBe('Bob Viewer');
})->with([
    'no trusted proxy (default)' => [null, '203.0.113.7'],
    'another proxy trusted' => ['10.9.9.9', '203.0.113.7'],
    'the sender is a trusted proxy' => ['203.0.113.7', '198.51.100.9'],
]);

it('takes the actor from the authenticated user, never from request input', function () {
    $post = asecPost($this->bob, 'Bob post');

    $this->withHeaders(['X-User-Id' => (string) $this->alice->id, 'X-Actor' => 'Alice Owner'])
        ->put("/admin/entities/secure-posts/{$post->id}", [
            'title' => 'Changed',
            'status' => 'draft',
            'actor_id' => $this->alice->id,
            'actor_label' => 'Alice Owner',
            'user_id' => $this->alice->id,
        ])
        ->assertSessionHasNoErrors();

    $row = DB::table('larsadmin_audit_entries')->sole();

    expect($row->actor_type)->toBe(User::class)
        ->and($row->actor_id)->toBe((string) $this->bob->id)
        ->and($row->actor_label)->toBe('Bob Viewer');
});

// --- Disabled log (AC-12, NFR-052) ------------------------------------------------------------------

it('AC-12: never touches the audit table while disabled, and the panel keeps working without it', function () {
    config(['larsadmin.audit.enabled' => false]);
    Schema::drop('larsadmin_audit_entries');
    $post = asecPost($this->bob, 'Bob post');
    $taggable = Post::create(['title' => 'Hello', 'status' => 'draft']);
    $tag = Tag::create(['name' => 'News']);
    SecurePost::$actions = [new AuditAction('publish', function (ActionFields $fields, Collection $models) {
        $models->each->update(['status' => 'live']);

        return null;
    })];

    DB::enableQueryLog();

    $this->get('/admin/entities/secure-posts')->assertOk()->assertInertia(fn (Assert $page) => $page->where('larsadmin.audit', null));
    $this->get("/admin/entities/secure-posts/{$post->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('audit', null)->where('larsadmin.audit', null));
    $this->post('/admin/entities/secure-posts', ['title' => 'Created', 'status' => 'draft'])->assertSessionHasNoErrors()->assertRedirect();
    $this->put("/admin/entities/secure-posts/{$post->id}", ['title' => 'Changed', 'status' => 'draft'])->assertSessionHasNoErrors();
    $this->post('/admin/entities/secure-posts/actions/publish', ['resources' => [$post->id]])->assertSessionHas('larsadmin.toast.type', 'success');
    $this->post("/admin/entities/posts/{$taggable->id}/attach/tags", ['related' => $tag->id])->assertSessionHasNoErrors();
    $this->delete("/admin/entities/posts/{$taggable->id}/attach/tags/{$tag->id}")->assertRedirect();
    $this->delete("/admin/entities/secure-posts/{$post->id}")->assertRedirect();
    asecGlobal()->assertNotFound();
    asecHistory('1')->assertNotFound();

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'larsadmin_audit_entries')))->toBeEmpty()
        ->and(SecureAuditPost::query()->pluck('title')->all())->toBe(['Created'])
        ->and(Schema::hasTable('larsadmin_audit_entries'))->toBeFalse();
});

// --- Links ------------------------------------------------------------------------------------------

it('links only to the panel\'s own routes, whatever the Host headers', function () {
    config(['larsadmin.audit.per_page' => 2]);
    $post = asecPost($this->bob, 'Bob post');
    $this->put("/admin/entities/secure-posts/{$post->id}", ['title' => 'Bob post', 'status' => 'draft', 'author' => $this->alice->id])->assertSessionHasNoErrors();
    $this->put("/admin/entities/secure-posts/{$post->id}", ['title' => 'Bob post', 'status' => 'draft', 'author' => $this->bob->id])->assertSessionHasNoErrors();
    asecRow($post, ['event' => 'created']);

    $headers = ['Host' => 'evil.example', 'X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https'];

    $global = asecProps(asecGlobal('page=2', $headers));
    $history = $this->getJson("/admin/api/entities/secure-posts/{$post->id}/audit", $headers)->assertOk()->json();
    $links = [...asecLinks($global['entries']), ...asecLinks($history)];

    expect($links)->toContain('/admin/audit?page=1')
        ->toContain("/admin/entities/secure-posts/{$post->id}")
        ->toContain("/admin/entities/secure-users/{$this->bob->id}");

    foreach ($links as $link) {
        expect($link)->toMatch('#^/admin/(audit|entities/[a-z-]+/[0-9]+)(\?|$)#')->not->toContain('evil');
    }
});

it('gives no link for a record key that is not a safe path segment', function (string $key) {
    $note = Note::create(['id' => $key, 'title' => 'Odd key']);
    asecRow($note, ['entity' => 'notes', 'event' => 'created']);

    $entry = asecProps(asecGlobal())['entries']['data'][0];

    expect($entry['record']['id'])->toBe($key)
        ->and($entry['record']['url'])->toBeNull()
        ->and(asecLinks($entry))->toBe([]);
})->with([
    'dot dot' => ['..'],
    'traversal' => ['../users/1'],
    'backslash' => ['a\\b'],
    'control character' => ["a\tb"],
]);

it('gives the detail page no history url for a key that is not a safe path segment', function () {
    $note = Note::create(['id' => 'a\\b', 'title' => 'Odd key']);

    $this->get('/admin/entities/notes/'.rawurlencode($note->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('audit', null));
});
