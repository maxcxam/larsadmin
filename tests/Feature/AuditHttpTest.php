<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Audit\AuditFilters;
use Maxcxam\Larsadmin\Fields\Password;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\Actions\AuditAction;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\AuditPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\AuditPostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\AuditSchema;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\CanSeePost;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\UnauditedTag;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\TagEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\TaggedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\User as UserEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\Security\DenyAllPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Tag;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    config(['larsadmin.audit.enabled' => true, 'larsadmin.locale' => 'uk', 'larsadmin.timezone' => 'Europe/Kyiv']);
    AuditSchema::create();
    CanSeePost::reset();
    AuditPostPolicy::reset();
    TagEntity::$indexUsing = null;

    Larsadmin::auth(fn () => true);
    Larsadmin::entities([CanSeePost::class, UserEntity::class, TagEntity::class, UnauditedTag::class]);
    Gate::define('viewLarsadminAudit', fn () => true);

    $this->admin = User::create(['name' => 'Олена Коваль', 'email' => 'olena@example.com', 'password' => 'secret']);
    $this->actingAs($this->admin);
    $this->post = AuditPost::create(['title' => 'Київ', 'status' => 'draft']);
});

afterEach(function () {
    CanSeePost::reset();
    AuditPostPolicy::reset();
});

/**
 * Insert an entry of the post (or of the given entity/record) directly.
 */
function auditRow(array $attributes = []): int
{
    $post = test()->post;

    return DB::table('larsadmin_audit_entries')->insertGetId(array_merge([
        'batch_id' => '00000000-0000-0000-0000-000000000001',
        'event' => 'updated',
        'entity' => 'can-see-posts',
        'auditable_type' => AuditPost::class,
        'auditable_id' => (string) $post->id,
        'actor_type' => User::class,
        'actor_id' => (string) test()->admin->id,
        'actor_guard' => 'web',
        'actor_label' => 'Олена Коваль',
        'changes' => null,
        'meta' => null,
        'ip_address' => '10.0.0.5',
        'user_agent' => 'Mozilla/5.0',
        'created_at' => '2026-10-30 12:05:11',
    ], array_map(fn ($value) => is_array($value) ? json_encode($value) : $value, $attributes)));
}

function historyOf(AuditPost $post, string $query = '')
{
    return test()->getJson("/admin/api/entities/can-see-posts/{$post->id}/audit".($query !== '' ? "?{$query}" : ''));
}

// Access (AC-7, AC-8)

it('answers 404 on both routes while the log is disabled', function () {
    config(['larsadmin.audit.enabled' => false]);

    historyOf($this->post)->assertNotFound();
    $this->get('/admin/audit')->assertNotFound();
});

it('answers 403 without the gate, also when it is undefined (AC-7)', function () {
    Gate::define('viewLarsadminAudit', fn () => false);
    historyOf($this->post)->assertForbidden();
    $this->get('/admin/audit')->assertForbidden();

    $this->app->forgetInstance(Illuminate\Contracts\Auth\Access\Gate::class);
    Gate::clearResolvedInstances();
    expect(Gate::has('viewLarsadminAudit'))->toBeFalse();

    historyOf($this->post)->assertForbidden();
    $this->get('/admin/audit')->assertForbidden();
});

it('honours Gate::before (AC-7)', function () {
    $this->app->forgetInstance(Illuminate\Contracts\Auth\Access\Gate::class);
    Gate::clearResolvedInstances();
    Gate::before(fn () => true);

    historyOf($this->post)->assertOk();
    $this->get('/admin/audit')->assertOk();
});

