# 01. Встановлення
🇬🇧 [English](en/01-installation.md)

## Вимоги

| Компонент | Версія |
| --- | --- |
| PHP | 8.3+ (CI перевіряє 8.3, 8.4, 8.5) |
| Laravel | 12 або 13 |
| Inertia (серверна частина) | `inertiajs/inertia-laravel` ^3.0 (ставиться разом із пакетом) |
| `laravel/framework` | ^12.0 або ^13.0 (обов'язкова залежність пакета) |

Фронтенд панелі поставляється зібраним (`dist/`), тож Node.js у вашому застосунку не потрібен.
Панель використовує власний клієнтський бандл і вимикає SSR Inertia для своїх маршрутів.

## Встановлення

```bash
composer require maxcxam/larsadmin
php artisan larsadmin:install
```

`larsadmin:install` виконує по черзі:

1. публікує конфіг (`larsadmin-config`) у `config/larsadmin.php`;
2. публікує асети (`larsadmin-assets`) у `public/vendor/larsadmin` з перезаписом;
3. публікує сервіс-провайдер (`larsadmin-provider`) в `app/Providers/LarsadminServiceProvider.php`
   і додає його в `bootstrap/providers.php`;
4. копіює `lang/uk/validation.php`, **лише якщо такого файлу ще немає** (існуючий не змінюється).

Після цього панель доступна за адресою `/admin` (див. ключ `path` нижче).

## Оновлення пакета

```bash
composer update maxcxam/larsadmin
php artisan larsadmin:publish
```

`larsadmin:publish` публікує конфіг (існуючий перезаписується лише з `--force`) і завжди
перезаписує асети.

## Теги публікації

| Тег | Куди копіюється |
| --- | --- |
| `larsadmin-config` | `config/larsadmin.php` |
| `larsadmin-assets` (також `laravel-assets`) | `public/vendor/larsadmin` |
| `larsadmin-lang` | `lang/vendor/larsadmin` |
| `larsadmin-provider` | `app/Providers/LarsadminServiceProvider.php` |

```bash
php artisan vendor:publish --tag=larsadmin-lang
```

Тег `larsadmin-lang` не входить у `larsadmin:install`. Публікуйте його, коли хочете змінити переклади
(див. [Локалізація](08-i18n.md)).

## Artisan-команди

| Команда | Призначення |
| --- | --- |
| `larsadmin:install` | перше встановлення |
| `larsadmin:publish [--force]` | оновити конфіг і асети |
| `larsadmin:entity {name} [--model=] [--force]` | створити Entity в `app/Larsadmin` |
| `larsadmin:action {name} [--destructive] [--force]` | створити Action в `app/Larsadmin/Actions` |
| `larsadmin:filter {name} [--force]` | створити Filter в `app/Larsadmin/Filters` |
| `larsadmin:dashboard {name} [--force]` | створити Dashboard в `app/Larsadmin/Dashboards` ([Дашборди](14-dashboards.md)) |
| `larsadmin:value {name} [--force]` | створити метрику Value в `app/Larsadmin/Metrics` ([Метрики](13-metrics.md)) |
| `larsadmin:trend {name} [--force]` | створити метрику Trend в `app/Larsadmin/Metrics` |
| `larsadmin:partition {name} [--force]` | створити метрику Partition в `app/Larsadmin/Metrics` |
| `larsadmin:progress {name} [--force]` | створити метрику Progress в `app/Larsadmin/Metrics` |

## Конфігурація (`config/larsadmin.php`)

| Ключ | Змінна середовища | За замовчуванням | Опис |
| --- | --- | --- | --- |
| `name` | `LARSADMIN_NAME` | `APP_NAME` + ` Admin` (без `APP_NAME`: `Laravel Admin`) | Назва в шапці та заголовку вкладки |
| `domain` | `LARSADMIN_DOMAIN` | `null` | Домен, на якому працює панель |
| `path` | `LARSADMIN_PATH` | `admin` | Префікс URI |
| `guard` | `LARSADMIN_GUARD` | `null` (guard за замовчуванням) | Guard автентифікації панелі |
| `locale` | `LARSADMIN_LOCALE` | `uk` | Мова панелі; `null` залишає мову застосунку |
| `locales` | | `['uk' => 'Українська', 'en' => 'English']` | Мови, між якими перемикається користувач (`код => підпис`) |
| `timezone` | `LARSADMIN_TIMEZONE` | `null` (`app.timezone`) | Часовий пояс, у якому рахуються періоди метрик ([Метрики](13-metrics.md#часовий-пояс)) |
| `metrics.cache_store` | | `null` (сховище за замовчуванням) | Сховище кешу для метрик із `cacheFor()` |
| `metrics.throttle` | | `120` | Ліміт запитів до ендпоінтів метрик на хвилину на користувача (гостя: на IP), лімітер `larsadmin-metrics`; `null` вимикає |
| `middleware` | | `['web', HandleInertiaRequests::class]` | Middleware всіх маршрутів панелі, включно зі сторінкою входу |
| `auth_middleware` | | `[Authenticate::class, Authorize::class]` | Middleware маршрутів за екраном входу |
| `allow_without_policy` | | `true` | Моделі без політики: `true` дозволяє, `false` забороняє |
| `allow_local` | `LARSADMIN_ALLOW_LOCAL` | `true` | У `APP_ENV=local` пускати в панель без gate `viewLarsadmin` |
| `actions.max_all` | | `1000` | Максимум записів для дії з вибором «усі знайдені»; більше дає помилку валідації 422 |

> **Увага.** Коли `allow_local` дорівнює `true` і `APP_ENV=local`, **кожен автентифікований
> користувач** потрапляє в панель. Не запускайте спільні чи публічні сервери з `APP_ENV=local`,
> або задайте `LARSADMIN_ALLOW_LOCAL=false`.

Приклад `.env` для продакшну:

```dotenv
LARSADMIN_NAME="Моя адмінка"
LARSADMIN_PATH=backoffice
LARSADMIN_ALLOW_LOCAL=false
```

## Вхід

Панель має власну сторінку входу (`/admin/login`) з обмеженням частоти: 5 спроб на хвилину
для пари email + IP і 30 на хвилину для одного IP.

## Кеш маршрутів

Якщо застосунок використовує `php artisan route:cache`, після зміни `path` або `domain`
перебудуйте кеш. Пакет не реєструє маршрути, коли кеш маршрутів активний.

Далі: [Entities](02-entities.md).

## Розробка пакета

У корені репозиторію пакета:

| Команда | Призначення |
| --- | --- |
| `composer test` | Pest + Testbench |
| `composer format` | Pint |
| `npm test` | фронтенд-тести (vitest) |
| `npm run i18n` | генерує `resources/js/i18n/uk.json` з `lang/uk/ui.php` |
| `npm run build` | збірка `dist/` |

Файл `resources/js/i18n/uk.json` потрібно **комітити**: після зміни `lang/uk/ui.php` запустіть
`npm run i18n`. Так само комітиться `dist/`. CI перевіряє, що `dist/` актуальний
(`git diff --exit-code dist` після збірки).
