# 21. Testing
🇺🇦 [Українська](../21-testing.md)

How to test the panel in your own application: access, entities, policies, actions (background ones
included), filters, metrics, the audit log and notifications. The last part is for extension authors: the
`InteractsWithLarsadmin` trait and the `kit/testing.js` helpers.

The panel is a regular set of Laravel routes, so tests use the standard tools: HTTP requests as a user,
database assertions, `Bus::fake()`, `Notification::fake()`. The examples below use [Pest](https://pestphp.com);
with PHPUnit everything works the same way.

The examples rely on the `App\Larsadmin\Post` entity (model `App\Models\Post`, fields `title` and `status`),
the `App\Policies\PostPolicy` policy, the `MarkLive` action, the `StatusFilter` filter and the `NewPosts`
metric from the previous chapters.

## Setup

The `tests/Pest.php` of a Laravel application already fits; all you need is a database reset between tests:

```php
pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');
```

- **Migrations.** `RefreshDatabase` runs the application's migrations, including the ones `larsadmin:install`
  published (`larsadmin-migrations`: the audit log and background action runs). Notifications and background
  actions also need the `notifications` table (`php artisan make:notifications-table`).
- **The panel gate.** Tests run with `APP_ENV=testing`, so `allow_local` does not apply and the panel checks
  the `viewLarsadmin` gate from your `LarsadminServiceProvider`. Create a user the real gate lets in, or
  redefine the gate in the test (the next test is not affected: Laravel creates the application again and
  the provider defines the gate again).
- **No assets or Node needed.** Panel pages render without a published `public/vendor/larsadmin`.
- **Static state.** The panel's registrations (`Larsadmin::entities()`, `Larsadmin::auth()`, extensions,
  tools) are static. The provider registers its own again on every application boot, but what a test adds
  itself (one more entity, an extension, a tool) stays for the following tests. Reset it with
  `Larsadmin::flushState()` in `afterEach()`: the next test gets the provider's registrations again.

A shared setup for the examples:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('viewLarsadmin', fn (User $user) => $user->is_admin);

    $this->admin = User::factory()->create(['is_admin' => true]);
});
```

## Panel access

```php
it('sends guests to the login page', function () {
    $this->get('/admin')->assertRedirect(route('larsadmin.login'));
});

it('lets admins in and keeps everyone else out', function () {
    $this->actingAs($this->admin)->get('/admin')->assertOk();

    $this->actingAs(User::factory()->create(['is_admin' => false]))->get('/admin')->assertForbidden();
});
```

The panel path comes from `larsadmin.path` (`admin` by default). Route names have the `larsadmin.` prefix, so
in tests you can write `route('larsadmin.entities.index', 'posts')`.

## Entities: create, update, delete

The entity routes (all under `{path}`, names prefixed with `larsadmin.`):

| Method | URI | Name | Result |
| --- | --- | --- | --- |
| GET | `entities/{entity}` | `entities.index` | the list; 403 without `viewAny` |
| GET | `entities/{entity}/create` | `entities.create` | the create form; 403 without `create` |
| POST | `entities/{entity}` | `entities.store` | redirect to the new record's detail page; validation errors in the session |
| GET | `entities/{entity}/{id}` | `entities.show` | the detail page; 404 outside `detailQuery` |
| GET | `entities/{entity}/{id}/edit` | `entities.edit` | the edit form |
| PUT | `entities/{entity}/{id}` | `entities.update` | redirect to the detail page |
| DELETE | `entities/{entity}/{id}` | `entities.destroy` | redirect to the list |
| POST | `entities/{entity}/actions/{action}` | `entities.actions.run` | an [action](#actions) |

`{entity}` is the entity's `uriKey()` (by default the plural kebab-case class name: `Post` gives `posts`).
The request body holds the field values under their attributes, as the form sends them.

```php
use App\Models\Post;

it('creates a post', function () {
    $this->actingAs($this->admin)
        ->post('/admin/entities/posts', ['title' => 'Hello', 'status' => 'draft'])
        ->assertRedirect('/admin/entities/posts/'.Post::sole()->id);

    expect(Post::sole()->title)->toBe('Hello');
});

it('validates the form', function () {
    $this->actingAs($this->admin)
        ->post('/admin/entities/posts', ['title' => ''])
        ->assertSessionHasErrors('title');

    expect(Post::count())->toBe(0);
});