it('applies viewAny, detailQuery and view to the record history (AC-8)', function () {
    Gate::policy(AuditPost::class, AuditPostPolicy::class);

    AuditPostPolicy::$view = false;
    historyOf($this->post)->assertForbidden();

    AuditPostPolicy::$viewAny = false;
    historyOf($this->post)->assertForbidden();

    AuditPostPolicy::reset();
    CanSeePost::$scope = fn ($query) => $query->whereKeyNot(test()->post->id);
    historyOf($this->post)->assertNotFound();

    $this->getJson('/admin/api/entities/unknown/1/audit')->assertNotFound();
    $this->getJson('/admin/api/entities/can-see-posts/abc/audit')->assertNotFound();
    $this->getJson('/admin/api/entities/can-see-posts/999/audit')->assertNotFound();
});

// Record history (§9.2)

it('serializes the history of a record newest first (§9.2)', function () {
    $author = User::create(['name' => 'Іван', 'email' => 'ivan@example.com', 'password' => 'x']);
    auditRow(['event' => 'created', 'changes' => ['title' => ['old' => null, 'new' => 'Київ']]]);
    $id = auditRow(['changes' => [
        'title' => ['old' => 'Київ', 'new' => 'Київ восени'],
        'status' => ['old' => 'draft', 'new' => 'live'],
        'published' => ['old' => false, 'new' => true],
        'published_at' => ['old' => null, 'new' => '2026-10-30T14:05:11+00:00'],
        'published_on' => ['old' => '2026-10-29', 'new' => '2026-10-30T00:00:00+00:00'],
        'author' => ['old' => $author->id, 'new' => 999],
        'attachments' => ['old' => [['name' => 'a.jpg', 'path' => 'larsadmin/x1.jpg']], 'new' => []],
        'body' => ['old' => 'x', 'new' => str_repeat('y', 10), 'truncated' => ['new']],
        'token' => ['old' => 'leaked-token', 'new' => 'leaked-token-2'],
        'secret' => ['masked' => true],
    ]]);

    $response = historyOf($this->post)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    $json = $response->json();
    $entry = $json['entries'][0];

    expect($json['page'])->toBe(1)
        ->and($json['perPage'])->toBe(10)
        ->and($json['hasMore'])->toBeFalse()
        ->and($json['timezone'])->toBe('Europe/Kyiv')
        ->and($json['entries'])->toHaveCount(2)
        ->and($entry['id'])->toBe($id)
        ->and($entry['event'])->toBe('updated')
        ->and($entry['createdAt'])->toBe('2026-10-30T14:05:11+02:00')
        ->and($entry['actor'])->toBe(['label' => 'Олена Коваль', 'id' => (string) $this->admin->id, 'system' => false])
        ->and($entry['action'])->toBeNull()
        ->and($entry['related'])->toBeNull()
        ->and($entry['soft'])->toBeFalse()
        ->and($entry['recordDeleted'])->toBeFalse()
        ->and($entry)->not->toHaveKeys(['ip', 'userAgent', 'entity', 'record'])
        ->and($entry['changes'])->toBe([
            ['attribute' => 'title', 'label' => 'Title', 'old' => ['kind' => 'text', 'text' => 'Київ'], 'new' => ['kind' => 'text', 'text' => 'Київ восени']],
            ['attribute' => 'status', 'label' => 'Status', 'old' => ['kind' => 'text', 'text' => 'Чернетка'], 'new' => ['kind' => 'text', 'text' => 'Опубліковано']],
            ['attribute' => 'published', 'label' => 'Published', 'old' => ['kind' => 'boolean', 'value' => false], 'new' => ['kind' => 'boolean', 'value' => true]],
            ['attribute' => 'published_at', 'label' => 'Published at', 'old' => ['kind' => 'empty'], 'new' => ['kind' => 'datetime', 'value' => '2026-10-30T14:05:11+00:00']],
            ['attribute' => 'published_on', 'label' => 'Published on', 'old' => ['kind' => 'text', 'text' => '2026-10-29'], 'new' => ['kind' => 'text', 'text' => '2026-10-30']],
            ['attribute' => 'author', 'label' => 'Author',
                'old' => ['kind' => 'record', 'id' => $author->id, 'title' => 'Іван', 'url' => "/admin/entities/users/{$author->id}"],
                'new' => ['kind' => 'record', 'id' => 999, 'title' => null, 'url' => null]],
            ['attribute' => 'attachments', 'label' => 'Attachments', 'old' => ['kind' => 'files', 'files' => ['a.jpg']], 'new' => ['kind' => 'files', 'files' => []]],
            ['attribute' => 'body', 'label' => 'Body', 'old' => ['kind' => 'text', 'text' => 'x'], 'new' => ['kind' => 'text', 'text' => 'yyyyyyyyyy', 'truncated' => true]],
            ['attribute' => 'token', 'label' => 'Token', 'masked' => true],
            ['attribute' => 'secret', 'label' => 'Secret', 'masked' => true],
        ])
        ->and($response->getContent())->not->toContain('larsadmin/x1.jpg')->not->toContain('leaked-token');
});

