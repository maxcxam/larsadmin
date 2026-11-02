# 05. Авторизація
🇬🇧 [English](en/05-authorization.md) · 🌐 [larsadmin.dev](https://larsadmin.dev/uk/docs/authorization)

Доступ до панелі й до даних перевіряється на двох рівнях:

1. **Хто може увійти в панель**: gate `viewLarsadmin`.
2. **Що може робити користувач з моделями**: політики Laravel, плюс обмеження видимих рядків.

## Доступ до панелі

Усі маршрути за екраном входу проходять middleware `Authenticate` (користувач залогінений на guard
панелі) і `Authorize` (виконується перевірка нижче). Інакше відповідь 403 (для JSON-запитів без
`X-Inertia` це JSON `{"message": "..."}`).

Відповіді 401 (не залогінений) і 403 для JSON-запитів містять перекладене повідомлення
(`larsadmin::messages.unauthenticated` / `larsadmin::messages.forbidden`) мовою панелі.

`Authorize` викликає `Larsadmin::check($request)`. Провайдер застосунку
(`App\Providers\LarsadminServiceProvider`) налаштовує перевірку так:

```php
(config('larsadmin.allow_local', true) && app()->environment('local'))
    || Gate::forUser($request->user(Larsadmin::guard()))->check('viewLarsadmin')
```

### Gate `viewLarsadmin`

Базовий провайдер оголошує gate, який **закритий для всіх**. Відкрийте його, перевизначивши метод
`gate()` у `app/Providers/LarsadminServiceProvider.php`:

```php
use Illuminate\Support\Facades\Gate;
use Maxcxam\Larsadmin\LarsadminApplicationServiceProvider;

class LarsadminServiceProvider extends LarsadminApplicationServiceProvider
{
    protected function gate(): void
    {
        Gate::define('viewLarsadmin', function ($user) {
            return in_array($user->email, [
                'admin@example.com',
            ]);
        });
    }
}
```

Можна ввести будь-яку власну логіку: роль, дозвіл, колонку `is_admin`.

### `allow_local`

У `APP_ENV=local` і з `larsadmin.allow_local = true` (за замовчуванням) **gate не перевіряється**:
у панель потрапляє кожен автентифікований користувач. Це зручно для розробки (так працює демо-застосунок, на якому розробляється пакет),
але небезпечно на спільних чи публічних серверах.

```dotenv
LARSADMIN_ALLOW_LOCAL=false   # завжди вимагати gate
```

### Власна перевірка доступу

Замість gate можна зареєструвати довільне замикання, перевизначивши метод `authorization()` у
`app/Providers/LarsadminServiceProvider.php`:

```php
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Larsadmin;

protected function authorization(): void
{
    Larsadmin::auth(fn (Request $request) => $request->user()?->isAdmin() === true);
}
```

Таке замикання повністю замінює перевірку з базового провайдера (`allow_local` і gate тоді не діють).
Без провайдера застосунку діє лише `allow_local` у `local`; в інших середовищах доступу немає.

### Окремий guard

`LARSADMIN_GUARD` (або `guard` у конфігу) задає guard автентифікації панелі. Той самий guard
використовується для gate і для `request->user()`.

## Політики моделей

Entity перевіряє політику моделі (`$model`) для таких ability:

| Дія | Ability | Аргумент |
| --- | --- | --- |
| Навігація, список, доступ до Entity | `viewAny` | клас моделі |
| Кнопка й сторінка створення, `store` | `create` | клас моделі |
| Перегляд запису | `view` | запис |
| Редагування, `update` | `update` | запис |
| Видалення | `delete` | запис |

Приклад `app/Policies/TagPolicy.php`: теги можна переглядати й змінювати, але не видаляти.

```php
class TagPolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, Tag $tag): bool { return true; }
    public function create(User $user): bool { return true; }
    public function update(User $user, Tag $tag): bool { return true; }

    /** Tags may never be deleted from the panel. */
    public function delete(User $user, Tag $tag): bool { return false; }
}
```

Кнопки в інтерфейсі показуються за цими ж перевірками, але сервер завжди перевіряє їх повторно.

### Політики працюють «fail closed»

Якщо для моделі **зареєстровано політику**, рішення повністю делегується gate Laravel:

- відсутній метод політики означає **заборону**;
- хуки `before()` політики (і `Gate::before`) діють, як звично.

Це стосується й прав на `attach`/`detach` у зв'язках (див. [Зв'язки](04-relations.md)), крім того, що
для них при відсутньому методі ability береться `update`.

### Моделі без політики: `allow_without_policy`

Якщо політики немає, діє `larsadmin.allow_without_policy`:

| Значення | Результат |
| --- | --- |
| `true` (за замовчуванням) | усе дозволено |
| `false` | усе заборонено |

Для продакшну рекомендується `false` і явні політики для кожної керованої моделі.

## Обмеження рядків

Політика відповідає «чи можна взагалі», а не «які саме рядки». Рядки обмежують статичні хуки Entity
([Сутності](02-entities.md)):

```php
class OwnPost extends Entity
{
    public static string $model = \App\Models\Post::class;

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('user_id', $request->user()->getKey());
    }
}
```

