---
name: larsadmin-testing
description: "Larsadmin testing: the InteractsWithLarsadmin trait, testing the entities, policies, actions, filters and metrics of the host app, resetting panel state between tests and testing extension components with the kit. Use when writing Pest or PHPUnit tests for admin panel code in a Laravel app that uses maxcxam/larsadmin."
license: MIT
metadata:
  package: maxcxam/larsadmin
  version: "1.6"
---

# Larsadmin testing

## When to use

- Prove that an entity lists, shows, creates and updates records for the right users and hides the rest.
- Test policies, row scoping (`indexQuery()`/`detailQuery()`), actions, filters, metrics and data cards.
- Unit-test custom or extension fields (`serializeField()`, `fillField()`) and Vue components with the kit.

Panel pages are ordinary HTTP routes under `larsadmin.path` (`/admin` by default): test them with Laravel's
HTTP testing as the acting user. No browser is needed.

## Quick start

**1. Detect the runner first** and write tests in the style the app already uses:

- Pest: `vendor/bin/pest` exists, `tests/Pest.php` exists, tests use `it()`/`test()`.
- PHPUnit: no `vendor/bin/pest` (or no `tests/Pest.php`), tests are classes extending `Tests\TestCase` with
  `public function test_...(): void` methods. Run them with `php artisan test` or `vendor/bin/phpunit`.

Do not install Pest or create `tests/Pest.php` in a PHPUnit app.

**2a. PHPUnit:** use the trait in the test class (no global setup needed):

```php
namespace Tests\Feature\Larsadmin;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin;
use Tests\TestCase;

class PostEntityTest extends TestCase
{
    use InteractsWithLarsadmin;
    use RefreshDatabase;

    // A user your viewLarsadmin gate (or Larsadmin::auth() closure) allows.
    private function admin(): User
    {
        return User::factory()->create(['email' => 'admin@example.com']);
    }

    public function test_it_lists_posts_for_an_admin(): void
    {
        Post::factory()->create(['title' => 'First']);

        $this->actingAs($this->admin())
            ->get('/admin/entities/posts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Entities/Index', false)
                ->has('records.data', 1)
                ->where('records.data.0.title', 'First'));
    }
}
```

**2b. Pest:** add the trait and a helper once in `tests/Pest.php`, then write the same test with `it()`:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin;

pest()->extend(Tests\TestCase::class)->use(RefreshDatabase::class, InteractsWithLarsadmin::class)->in('Feature');

function admin(): App\Models\User
{
    return App\Models\User::factory()->create(['email' => 'admin@example.com']);
}
```

The recipes below use Pest syntax. For PHPUnit, put each body in a `test_...` method, call `$this->admin()`
instead of `admin()`, use `$this->assert...()` instead of `expect()`, and `$this->expectException()` instead of
`->throws()`; `$this->get()`, `$this->serializeField()` and the other `$this->` calls are identical.

Pass `false` as the second argument of `component()`: the panel's pages are not in your `resources/js`, and
Inertia's `ensure_pages_exist` check would fail otherwise. The entity key is `Entity::uriKey()` (the plural
kebab-cased class name, `Post` gives `posts`).

## Recipes

### Panel access in tests

The test environment is not `local`, so `allow_local` does not apply: access goes through the
`viewLarsadmin` gate in `App\Providers\LarsadminServiceProvider` (or a `Larsadmin::auth()` closure). Create
users the gate allows; assert the rest are refused:

```php
use App\Models\User;

it('refuses users outside the gate', function () {
    $this->actingAs(User::factory()->create(['email' => 'guest@example.com']))
        ->get('/admin')
        ->assertForbidden();
});
```

### Policies and row scoping

```php
use App\Models\Post;
use App\Models\User;

it('hides other teams posts', function () {
    $admin = User::factory()->create(['team_id' => 1]);
    $foreign = Post::factory()->create(['team_id' => 2]);

    expect($admin->can('update', $foreign))->toBeFalse();

    $this->actingAs($admin)->get("/admin/entities/posts/{$foreign->id}")->assertNotFound(); // outside detailQuery()
    $this->actingAs($admin)->put("/admin/entities/posts/{$foreign->id}", ['title' => 'x'])->assertNotFound();
});
```

A record outside `detailQuery()` answers 404; a denied policy ability answers 403. Cover both for each
entity that holds tenant or owner data.

### Actions

```php
use App\Models\Post;