it('hides fields the viewer may not see and unknown attributes (AC-9)', function () {
    auditRow(['changes' => [
        'note' => ['old' => 'a', 'new' => 'b'],
        'ghost' => ['old' => 'removed field', 'new' => 'x'],
        'title' => ['old' => 'A', 'new' => 'B'],
        'weird' => 'not an array',
    ]]);

    expect(collect(historyOf($this->post)->json('entries.0.changes'))->pluck('attribute')->all())->toBe(['title']);

    CanSeePost::$seeNote = true;

    expect(collect(historyOf($this->post)->json('entries.0.changes'))->pluck('attribute')->all())->toBe(['note', 'title']);
});

it('hides BelongsTo titles outside the related scope (AC-9)', function () {
    $author = User::create(['name' => 'Іван', 'email' => 'ivan@example.com', 'password' => 'x']);
    auditRow(['changes' => ['author' => ['old' => null, 'new' => $author->id], 'published' => ['old' => 0, 'new' => 'maybe']]]);

    Gate::policy(User::class, DenyAllPolicy::class);

    $changes = historyOf($this->post)->json('entries.0.changes');

    expect($changes[0]['new'])->toBe(['kind' => 'record', 'id' => $author->id, 'title' => null, 'url' => null])
        ->and($changes[1]['old'])->toBe(['kind' => 'boolean', 'value' => false])
        ->and($changes[1]['new'])->toBe(['kind' => 'text', 'text' => 'maybe']);
});

it('pages the history by 10 without a count', function () {
    foreach (range(1, 12) as $i) {
        auditRow(['changes' => ['title' => ['old' => (string) $i, 'new' => (string) ($i + 1)]]]);
    }

    DB::enableQueryLog();
    $first = historyOf($this->post)->json();
    $count = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains(strtolower($sql), 'count('));

    $second = historyOf($this->post, 'page=2')->json();

    expect($first['entries'])->toHaveCount(10)
        ->and($first['hasMore'])->toBeTrue()
        ->and($second['entries'])->toHaveCount(2)
        ->and($second['hasMore'])->toBeFalse()
        ->and($second['page'])->toBe(2)
        ->and($count)->toBeEmpty();
});

it('rejects an invalid page with 422 (AC-14)', function (string $page) {
    historyOf($this->post, "page={$page}")->assertStatus(422)->assertJsonValidationErrors('page');
})->with(['abc' => ['abc'], 'zero' => ['0'], 'negative' => ['-1'], 'array' => ['page[]=1'], 'huge' => ['99999999999']]);