it('updates and deletes a post', function () {
    $post = Post::factory()->create();

    $this->actingAs($this->admin)
        ->put("/admin/entities/posts/{$post->id}", ['title' => 'Changed', 'status' => 'live'])
        ->assertRedirect("/admin/entities/posts/{$post->id}");

    expect($post->fresh()->title)->toBe('Changed');

    $this->delete("/admin/entities/posts/{$post->id}")->assertRedirect('/admin/entities/posts');

    expect(Post::count())->toBe(0);
});
```

HTTP tests check everything together: field rules, `readonly`, `canSee`, the policy, scoping, model hooks.
The props of the Inertia pages themselves are not a stable panel API; assert statuses, redirects,
validation errors and the database state.

When you check the page component with `assertInertia()`, pass `false` as the second argument of
`component()`. `inertia.testing.ensure_pages_exist` is on by default, and Inertia looks for the page file
among **your** application's Vue pages. The panel's pages live in the package, so without `false` the check
fails with `Inertia page component file [Entities/Index] does not exist`.

```php
use Inertia\Testing\AssertableInertia as Assert;

it('opens the posts list', function () {
    $this->actingAs($this->admin)
        ->get('/admin/entities/posts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Entities/Index', false));
});
```

The panel's pages: `Entities/Index`, `Entities/Create`, `Entities/Detail`, `Entities/Edit`, `Dashboard`.

## Policies and scoping

A policy is a regular Laravel policy: test its methods directly, and check over HTTP that the panel applies
them.

```php
use App\Models\Post;

it('forbids deleting a published post', function () {
    $post = Post::factory()->create(['status' => 'live']);

    expect($this->admin->can('delete', $post))->toBeFalse();

    $this->actingAs($this->admin)->delete("/admin/entities/posts/{$post->id}")->assertForbidden();

    expect($post->fresh())->not->toBeNull();
});

// The entity's indexQuery() keeps only the current user's records (Authorization: scoping rows)
it('hides other authors\' posts', function () {
    $foreign = Post::factory()->create(['user_id' => User::factory()]);

    $this->actingAs($this->admin)->get("/admin/entities/posts/{$foreign->id}")->assertNotFound();
});
```

| Check | The panel's response |
| --- | --- |
| no panel access (`viewLarsadmin`) | 403 (a guest: redirect to the login page) |
| no `viewAny` | 403 for the list, the actions and the entity's metrics |
| no `view`, `create`, `update`, `delete` | 403 |
| a record outside `detailQuery` (`indexQuery`) | 404 |

A model **without** a policy is governed by `larsadmin.allow_without_policy` (`true` by default: everything is
allowed). If you rely on failing closed, assert in a test that the policy is registered:
`expect(Gate::getPolicyFor(Post::class))->not->toBeNull()`
([Authorization](05-authorization.md#models-without-a-policy-allow_without_policy)).

## Actions

### Over HTTP

An action runs with `POST {path}/entities/{entity}/actions/{action}` with the `resources` field (a list of IDs
or `all`) and the values of the action's fields ([How an action runs](07-actions.md#how-an-action-runs)).
`{action}` is the action's `uriKey()` (`MarkLive` gives `mark-live`).

```php
use App\Models\Post;

it('publishes the selected posts', function () {
    [$a, $b, $c] = Post::factory()->count(3)->create(['status' => 'draft']);

    $this->actingAs($this->admin)
        ->from('/admin/entities/posts')
        ->post('/admin/entities/posts/actions/mark-live', ['resources' => [$a->id, $b->id], 'note' => 'ok'])
        ->assertRedirect('/admin/entities/posts')
        ->assertSessionHasNoErrors();

    expect($a->fresh()->status)->toBe('live')
        ->and($c->fresh()->status)->toBe('draft');
});

it('validates the action fields', function () {
    $post = Post::factory()->create();

    $this->actingAs($this->admin)
        ->post('/admin/entities/posts/actions/mark-live', ['resources' => [$post->id], 'note' => str_repeat('x', 300)])
        ->assertSessionHasErrors('note');
});