it('publishes the selected posts', function () {
    $post = Post::factory()->create(['status' => 'draft']);

    $this->actingAs(admin())
        ->from('/admin/entities/posts')
        ->post('/admin/entities/posts/actions/publish', ['resources' => [$post->id]])
        ->assertRedirect();

    expect($post->fresh()->status)->toBe('published');
});
```

The URL is `{path}/entities/{entity}/actions/{action}` with the action's `uriKey()` (kebab-cased class name).
Send `resources` as a list of ids or `'all'` (with `filters` and `search`), plus the action's field values.
Assert that a user without `update` on a record gets it skipped, and that a hidden action (`canSee()`)
answers 404. Background (`$queued`) actions run synchronously while notifications are off, which is the
default; to test the queued path set `LARSADMIN_NOTIFICATIONS=true` in `phpunit.xml` with the `sync` queue.

### Filters

```php
use App\Models\Post;
use Inertia\Testing\AssertableInertia as Assert;

it('filters posts by status', function () {
    Post::factory()->create(['status' => 'draft']);
    Post::factory()->create(['status' => 'published']);

    $this->actingAs(admin())
        ->get('/admin/entities/posts?filters[status]=draft')
        ->assertInertia(fn (Assert $page) => $page->has('records.data', 1));
});
```

The query key is the filter's `key()`. A filter is not a security boundary: test scoping separately.

### Metrics and data cards

```php
use App\Models\Post;

it('counts new posts', function () {
    Post::factory()->count(3)->create();

    $this->actingAs(admin())
        ->getJson('/admin/api/dashboards/main/metrics/new-posts?range=30')
        ->assertOk()
        ->assertJsonPath('result.value', 3);
});
```

Dashboard cards: `{path}/api/dashboards/{dashboard}/metrics/{card}`; cards above a list:
`{path}/api/entities/{entity}/metrics/{card}` (403 without `viewAny`). A metric answers `{ range, result }`.
For a `DataCard` call `$this->cardData(TopRated::make(), ['limit' => 5], $user)`: it runs `canSee()`
(`AuthorizationException`), `rules()` (`ValidationException`) and `data()`.

### Custom fields, data cards and extensions

`serializeField()` (with 1.6 `abilities:` for `Field::authorizedTo()`), `fillField()`, `cardData()`,
`assertExtensionRegistered()` and vitest with `mountField(..., { action })` from `kit/testing.js`:
[references/fields-and-extensions.md](references/fields-and-extensions.md).

## API cheat sheet

| Tool | Use |
|---|---|
| `Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin` | trait for Pest (`->use(...)`) or PHPUnit (`use ...;`) |
| `serializeField()`, `fillField()` | field serialization and filling as the panel does |
| `cardData()` | run a `DataCard` with authorization and validation |
| `assertExtensionRegistered()`, `extensionAssetResponse()` | extension package checks |
| `Larsadmin::flushState()` | reset all static panel state (entities, dashboards, tools, extensions, callbacks) |
| `->component('Entities/Index', false)` | Inertia page assertion without the on-disk check (`Entities/Detail`, `Entities/Edit`, `Entities/Create`) |
| `GET /admin/entities/{entity}?filters[key]=v&search=...` | index with filters |
| `POST /admin/entities/{entity}/actions/{action}` | run an action |
| `GET /admin/api/dashboards/{dashboard}/metrics/{card}?range=30` | metric JSON |

## Security rules

- MUST test the negative paths for each entity: a user outside the gate (403), a record outside
  `detailQuery()` (404), a denied policy ability (403), a hidden action (404).
- MUST test with real policies; never stub `Gate::before(fn () => true)` in tests that are meant to cover
  authorization.
- MUST assert that sensitive fields (`->sensitive()`, `Password`) never appear in responses you build
  yourself (extension routes, data cards).
- NEVER define `viewLarsadmin` as `fn () => true` in the application provider to make tests pass; define it
  per test (`Gate::define()` in `setUp()`) or create allowed users.

## Pitfalls

- `Larsadmin::flushState()` also removes entities, dashboards and extensions your providers registered at
  boot. Do not call it in host tests unless you register them again afterwards.
- `component('Entities/Index')` without `false` fails while Inertia's `ensure_pages_exist` testing check is on.
- An action answers with a redirect back (set `from()`); assert the database state rather than the flash message.
- Panel config in tests comes from `config/larsadmin.php` and `phpunit.xml` env (`LARSADMIN_AUDIT`,
  `LARSADMIN_NOTIFICATIONS`); turn features on per test with `config([...])` before the request.
- Metrics on SQLite put `DATE` columns into the previous day's trend point; use `DATETIME` columns.

## Reference

- Guide: [vendor/maxcxam/larsadmin/docs/guide/en/21-testing.md](vendor/maxcxam/larsadmin/docs/guide/en/21-testing.md);
  extensions: the "Testing" section of [vendor/maxcxam/larsadmin/docs/guide/en/19-extensions.md](vendor/maxcxam/larsadmin/docs/guide/en/19-extensions.md).
- Policies and scoping: `larsadmin-authorization-security`. Actions: `larsadmin-actions`.
- MCP (when connected): `read-docs page=21-testing`, `describe-entity entity=posts` (fields, actions, filters,
  cards and policy of an entity to cover).