it('presents actions, soft deletes and attach entries', function () {
    $tag = Tag::create(['name' => 'Новини']);
    CanSeePost::$actions = [new AuditAction('publish', fn () => null, [
        Select::make('Mode')->options(['fast' => 'Швидко']),
        Password::make('Pin'),
        Text::make('Hidden')->canSee(fn () => false),
    ], 'Publish')];

    auditRow(['event' => 'deleted', 'meta' => ['soft' => true]]);
    auditRow(['event' => 'attached', 'meta' => ['field' => 'tags', 'related_entity' => 'tags', 'related_id' => (string) $tag->id]]);
    auditRow(['event' => 'action', 'meta' => ['action' => 'gone', 'action_name' => 'Old action', 'fields' => ['mode' => 'fast'], 'record_deleted' => true]]);
    auditRow(['event' => 'action', 'meta' => ['action' => 'publish', 'action_name' => 'Опублікувати', 'fields' => ['mode' => 'fast', 'pin' => ['masked' => true], 'hidden' => 'x', 'extra' => 'y']]]);
    auditRow(['event' => 'detached', 'meta' => ['field' => 'tags']]);

    $entries = historyOf($this->post)->json('entries');

    expect($entries[0]['related'])->toBeNull()
        ->and($entries[1]['action'])->toBe(['name' => 'Опублікувати', 'fields' => [
            ['label' => 'Mode', 'value' => ['kind' => 'text', 'text' => 'Швидко']],
            ['label' => 'Pin', 'masked' => true],
        ]])
        ->and($entries[2]['action'])->toBe(['name' => 'Old action', 'fields' => []])
        ->and($entries[2]['recordDeleted'])->toBeTrue()
        ->and($entries[3]['related'])->toBe(['entity' => 'tags', 'entityLabel' => TagEntity::label(), 'id' => (string) $tag->id, 'title' => null, 'url' => null])
        ->and($entries[4]['soft'])->toBeTrue()
        ->and($entries[4]['event'])->toBe('deleted');
});

it('resolves the attached record title when the panel is visible', function () {
    $tag = Tag::create(['name' => 'Новини']);
    Larsadmin::flushState();
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([TaggedPost::class, TagEntity::class]);
    $post = Post::create(['title' => 'Hello', 'status' => 'draft']);

    auditRow([
        'event' => 'attached',
        'entity' => 'posts',
        'auditable_type' => Post::class,
        'auditable_id' => (string) $post->id,
        'actor_type' => null,
        'actor_id' => null,
        'actor_label' => null,
        'meta' => ['field' => 'tags', 'related_entity' => 'tags', 'related_id' => (string) $tag->id],
    ]);

    $entry = $this->getJson("/admin/api/entities/posts/{$post->id}/audit")->json('entries.0');

    expect($entry['related'])->toBe(['entity' => 'tags', 'entityLabel' => TagEntity::label(), 'id' => (string) $tag->id, 'title' => 'Новини', 'url' => "/admin/entities/tags/{$tag->id}"])
        ->and($entry['actor'])->toBeNull();
});

it('shows only entries of entities of the record model the viewer may list on the record history', function () {
    auditRow(['entity' => 'unaudited-tags']);
    auditRow(['entity' => 'not-registered']);
    auditRow();

    expect(historyOf($this->post)->json('entries'))->toHaveCount(1);
});

it('answers 404 on the record history of an entity that opts out', function () {
    auditRow();
    CanSeePost::$auditable = false;

    historyOf($this->post)->assertNotFound();
});

it('caps the page at 1000', function () {
    auditRow();

    historyOf($this->post, 'page=1000')->assertOk();
    historyOf($this->post, 'page=1001')->assertStatus(422)->assertJsonValidationErrors('page');

    $this->get('/admin/audit?page=1001')->assertInertia(fn (Assert $page) => $page->where('entries.page', 1)->has('entries.data', 1));
});

it('throttles both audit routes per user', function () {
    config(['larsadmin.audit.throttle' => 2]);

    historyOf($this->post)->assertOk();
    $this->get('/admin/audit')->assertOk();
    historyOf($this->post)->assertStatus(429);
    $this->get('/admin/audit')->assertStatus(429);
});

it('can disable the audit throttle', function () {
    config(['larsadmin.audit.throttle' => null]);

    foreach (range(1, 3) as $ignored) {
        historyOf($this->post)->assertOk();
    }
});