it('runs on all matching records with the filters', function () {
    Post::factory()->count(2)->create(['status' => 'draft']);
    Post::factory()->create(['status' => 'archived']);

    $this->actingAs($this->admin)->post('/admin/entities/posts/actions/mark-live', [
        'resources' => 'all',
        'filters' => ['status-filter' => 'draft'],
    ]);

    expect(Post::where('status', 'live')->count())->toBe(2);
});
```

- Records that fail `authorizedToRun()` (the policy's `update` by default) are skipped; when none passes,
  the response is 403. An action hidden by `canSee()` gives 404.
- `resources: 'all'` applies `indexQuery`, the filters and the search, and is capped by `actions.max_all`;
  more records give a validation error on `resources`.

### `handle()` directly

An action's logic can be tested without HTTP: `ActionFields` is an `Illuminate\Support\Fluent`.

```php
use App\Larsadmin\Actions\MarkLive;
use App\Models\Post;
use Maxcxam\Larsadmin\Actions\ActionFields;

it('marks posts live', function () {
    $post = Post::factory()->create(['status' => 'draft']);

    (new MarkLive)->handle(new ActionFields(['note' => 'ok']), collect([$post]));

    expect($post->fresh()->status)->toBe('live');
});
```

Such a test does not check authorization, field validation, chunks or transactions; those need an HTTP test.

### Background actions

An action with `$queued` goes to the queue only while notifications are on
(`larsadmin.notifications.enabled`); otherwise it runs synchronously. It needs the `larsadmin_action_runs`
and `notifications` tables ([Notifications and background actions](18-notifications.md#background-actions)).

That the action was queued:

```php
use App\Models\Post;
use Illuminate\Support\Facades\Bus;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;

it('queues the export', function () {
    config(['larsadmin.notifications.enabled' => true]);
    Bus::fake();
    $post = Post::factory()->create();

    $this->actingAs($this->admin)
        ->post('/admin/entities/posts/actions/recalculate-views', ['resources' => [$post->id]])
        ->assertRedirect();

    $run = ActionRun::sole();

    expect($run->status)->toBe(ActionRun::QUEUED)
        ->and($run->action)->toBe('recalculate-views')
        ->and($run->total)->toBe(1);
});
```

That the action ran to the end: with the `sync` driver the run executes within the same request, as the same
user, with all the worker's checks ([The `sync` driver](18-notifications.md#the-sync-driver)).

```php
use Illuminate\Support\Facades\Event;
use Maxcxam\Larsadmin\Events\ActionRunFinished;

it('finishes the run and notifies the user', function () {
    config(['larsadmin.notifications.enabled' => true, 'queue.default' => 'sync']);
    Event::fake([ActionRunFinished::class]);
    $post = Post::factory()->create();

    $this->actingAs($this->admin)
        ->post('/admin/entities/posts/actions/recalculate-views', ['resources' => [$post->id]]);

    expect(ActionRun::sole()->status)->toBe(ActionRun::COMPLETED)
        ->and($this->admin->notifications()->count())->toBe(1);

    Event::assertDispatched(ActionRunFinished::class, fn (ActionRunFinished $event) => $event->run->status === ActionRun::COMPLETED);
});
```

Run statuses: `ActionRun::QUEUED`, `RUNNING`, `COMPLETED`, `FAILED`, `CANCELLED`. The `payload` column (the
encrypted selection and field values) is erased when the run finishes. The field values of a background
action must be scalars, arrays, dates or enums: anything else gives a `LogicException` before the run is
queued.

## Filters

A filter can be tested directly: `normalize()` turns the raw query value into a filter value, `apply()`
narrows the query.

```php
use App\Larsadmin\Filters\StatusFilter;
use App\Models\Post;
use Illuminate\Http\Request;

it('filters by status', function () {
    Post::factory()->create(['status' => 'draft']);
    Post::factory()->create(['status' => 'live']);

    $filter = StatusFilter::make();
    $request = Request::create('/');

    expect($filter->normalize($request, 'live'))->toBe('live')
        ->and($filter->normalize($request, 'bogus'))->toBeNull()
        ->and($filter->apply($request, Post::query(), 'live')->pluck('status')->all())->toBe(['live']);
});
```

By default `normalize()` accepts only keys of `options()`; `null` means "filter not active". Together with
scoping, filters are tested through an action with `resources: 'all'` ([the example above](#over-http)). A
filter is not a security boundary: test access restrictions on `indexQuery`, not on filters.

## Metrics and dashboards

Cards load their data from the `GET {path}/api/dashboards/{dashboard}/metrics/{metric}?range=` and
`GET {path}/api/entities/{entity}/metrics/{metric}?range=` endpoints. The response format is stable
([Metrics](13-metrics.md#endpoint-response-format)).

```php
use App\Models\Post;

