# 21. Тестування
🇬🇧 [English](en/21-testing.md) · 🌐 [larsadmin.dev](https://larsadmin.dev/uk/docs/testing)

Як тестувати панель у власному застосунку: доступ, Entity, policies, дії (зокрема фонові), фільтри, метрики,
журнал аудиту й сповіщення. Остання частина для авторів розширень: трейт `InteractsWithLarsadmin` і помічники
`kit/testing.js`.

Панель є звичайним набором маршрутів Laravel, тож тести пишуться стандартними засобами: HTTP-запити від імені
користувача, перевірки бази, `Bus::fake()`, `Notification::fake()`. Приклади нижче на [Pest](https://pestphp.com);
з PHPUnit усе так само.

Приклади спираються на Entity `App\Larsadmin\Post` (модель `App\Models\Post`, поля `title` і `status`), policy
`App\Policies\PostPolicy`, дію `MarkLive`, фільтр `StatusFilter` і метрику `NewPosts` з попередніх розділів.

## Налаштування

`tests/Pest.php` застосунку Laravel уже підходить; потрібна лише база, що очищується між тестами:

```php
pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');
```

- **Міграції.** `RefreshDatabase` виконує міграції застосунку, зокрема опубліковані `larsadmin:install`
  (`larsadmin-migrations`: журнал аудиту й запуски фонових дій). Для сповіщень і фонових дій потрібна ще
  таблиця `notifications` (`php artisan make:notifications-table`).
- **Gate панелі.** У тестах `APP_ENV=testing`, тож `allow_local` не діє і панель перевіряє gate
  `viewLarsadmin` з вашого `LarsadminServiceProvider`. Створюйте користувача, якого пускає справжній gate, або
  перевизначте gate у тесті (на наступний тест це не впливає: Laravel створює застосунок заново, і провайдер
  визначає gate знову).
- **Асети й Node не потрібні.** Сторінки панелі рендеряться й без опублікованих `public/vendor/larsadmin`.
- **Статичний стан.** Реєстрації панелі (`Larsadmin::entities()`, `Larsadmin::auth()`, розширення, tools)
  статичні. Провайдер реєструє свої знову під час кожного boot застосунку, але те, що тест додає сам
  (ще одну Entity, розширення, tool), лишається на наступні тести. Скидайте це `Larsadmin::flushState()` в
  `afterEach()`: наступний тест отримає реєстрації провайдера заново.

Спільна заготовка для прикладів:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    Gate::define('viewLarsadmin', fn (User $user) => $user->is_admin);

    $this->admin = User::factory()->create(['is_admin' => true]);
});
```

## Доступ до панелі

```php
it('sends guests to the login page', function () {
    $this->get('/admin')->assertRedirect(route('larsadmin.login'));
});

it('lets admins in and keeps everyone else out', function () {
    $this->actingAs($this->admin)->get('/admin')->assertOk();

    $this->actingAs(User::factory()->create(['is_admin' => false]))->get('/admin')->assertForbidden();
});
```

Шлях панелі береться з `larsadmin.path` (за замовчуванням `admin`). Імена маршрутів мають префікс
`larsadmin.`, тож у тестах зручно писати `route('larsadmin.entities.index', 'posts')`.

## Entity: створення, зміна, видалення

Маршрути Entity (усі під `{path}`, імена з префіксом `larsadmin.`):

| Метод | URI | Ім'я | Результат |
| --- | --- | --- | --- |
| GET | `entities/{entity}` | `entities.index` | список; 403 без `viewAny` |
| GET | `entities/{entity}/create` | `entities.create` | форма створення; 403 без `create` |
| POST | `entities/{entity}` | `entities.store` | редирект на detail нового запису; помилки валідації в сесії |
| GET | `entities/{entity}/{id}` | `entities.show` | detail; 404 поза `detailQuery` |
| GET | `entities/{entity}/{id}/edit` | `entities.edit` | форма редагування |
| PUT | `entities/{entity}/{id}` | `entities.update` | редирект на detail |
| DELETE | `entities/{entity}/{id}` | `entities.destroy` | редирект на список |
| POST | `entities/{entity}/actions/{action}` | `entities.actions.run` | [дія](#дії) |

`{entity}` є `uriKey()` Entity (за замовчуванням ім'я класу в kebab-case у множині: `Post` дає `posts`). Тіло
запиту містить значення полів за їхніми атрибутами, як їх надсилає форма.

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

Тести через HTTP перевіряють усе разом: правила полів, `readonly`, `canSee`, policy, скоупінг, хуки моделі. Самі
props сторінок Inertia не є стабільним API панелі; перевіряйте статуси, редиректи, помилки валідації й стан
бази.

Якщо перевіряєте компонент сторінки через `assertInertia()`, передайте `false` другим аргументом
`component()`. За замовчуванням `inertia.testing.ensure_pages_exist` увімкнено, і Inertia шукає файл сторінки
серед Vue-сторінок **вашого** застосунку. Сторінки панелі лежать у пакеті, тож без `false` перевірка падає з
`Inertia page component file [Entities/Index] does not exist`.

```php
use Inertia\Testing\AssertableInertia as Assert;