it('shows a field hidden from the index and detail pages only as changed (SECA-04)', function () {
    auditRow(['changes' => ['secret' => ['old' => 'form-only-old', 'new' => 'form-only-new']]]);

    $response = historyOf($this->post);

    expect($response->json('entries.0.changes'))->toBe([['attribute' => 'secret', 'label' => 'Secret', 'masked' => true]])
        ->and($response->getContent())->not->toContain('form-only');
});

it('tells apart actors with the same label in the actor filter (SECA-05)', function () {
    $twin = User::create(['name' => 'Олена Коваль', 'email' => 'twin@example.com', 'password' => 'x']);
    auditRow();
    auditRow(['actor_id' => (string) $twin->id]);

    $this->get('/admin/audit')->assertInertia(fn (Assert $page) => $page
        ->where('filters.2.options', [
            ['value' => User::class.'|'.$this->admin->id, 'label' => "Олена Коваль (#{$this->admin->id})"],
            ['value' => User::class.'|'.$twin->id, 'label' => "Олена Коваль (#{$twin->id})"],
            ['value' => 'system', 'label' => 'Система'],
        ])
        ->where('entries.data.0.actor', ['label' => 'Олена Коваль', 'id' => (string) $twin->id, 'system' => false, 'type' => User::class]));
});

it('caches the actor options per viewer', function () {
    auditRow();
    $this->get('/admin/audit');

    $other = User::create(['name' => 'Новий', 'email' => 'new@example.com', 'password' => 'x']);
    auditRow(['actor_id' => (string) $other->id, 'actor_label' => 'Новий']);

    $this->get('/admin/audit')->assertInertia(fn (Assert $page) => $page->has('filters.2.options', 2));

    Cache::flush();

    $this->get('/admin/audit')->assertInertia(fn (Assert $page) => $page->has('filters.2.options', 3));
});

// Global page (§9.3)

it('renders the global page with entries, filters and record links (§9.3)', function () {
    $other = AuditPost::create(['title' => 'Інший', 'status' => 'draft']);
    auditRow(['event' => 'created']);
    auditRow(['auditable_id' => (string) $other->id]);
    auditRow(['entity' => 'not-registered']);
    auditRow(['event' => 'action', 'auditable_type' => null, 'auditable_id' => null, 'actor_type' => null, 'actor_id' => null, 'actor_label' => null, 'meta' => ['action' => 'ping', 'action_name' => 'Ping']]);

    $this->get('/admin/audit')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Audit/Index')
            ->where('timezone', 'Europe/Kyiv')
            ->where('entries.page', 1)
            ->where('entries.perPage', 25)
            ->where('entries.prevUrl', null)
            ->where('entries.nextUrl', null)
            ->has('entries.data', 3)
            ->where('entries.data.0.event', 'action')
            ->where('entries.data.0.record', null)
            ->where('entries.data.0.actor', null)
            ->where('entries.data.1.entity', ['uriKey' => 'can-see-posts', 'label' => CanSeePost::label()])
            ->where('entries.data.1.record', ['id' => (string) $other->id, 'title' => 'Інший', 'url' => "/admin/entities/can-see-posts/{$other->id}"])
            ->where('entries.data.1.ip', '10.0.0.5')
            ->where('entries.data.1.userAgent', 'Mozilla/5.0')
            ->where('entries.data.2.record.title', 'Київ')
            ->where('query.filters', [])
            ->has('filters', 4)
            ->where('filters.0.key', 'entity')
            ->where('filters.0.component', 'select-filter')
            ->where('filters.1.key', 'event')
            ->where('filters.1.options.0', ['value' => 'created', 'label' => 'Створено'])
            ->where('filters.1.options.3', ['value' => 'action', 'label' => 'Дія'])
            ->where('filters.1.options.4.label', fn (string $label) => ! str_contains($label, ':'))
            ->where('filters.2.key', 'actor')
            ->where('filters.2.options', [
                ['value' => User::class.'|'.$this->admin->id, 'label' => 'Олена Коваль'],
                ['value' => 'system', 'label' => 'Система'],
            ])
            ->where('filters.3', ['key' => 'date', 'label' => 'Дата', 'component' => 'date-range-filter', 'value' => null, 'default' => null])
        );
});

