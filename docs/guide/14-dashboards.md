# 14. Дашборди
🇬🇧 [English](en/14-dashboards.md)

Дашборд це сторінка з картками [метрик](13-metrics.md). Перший дашборд, який може бачити
користувач, відкривається на головній сторінці панелі (`/admin`). Решта мають власні адреси
`/admin/dashboards/{uriKey}` і пункти в бічному меню.

## Генератор

```bash
php artisan larsadmin:dashboard Content
```

Клас створюється як `App\Larsadmin\Dashboards\Content`. `--force` перезаписує існуючий клас.
Класи дашбордів у `app/Larsadmin` не реєструються як Entity, і автоматично вони теж не
підключаються: дашборд треба додати в `dashboards()` (див. нижче).

## Клас дашборда

```php
namespace App\Larsadmin\Dashboards;

use App\Larsadmin\Metrics\NewPosts;
use App\Larsadmin\Metrics\PostsByStatus;
use App\Larsadmin\Metrics\PostsPerDay;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Dashboards\Dashboard;

class Content extends Dashboard
{
    public function name(): string
    {
        return 'Аналітика контенту';
    }

    public function cards(Request $request): array
    {
        return [
            NewPosts::make()->help('Дописи, створені за період.'),
            PostsByStatus::make(),
            PostsPerDay::make()->width('full'),
        ];
    }
}
```

| Член класу | За замовчуванням | Опис |
| --- | --- | --- |
| `cards(Request): array` | обов'язковий | картки в порядку показу |
| `name()` | назва класу в Title Case через `__()` | назва в меню й заголовок сторінки |
| `uriKey()` | kebab-case назви класу | сегмент URL: `/admin/dashboards/content` |
| `$displayInNavigation` | `true` | показувати в бічному меню. `false` лишає дашборд доступним за адресою |
| `canSee(Closure)` | | `fn (Request $request): bool`, хто бачить дашборд |
| `make(...$args)` | | створює екземпляр |

Розміри карток і підказки задаються на самих картках: `width()`, `help()`, `canSee()`
([Метрики](13-metrics.md#картка)).

## Реєстрація

Дашборди повертає метод `dashboards()` провайдера застосунку `App\Providers\LarsadminServiceProvider`
(його створює `larsadmin:install`). Порядок у масиві це порядок у меню.

```php
use App\Larsadmin\Dashboards\Content;
use App\Larsadmin\Dashboards\Overview;

protected function dashboards(): array
{
    return [
        Overview::class,                // клас або екземпляр
        Content::make()->canSee(fn (Request $request) => $request->user()->can('viewAnalytics')),
    ];
}
```

За замовчуванням там стоїть `new Main`: вбудований дашборд `Maxcxam\Larsadmin\Dashboards\Main`
з назвою «Головна» (`uriKey` `main`) і без карток. Можна також викликати
`Larsadmin::dashboards([...])` напряму: виклик замінює раніше зареєстровані дашборди. Порожній
список означає один `Main`.

Елементи масиву: екземпляр `Dashboard` або назва його класу (створюється через `make()`). Будь-що
інше, а також два дашборди з однаковим `uriKey()` кидають `InvalidArgumentException`. Так само
дві картки з однаковим `uriKey()` в одному дашборді чи Entity кидають `InvalidArgumentException`.

## Головна сторінка

- `/admin` показує **перший зареєстрований дашборд, який користувач може бачити** (`canSee`).
- Якщо в дашборда немає видимих карток (як у `Main`), сторінка показує список Entity, як до появи
  дашбордів.
- Якщо жоден дашборд не видимий, `/admin` теж показує список Entity.

## Бічне меню

- Видимий **один** дашборд: у меню один пункт з його назвою, що веде на `/admin`.
- Видимих **кілька**: меню показує групу «Дашборди» з усіма видимими дашбордами, у яких
  `$displayInNavigation = true`. Пункт домашнього дашборда веде на `/admin`, решта на
  `/admin/dashboards/{uriKey}`.

## Доступ

| Перевірка | Наслідок |
| --- | --- |
| доступ до панелі (gate `viewLarsadmin`, [Авторизація](05-authorization.md)) | без нього недоступні всі сторінки й ендпоінти дашбордів |
| `canSee()` дашборда | невидимий дашборд відповідає 404 і на сторінці, і на ендпоінтах його метрик |
| `canSee()` картки | невидима картка не потрапляє на сторінку, її ендпоінт відповідає 404 |
| джерело метрики | Entity-джерело потребує `viewAny` і обмежене `indexQuery()`; клас моделі чи builder **не обмежені** ([Метрики](13-metrics.md#джерело-даних-і-безпека)) |

`canSee()` дашборда не перевіряє доступ до даних карток. Захищайте чутливі числа на рівні
метрики: Entity-джерелом або `canSee()` картки.

## Картки над списком Entity

`Entity::cards()` показує метрики над таблицею списку цієї Entity:

```php
// app/Larsadmin/Post.php
public function cards(Request $request): array
{
    return [
        NewPosts::make(),
        PostsByStatus::make(),
        MonthlyPublishingGoal::make(),
    ];
}
```

- Картки показуються лише на сторінці списку, не на сторінці перегляду запису.
- Дані йдуть з `GET {path}/api/entities/{entity}/metrics/{metric}`. Без `viewAny` для Entity
  ендпоінт відповідає 403, невидима картка 404.
- Картки не обмежуються поточними фільтрами й пошуком списку: метрика рахує своє джерело.
- Ті самі класи метрик можна використовувати й на дашбордах. Кеш (`cacheFor()`) ведеться окремо
  для кожного власника картки.

## Часовий пояс

Періоди всіх метрик рахуються в часовому поясі панелі: `Larsadmin::timezoneUsing()`, далі
`larsadmin.timezone`, далі `app.timezone`. Див. [Метрики](13-metrics.md#часовий-пояс).

Далі: [Розширення](10-extending.md).