it('opens the posts list', function () {
    $this->actingAs($this->admin)
        ->get('/admin/entities/posts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Entities/Index', false));
});
```

Сторінки панелі: `Entities/Index`, `Entities/Create`, `Entities/Detail`, `Entities/Edit`, `Dashboard`.

## Policies і скоупінг

Policy є звичайною policy Laravel: її методи можна тестувати напряму, а через HTTP перевіряти, що панель їх
застосовує.

```php
use App\Models\Post;

it('forbids deleting a published post', function () {
    $post = Post::factory()->create(['status' => 'live']);

    expect($this->admin->can('delete', $post))->toBeFalse();

    $this->actingAs($this->admin)->delete("/admin/entities/posts/{$post->id}")->assertForbidden();

    expect($post->fresh())->not->toBeNull();
});

// indexQuery() Entity лишає тільки записи поточного користувача (Авторизація: скоупінг рядків)
it('hides other authors\' posts', function () {
    $foreign = Post::factory()->create(['user_id' => User::factory()]);

    $this->actingAs($this->admin)->get("/admin/entities/posts/{$foreign->id}")->assertNotFound();
});
```

| Перевірка | Відповідь панелі |
| --- | --- |
| немає доступу до панелі (`viewLarsadmin`) | 403 (гість: редирект на вхід) |
| немає `viewAny` | 403 на список, дії й метрики Entity |
| немає `view`, `create`, `update`, `delete` | 403 |
| запис поза `detailQuery` (`indexQuery`) | 404 |

Модель **без** policy регулює `larsadmin.allow_without_policy` (за замовчуванням `true`: дозволено все). Якщо
покладаєтеся на fail closed, перевірте в тестах, що policy зареєстрована: `expect(Gate::getPolicyFor(Post::class))->not->toBeNull()`
([Авторизація](05-authorization.md#моделі-без-політики-allow_without_policy)).

## Дії

### Через HTTP

Дія запускається `POST {path}/entities/{entity}/actions/{action}` з полем `resources` (список ID або `all`) і
значеннями полів дії ([Як виконується дія](07-actions.md#як-виконується-дія)). `{action}` є `uriKey()` дії
(`MarkLive` дає `mark-live`).

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

- Записи, які не проходять `authorizedToRun()` (за замовчуванням `update` з policy), пропускаються; якщо не
  пройшов жоден, відповідь 403. Прихована `canSee()` дія дає 404.
- `resources: 'all'` застосовує `indexQuery`, фільтри й пошук і має ліміт `actions.max_all`; більше записів
  дає помилку валідації `resources`.

### `handle()` напряму

Логіку дії можна перевірити без HTTP: `ActionFields` є `Illuminate\Support\Fluent`.

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

Такий тест не перевіряє авторизацію, валідацію полів, чанки й транзакції; для них потрібен HTTP-тест.

### Фонові дії

Дія з `$queued` іде в чергу лише з увімкненими сповіщеннями (`larsadmin.notifications.enabled`), інакше вона
виконується синхронно. Потрібні таблиці `larsadmin_action_runs` і `notifications`
([Сповіщення та фонові дії](18-notifications.md#фонові-дії)).

Що дія потрапила в чергу:

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

Що дія відпрацювала до кінця: з драйвером `sync` запуск виконується в тому самому запиті, від імені того самого
користувача, з усіма перевірками воркера ([Драйвер `sync`](18-notifications.md#драйвер-sync)).

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

Статуси запуску: `ActionRun::QUEUED`, `RUNNING`, `COMPLETED`, `FAILED`, `CANCELLED`. Поле `payload` (зашифровані
вибір і значення полів) стирається, коли запуск завершено. Значення полів фонової дії мають бути скалярами,
масивами, датами чи enum: інше дає `LogicException` ще до постановки в чергу.

## Фільтри

Фільтр можна перевірити напряму: `normalize()` перетворює сире значення з запиту, `apply()` звужує запит.

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

`normalize()` за замовчуванням приймає лише ключі з `options()`; `null` означає «фільтр не активний». Разом зі
скоупінгом фільтри перевіряються через дію з `resources: 'all'` ([приклад вище](#через-http)). Фільтр не є межею
безпеки: обмеження доступу тестуйте на `indexQuery`, а не на фільтрах.

## Метрики й дашборди

Картки вантажать дані з ендпоінтів `GET {path}/api/dashboards/{dashboard}/metrics/{metric}?range=` і
`GET {path}/api/entities/{entity}/metrics/{metric}?range=`. Формат відповіді стабільний
([Метрики](13-metrics.md#формат-відповіді-ендпоінта)).

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

- Дашборд має бути зареєстрований у `dashboards()` провайдера, а картка повернута з його `cards()`; інакше 404.
  Невидимі (`canSee`) дашборд чи картка теж дають 404.
- Періоди рахуються від поточного моменту в `larsadmin.timezone`: фіксуйте час `travelTo()`.
- Метрики з `cacheFor()` кешуються; між тестами кеш очищується, якщо в `phpunit.xml` `CACHE_STORE=array`
  (стандарт Laravel).

## Журнал аудиту й сповіщення

### Журнал аудиту

Увімкніть журнал у тесті й перевірте записи моделі `Maxcxam\Larsadmin\Audit\AuditEntry`
([Журнал аудиту](17-audit-log.md)):

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

Поля з `->sensitive()` зберігаються як `['masked' => true]`. Запис журналу не можна змінити чи видалити через
Eloquent (`LogicException`). Подію `Maxcxam\Larsadmin\Events\AuditRecorded` можна перехопити `Event::fake()`.

### Сповіщення

`LarsadminNotification` є звичайним сповіщенням Laravel, тож працює `Notification::fake()`:

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

`toArray()` повертає `title`, `body`, `level`, `icon`, `url`, `urlLabel`, `run`.

## Розширення

Скелет пакета розширення (`larsadmin:extension`) уже має `tests/TestCase.php` на Orchestra Testbench,
`tests/Pest.php` і приклади тестів поля, картки й HTTP ([Розширення: тестування](19-extensions.md#тестування)).

### `InteractsWithLarsadmin`

Трейт `Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin` працює і в застосунку, і в пакеті розширення (Pest і
PHPUnit) та використовує лише публічний API:

```php
pest()->extend(Tests\TestCase::class)
    ->use(Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin::class)
    ->in('Feature');