it('hides record titles the viewer may not view on the global page', function () {
    Gate::policy(AuditPost::class, AuditPostPolicy::class);
    AuditPostPolicy::$view = false;
    auditRow();

    $this->get('/admin/audit')->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.record', ['id' => (string) $this->post->id, 'title' => null, 'url' => null]));

    AuditPostPolicy::$viewAny = false;

    $this->get('/admin/audit')->assertInertia(fn (Assert $page) => $page->has('entries.data', 0));
});

it('filters by entity, event, actor and date (§9.3)', function () {
    $tag = Tag::create(['name' => 'T']);
    auditRow(['event' => 'created', 'created_at' => '2026-10-28 10:00:00']);
    auditRow(['event' => 'updated', 'created_at' => '2026-10-30 21:30:00']); // 2026-10-30 23:30 in Kyiv
    auditRow(['event' => 'updated', 'created_at' => '2026-10-30 22:30:00', 'actor_type' => null, 'actor_id' => null, 'actor_label' => null]); // 2026-10-31 00:30 in Kyiv
    auditRow(['entity' => 'tags', 'event' => 'created', 'auditable_type' => Tag::class, 'auditable_id' => (string) $tag->id, 'created_at' => '2026-10-27 10:00:00']);

    $ids = fn (string $query) => collect($this->get("/admin/audit?{$query}")->viewData('page')['props']['entries']['data'])->pluck('event', 'id')->count();

    expect($ids('filters[entity]=tags'))->toBe(1)
        ->and($ids('filters[event]=updated'))->toBe(2)
        ->and($ids('filters[actor]=system'))->toBe(1)
        ->and($ids('filters[actor]='.urlencode(User::class.'|'.$this->admin->id)))->toBe(3)
        ->and($ids('filters[date][from]=2026-10-30&filters[date][to]=2026-10-30'))->toBe(1)
        ->and($ids('filters[date][from]=2026-10-31'))->toBe(1)
        ->and($ids('filters[date][to]=2026-10-29'))->toBe(2);

    $this->get('/admin/audit?filters[event]=updated&filters[entity]=can-see-posts')->assertInertia(fn (Assert $page) => $page
        ->where('query.filters', ['entity' => 'can-see-posts', 'event' => 'updated'])
        ->where('filters.0.value', 'can-see-posts')
        ->where('filters.1.value', 'updated'));
});

it('ignores invalid filters and pages (AC-14)', function (string $query) {
    auditRow();
    auditRow(['event' => 'created']);

    $this->get("/admin/audit?{$query}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('entries.data', 2)
        ->where('entries.page', 1));
})->with([
    'entity array' => ['filters[entity][]=x'],
    'unknown entity' => ['filters[entity]=not-registered'],
    'unknown event' => ['filters[event]=bogus'],
    'bad date' => ['filters[date][from]=abc'],
    'date string' => ['filters[date]=2026-10-30'],
    'bad actor' => ['filters[actor]=nobody'],
    'filters string' => ['filters=x'],
    'page text' => ['page=abc'],
    'page zero' => ['page=0'],
]);

it('links previous and next pages, keeping the filters', function () {
    config(['larsadmin.audit.per_page' => 2]);

    foreach (range(1, 5) as $ignored) {
        auditRow();
    }

    $this->get('/admin/audit?filters[event]=updated&page=2')->assertInertia(fn (Assert $page) => $page
        ->has('entries.data', 2)
        ->where('entries.perPage', 2)
        ->where('entries.prevUrl', '/admin/audit?filters%5Bevent%5D=updated&page=1')
        ->where('entries.nextUrl', '/admin/audit?filters%5Bevent%5D=updated&page=3'));

    $this->get('/admin/audit?page=3')->assertInertia(fn (Assert $page) => $page
        ->has('entries.data', 1)
        ->where('entries.prevUrl', '/admin/audit?page=2')
        ->where('entries.nextUrl', null));
});