`indexQuery` діє на список та панелі зв'язків. `detailQuery` (за замовчуванням дорівнює `indexQuery`)
діє на перегляд, редагування, оновлення, видалення й дії над вибраними записами. Запис поза
областю дає 404. `relatableQuery` визначає, що можна вибрати у зв'язках, і діє також при валідації.

Дії над записами додатково перевіряють `update` для кожного запису ([Дії](07-actions.md)).

## Глобальний пошук

Глобальний пошук (Ctrl/⌘+K) дотримується тих самих правил, що й список Entity:

- Entity шукається, лише якщо має `$globallySearchable = true` і користувач має `viewAny`. Інакше вона
  мовчки пропускається й не згадується у відповіді.
- Рядки беруться лише з `indexQuery()`, також коли пошук іде через Laravel Scout: рушій пропонує лише
  ключі, а записи завантажуються з бази в межах області користувача.
- Колонки полів, прихованих `canSee`, не шукаються (SQL-шлях). На Scout-шляху збіг визначає індекс
  рушія, тож поле, приховане `canSee`, але присутнє в `toSearchableArray()`, усе ще може дати збіг.
- Назва й підзаголовок не показують полів, прихованих `canSee`, і пов'язаних записів поза областю
  пов'язаної Entity.
- Кожен результат перевіряється на `view`; записи без дозволу відкидаються.
- Результати не кешуються; ендпоінт має лімітер `larsadmin-search`.

Scout-індекс не знає про `canSee`: не кладіть чутливі поля в `toSearchableArray()` або вимкніть Scout
для Entity через `usesScout(): false`. Див. [Глобальний пошук](16-search.md#авторизація).

## Журнал аудиту

Журнал аудиту має власний gate `viewLarsadminAudit`, якого пакет **не визначає**: доки застосунок його не
визначить, журнал не бачить ніхто (fail closed). `allow_local` на нього не діє.

```php
protected function gate(): void
{
    Gate::define('viewLarsadmin', fn ($user) => in_array($user->email, ['admin@example.com']));

    Gate::define('viewLarsadminAudit', fn ($user) => $user->is_admin);
}
```

- Сторінка «Журнал аудиту» показує записи лише тих Entity, на яких користувач має `viewAny`.
- Історія запису додатково вимагає, щоб запис був у межах `detailQuery()` і користувач мав на нього `view`.
- Зміни полів, прихованих від глядача через `canSee`, не показуються.
- **Сторінка «Журнал аудиту» не обмежується за рядками:** вона не перевіряє `indexQuery()` і `view` для
  кожного запису журналу, тож власник gate бачить усі записи журналу кожної Entity, на якій має
  `viewAny`. Gate це межа довіри: у multi-tenant застосунках давайте його лише тим, хто може бачити дані
  всіх орендарів.

Див. [Журнал аудиту](17-audit-log.md#хто-бачить-журнал).

## Сповіщення та фонові дії

- **Дзвіночок** показує лише сповіщення типу `larsadmin`, адресовані поточному користувачу guard-а панелі.
  Чуже або неіснуюче сповіщення відповідає 404. Окремого gate немає: досить доступу до панелі.
- **Фонову дію** обробник черги виконує від імені користувача, що її запустив, і повторює всі перевірки
  (fail closed): `viewLarsadmin`, `viewAny` на Entity, `canSee()` дії, `authorizedToRunStandalone()` або
  `authorizedToRun()` для кожного запису, `indexQuery()`/`detailQuery()`. Права, відкликані між запуском
  і виконанням, діють: записи пропускаються або запуск завершується помилкою. Перед кожним пакетом
  користувач перечитується, а доступ до панелі перевіряється знову. `session()` у обробнику це окрема
  порожня сесія запуску, тож скоупінг, що спирається на сесію, не бачить даних інших запусків.
- **Запуски** бачить і скасовує лише їхній власник. Адміністратори чужих запусків не бачать; файл
  `ActionResponse::file()` завантажує лише власник.
- **Realtime-канали** панелі (`private-larsa_inbox.*`) авторизує лише `POST {path}/broadcasting/auth`
  панелі; `/broadcasting/auth` застосунку їх не авторизує.

Див. [Сповіщення та фонові дії](18-notifications.md#безпека).

## Контрольний список

- Закрийте gate `viewLarsadmin` на реальні умови, вимкніть `allow_local` поза розробкою.
- Створіть політики для керованих моделей і розгляньте `allow_without_policy => false`.
- Додайте `indexQuery`, якщо користувач бачить лише частину рядків.
- Для Entity з `$globallySearchable` і Laravel Scout перевірте, що `toSearchableArray()` не містить
  полів, прихованих `canSee`.
- Якщо журнал аудиту ввімкнено, дайте gate `viewLarsadminAudit` лише тим, кому можна бачити історію всіх
  записів доступних їм Entity.
- Для фонових дій перевірте, що `canSee()`, `canRun()`, `fields()`, політики й `indexQuery()` не залежать
  від сесії чи заголовків запиту: в обробнику черги сесія порожня, а заголовків немає. Скоупінг за
  орендарем із сесії в черзі не спрацює (поверне порожнє значення).
- Не використовуйте префікс `larsa_` (`LARSADMIN_CHANNEL_PREFIX`) для broadcast-каналів застосунку.
- Не запускайте публічні сервери з `APP_ENV=local`.