```

| Метод | Що робить |
| --- | --- |
| `serializeField(Field $field, ?Model $record = null, string $context = Field::DETAIL, ?string $entity = null, array $abilities = []): array` | серіалізує поле, як панель для сторінки; з `$entity` (клас чи `uriKey`) спершу викликає `bindTo()`; `$abilities` (з 1.6) задає права на запис |
| `fillField(Field $field, Model $record, array $input, array $files = [], string $context = Field::UPDATE): Model` | валідує ввід правилами поля, викликає `fillInto()` з журналом файлів, зберігає й виконує відкладене замикання в транзакції |
| `cardData(DataCard $card, array $query = [], ?Authenticatable $user = null, string $owner = 'dashboard:test'): mixed` | `canSee()` (`AuthorizationException`), `rules()` (`ValidationException`), `data()` |
| `assertExtensionRegistered(string $name)` | розширення зареєстроване |
| `extensionAssetResponse(string $name, string $file): TestResponse` | `GET` версійованого URL файлу від імені поточного користувача |

### Права запису: `$abilities`

`Field::authorizedTo('view' | 'update' | 'delete')` (з 1.6) відповідає правами, які панель уже обчислила для
рядка. У тесті їх задає `$abilities`: відсутні права дорівнюють `false`, інші ключі дають помилку. Без запису й
у контексті `create` права ігноруються, і `authorizedTo()` завжди `false`.

```php
use Maxcxam\Larsadmin\Fields\Field;