it('counts new posts for the range', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));
    Post::factory()->count(2)->create();
    Post::factory()->create(['created_at' => now()->subDays(40)]);

    $this->actingAs($this->admin)
        ->getJson('/admin/api/dashboards/content/metrics/new-posts?range=30')
        ->assertOk()
        ->assertJsonPath('result.value', 2);
});

it('hides the entity metric without viewAny', function () {
    Gate::before(fn ($user, string $ability) => $ability === 'viewAny' ? false : null);

    $this->actingAs($this->admin)
        ->getJson('/admin/api/entities/posts/metrics/new-posts')
        ->assertForbidden();
});
```

- The dashboard must be registered in the provider's `dashboards()` and the card returned from its
  `cards()`; otherwise 404. An invisible (`canSee`) dashboard or card gives 404 too.
- Ranges are counted from the current moment in `larsadmin.timezone`: freeze time with `travelTo()`.
- Metrics with `cacheFor()` are cached; the cache is cleared between tests when `phpunit.xml` sets
  `CACHE_STORE=array` (the Laravel default).

## Audit log and notifications

### Audit log

Turn the log on in the test and assert entries of the `Maxcxam\Larsadmin\Audit\AuditEntry` model
([Audit log](17-audit-log.md)):

```php
use App\Models\Post;
use Maxcxam\Larsadmin\Audit\AuditEntry;

it('records the change', function () {
    config(['larsadmin.audit.enabled' => true]);
    $post = Post::factory()->create(['title' => 'Old', 'status' => 'draft']);

    $this->actingAs($this->admin)
        ->put("/admin/entities/posts/{$post->id}", ['title' => 'New', 'status' => 'draft']);

    $entry = AuditEntry::query()->sole();

    expect($entry->event)->toBe('updated')
        ->and($entry->entity)->toBe('posts')
        ->and($entry->actor_id)->toBe((string) $this->admin->id)
        ->and($entry->changes)->toBe(['title' => ['old' => 'Old', 'new' => 'New']]);
});
```

Fields with `->sensitive()` are stored as `['masked' => true]`. An audit entry cannot be changed or deleted
through Eloquent (`LogicException`). The `Maxcxam\Larsadmin\Events\AuditRecorded` event can be caught with
`Event::fake()`.

### Notifications

`LarsadminNotification` is a regular Laravel notification, so `Notification::fake()` works:

```php
use Illuminate\Support\Facades\Notification;
use Maxcxam\Larsadmin\Notifications\LarsadminNotification;

it('tells the admin the import finished', function () {
    Notification::fake();

    app(\App\Imports\ProductImport::class)->run($this->admin);

    Notification::assertSentTo($this->admin, LarsadminNotification::class,
        fn (LarsadminNotification $notification) => $notification->toArray($this->admin)['level'] === 'success');
});
```

`toArray()` returns `title`, `body`, `level`, `icon`, `url`, `urlLabel`, `run`.

## Extensions

The extension package skeleton (`larsadmin:extension`) already has a `tests/TestCase.php` on Orchestra
Testbench, `tests/Pest.php` and example tests of a field, a card and HTTP
([Extensions: testing](19-extensions.md#testing)).

### `InteractsWithLarsadmin`

The `Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin` trait works both in an application and in an extension
package (Pest and PHPUnit) and uses only the public API:

```php
pest()->extend(Tests\TestCase::class)
    ->use(Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin::class)
    ->in('Feature');