it('clamps the page size to 1..100', function () {
    config(['larsadmin.audit.per_page' => 500]);
    $this->get('/admin/audit')->assertInertia(fn (Assert $page) => $page->where('entries.perPage', 100));

    config(['larsadmin.audit.per_page' => 0]);
    $this->get('/admin/audit')->assertInertia(fn (Assert $page) => $page->where('entries.perPage', 1));
});

it('labels events without their placeholders', function () {
    app()->setLocale('uk');

    expect(AuditFilters::eventLabel('attached'))->toBe('Прив\'язано')
        ->and(AuditFilters::eventLabel('action'))->toBe('Дія')
        ->and(AuditFilters::eventLabel('created'))->toBe('Створено');
});

// Props

it('shares the audit menu entry only with the gate (larsadmin.audit)', function () {
    $this->get('/admin/entities/can-see-posts')->assertInertia(fn (Assert $page) => $page->where('larsadmin.audit', ['url' => '/admin/audit']));

    Gate::define('viewLarsadminAudit', fn () => false);
    $this->get('/admin/entities/can-see-posts')->assertInertia(fn (Assert $page) => $page->where('larsadmin.audit', null));

    Gate::define('viewLarsadminAudit', fn () => true);
    config(['larsadmin.audit.enabled' => false]);
    $this->get('/admin/entities/can-see-posts')->assertInertia(fn (Assert $page) => $page->where('larsadmin.audit', null));
});

it('gives the detail page its history url only when allowed', function () {
    $show = fn () => $this->get("/admin/entities/can-see-posts/{$this->post->id}");

    $show()->assertInertia(fn (Assert $page) => $page->where('audit', ['url' => "/admin/api/entities/can-see-posts/{$this->post->id}/audit"]));

    Gate::define('viewLarsadminAudit', fn () => false);
    $show()->assertInertia(fn (Assert $page) => $page->where('audit', null));

    Gate::define('viewLarsadminAudit', fn () => true);
    CanSeePost::$auditable = false;
    $show()->assertInertia(fn (Assert $page) => $page->where('audit', null));

    CanSeePost::$auditable = true;
    config(['larsadmin.audit.enabled' => false]);
    $show()->assertInertia(fn (Assert $page) => $page->where('audit', null));
});

it('offers no routes that change entries', function () {
    $id = auditRow();

    $this->delete('/admin/audit')->assertStatus(405);
    $this->put("/admin/api/entities/can-see-posts/{$this->post->id}/audit")->assertStatus(405);
    $this->delete("/admin/api/entities/can-see-posts/{$this->post->id}/audit")->assertStatus(405);

    expect(DB::table('larsadmin_audit_entries')->where('id', $id)->exists())->toBeTrue();
});

it('records changes end to end and shows them in the history', function () {
    $this->put("/admin/entities/can-see-posts/{$this->post->id}", ['title' => 'Київ восени', 'status' => 'live'])->assertSessionHasNoErrors();

    $entry = historyOf($this->post)->json('entries.0');

    expect($entry['event'])->toBe('updated')
        ->and($entry['changes'][0])->toBe(['attribute' => 'title', 'label' => 'Title', 'old' => ['kind' => 'text', 'text' => 'Київ'], 'new' => ['kind' => 'text', 'text' => 'Київ восени']]);
});

it('runs a standalone action shown on the global page', function () {
    CanSeePost::$actions = [(new AuditAction('ping', fn (ActionFields $fields) => null))->standalone()];

    $this->post('/admin/entities/can-see-posts/actions/ping', ['resources' => []])->assertRedirect();

    $this->get('/admin/audit')->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.action', ['name' => 'Опублікувати', 'fields' => []])
        ->where('entries.data.0.record', null));
});