// Поле додає 'canEdit' => $this->authorizedTo('update') у jsonSerialize()
it('offers inline editing only with the update right', function () {
    $post = new Post(['rating' => 4]);
    $field = Rating::make('Рейтинг', 'rating');

    expect($this->serializeField($field, $post, Field::INDEX, abilities: ['update' => true])['canEdit'])->toBeTrue()
        ->and($this->serializeField($field, $post, Field::INDEX)['canEdit'])->toBeFalse()
        ->and($this->serializeField($field, null, Field::CREATE, abilities: ['update' => true])['canEdit'])->toBeFalse();
});
```

### Поле розширення в дії

З 1.6 поля розширень працюють у модальних вікнах дій. Дія отримує значення `Field::actionValue(Request)`:
за замовчуванням це значення, яке `fill()` пише в атрибут. Його можна перевірити напряму або через HTTP-запуск
дії з цим полем:

```php
use Illuminate\Http\Request;

it('hands the action the chosen rating', function () {
    $request = Request::create('/', 'POST', ['rating' => '4']);

    expect(Rating::make('Рейтинг', 'rating')->actionValue($request))->toBe(4);
});
```

Поля з файлами (`File`, `Image`, `$multipart`) у діях не підтримуються: дія з таким полем у `fields()` кидає
`LogicException`. У фоновій дії значення має бути скаляром, масивом, датою чи enum.

### vitest: `mountField` у дії

`mountField()` з `kit/testing.js` монтує компонент форми з контрактом панелі
([vitest](19-extensions.md#vitest-vue)). З опцією `action` (з 1.6) поле монтується як у модальному вікні дії:
`field.inAction` дорівнює `true`, `context` за замовчуванням `'create'`, проп `action` дорівнює переданому
`{ uriKey, entity }`, `resourceId` дорівнює `null`, якщо не задано інше.

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

- Тести застосунку з панеллю не потребують Node, npm чи опублікованих асетів: достатньо `composer install` і
  `php artisan test` (або `vendor/bin/pest`).
- SQLite у пам'яті (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` у `phpunit.xml`) підходить для більшості
  тестів. Метрики з трендами по колонках `DATE` на SQLite зсуваються на день: використовуйте `DATETIME`
  ([Метрики](13-metrics.md#відомі-обмеження)).
- `QUEUE_CONNECTION=sync` (стандарт `phpunit.xml`) виконує фонові дії в тому самому запиті; для перевірки
  постановки в чергу використовуйте `Bus::fake()`.
- Журнал аудиту й сповіщення вимкнено за замовчуванням: вмикайте їх у конкретних тестах через `config()`, а не
  в `phpunit.xml`, щоб інші тести не залежали від їхніх таблиць.
- MCP-сервер у тестах не реєструється (`APP_ENV=testing` без `LARSADMIN_MCP=true`), тож `laravel/mcp` у CI
  не заважає.
- Пакет розширення: `composer test` (Pest + Testbench), `npm test` (vitest), `npm run build` і перевірка
  публічного API `node vendor/maxcxam/larsadmin/kit/check-public-api.mjs .`
  ([Перевірка публічного API](19-extensions.md#перевірка-публічного-api)).

Далі: [Встановлення](01-installation.md).