```

| Method | What it does |
| --- | --- |
| `serializeField(Field $field, ?Model $record = null, string $context = Field::DETAIL, ?string $entity = null, array $abilities = []): array` | serializes the field as the panel does for a page; with `$entity` (a class or `uriKey`) calls `bindTo()` first; `$abilities` (since 1.6) sets the record abilities |
| `fillField(Field $field, Model $record, array $input, array $files = [], string $context = Field::UPDATE): Model` | validates the input with the field's rules, calls `fillInto()` with a file journal, saves and runs the deferred closure in a transaction |
| `cardData(DataCard $card, array $query = [], ?Authenticatable $user = null, string $owner = 'dashboard:test'): mixed` | `canSee()` (`AuthorizationException`), `rules()` (`ValidationException`), `data()` |
| `assertExtensionRegistered(string $name)` | the extension is registered |
| `extensionAssetResponse(string $name, string $file): TestResponse` | `GET` of the file's versioned URL as the acting user |

### Record abilities: `$abilities`

`Field::authorizedTo('view' | 'update' | 'delete')` (since 1.6) answers with the abilities the panel already
computed for the row. In a test, `$abilities` sets them: missing abilities are `false`, other keys fail the
test. Without a record and in the `create` context the abilities are ignored and `authorizedTo()` is always
`false`.

```php
use Maxcxam\Larsadmin\Fields\Field;

// The field adds 'canEdit' => $this->authorizedTo('update') in jsonSerialize()
it('offers inline editing only with the update right', function () {
    $post = new Post(['rating' => 4]);
    $field = Rating::make('Rating', 'rating');

    expect($this->serializeField($field, $post, Field::INDEX, abilities: ['update' => true])['canEdit'])->toBeTrue()
        ->and($this->serializeField($field, $post, Field::INDEX)['canEdit'])->toBeFalse()
        ->and($this->serializeField($field, null, Field::CREATE, abilities: ['update' => true])['canEdit'])->toBeFalse();
});
```

### An extension field in an action

Since 1.6 extension fields work in action modals. The action receives the value of
`Field::actionValue(Request)`: by default the value `fill()` writes to the attribute. Test it directly or
through an HTTP run of an action with this field:

```php
use Illuminate\Http\Request;

it('hands the action the chosen rating', function () {
    $request = Request::create('/', 'POST', ['rating' => '4']);

    expect(Rating::make('Rating', 'rating')->actionValue($request))->toBe(4);
});
```

Fields with files (`File`, `Image`, `$multipart`) are not supported in actions: an action with such a field in
`fields()` throws a `LogicException`. In a background action the value must be a scalar, an array, a date or
an enum.

### vitest: `mountField` in an action

`mountField()` from `kit/testing.js` mounts a form component with the panel contract
([vitest](19-extensions.md#vitest-vue)). With the `action` option (since 1.6) the field is mounted as in an
action's modal: `field.inAction` is `true`, `context` defaults to `'create'`, the `action` prop is the given
`{ uriKey, entity }`, `resourceId` is `null` unless set.

```js
import { expect, it } from 'vitest'
import { mountField } from 'larsadmin-kit/testing.js'
import RatingInput from './RatingInput.vue'

it('works in an action modal', async () => {
    const wrapper = mountField(RatingInput, {
        field: { attribute: 'rating', value: null, max: 5 },
        action: { uriKey: 'rate-posts', entity: 'posts' },
    })

    await wrapper.findAll('input[type="radio"]')[2].setValue(true)

    expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([3])
})
```

## CI

- Application tests with the panel need no Node, npm or published assets: `composer install` and
  `php artisan test` (or `vendor/bin/pest`) are enough.
- In-memory SQLite (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` in `phpunit.xml`) fits most tests. Trend
  metrics over `DATE` columns shift by a day on SQLite: use `DATETIME`
  ([Metrics](13-metrics.md#known-limitations)).
- `QUEUE_CONNECTION=sync` (the `phpunit.xml` default) runs background actions within the same request; to
  check queuing, use `Bus::fake()`.
- The audit log and notifications are off by default: turn them on in specific tests with `config()`, not in
  `phpunit.xml`, so that other tests do not depend on their tables.
- The MCP server is not registered in tests (`APP_ENV=testing` without `LARSADMIN_MCP=true`), so
  `laravel/mcp` does not get in the way in CI.
- An extension package: `composer test` (Pest + Testbench), `npm test` (vitest), `npm run build` and the
  public API check `node vendor/maxcxam/larsadmin/kit/check-public-api.mjs .`
  ([Checking the public API](19-extensions.md#checking-the-public-api)).

Next: [Installation](01-installation.md).
