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
4. публікує міграції пакета (`larsadmin-migrations`) у `database/migrations`: журналу аудиту й запусків
   фонових дій;
5. копіює `lang/uk/validation.php`, **лише якщо такого файлу ще немає** (існуючий не змінюється).

Міграцію не виконано: журнал аудиту вимкнено за замовчуванням. Щоб увімкнути його, виконайте
`php artisan migrate`, визначте gate `viewLarsadminAudit` і задайте `LARSADMIN_AUDIT=true`
(див. [Журнал аудиту](17-audit-log.md#швидкий-старт)).

Сповіщення й фонові дії теж вимкнено. Щоб увімкнути їх, створіть таблицю `notifications`
(`php artisan make:notifications-table`), виконайте `php artisan migrate`, задайте
`LARSADMIN_NOTIFICATIONS=true`, запустіть обробник черги й заплануйте `larsadmin:prune-notifications`
(див. [Сповіщення та фонові дії](18-notifications.md#швидкий-старт)).

Після цього панель доступна за адресою `/admin` (див. ключ `path` нижче).

### AI-асистенти (необов'язково)

Пакет постачає guideline, 15 скілів і локальний MCP-сервер для AI-асистентів (Claude Code, Cursor тощо).
З Laravel Boost 2.x вони встановлюються через `php artisan boost:install`, без нього командою
`php artisan larsadmin:ai`. MCP-серверу потрібен `composer require laravel/mcp --dev`; він працює лише
локально й ніколи в production. Див. [AI-асистенти](20-ai-assistants.md).

## Оновлення пакета

```bash
composer update maxcxam/larsadmin
php artisan larsadmin:publish
```

`larsadmin:publish` публікує конфіг (існуючий перезаписується лише з `--force`) і завжди
перезаписує асети. Асети це не лише `app.js` і `app.css`: з 1.5 `app.js` лише невеликий вхідний
модуль, а код панелі, Vue й Inertia лежать у `chunks/` (`shared-*`, `vue-*`, `inertia-*`, а також дзвіночок і
клієнт realtime, що вантажаться на вимогу), модулі для розширень у `runtime/`. Публікуйте весь каталог, а
не окремі файли.

Виправлення безпеки виходять патч-версіями останнього релізу 1.x, тож оновлюйтеся регулярно.
Про вразливості повідомляйте приватно, не в публічних issue: див. [SECURITY.md](../../SECURITY.md).

## Теги публікації

| Тег | Куди копіюється |
| --- | --- |
| `larsadmin-config` | `config/larsadmin.php` |
| `larsadmin-assets` (також `laravel-assets`) | `public/vendor/larsadmin` |
| `larsadmin-lang` | `lang/vendor/larsadmin` |
| `larsadmin-provider` | `app/Providers/LarsadminServiceProvider.php` |
| `larsadmin-migrations` | `database/migrations` (міграції таблиць журналу аудиту й запусків фонових дій) |

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
| `larsadmin:extension {name} [--path=packages] [--field=] [--card=] [--force]` | створити пакет розширення в `PATH/VENDOR/PACKAGE` ([Розширення](19-extensions.md#швидкий-старт)) |
| `larsadmin:field {name} [--extension=] [--force]` | створити власне поле: PHP-клас і Vue-компоненти форми й відображення |
| `larsadmin:card {name} [--extension=] [--data] [--force]` | створити власну картку (`Card` чи з `--data` `DataCard`) і її Vue-компонент |
| `larsadmin:tool {name} [--extension=] [--force]` | створити сторінку меню (`Tool`) і її Vue-компонент |
| `larsadmin:ai [--skills] [--mcp] [--claude-md] [--path=.claude/skills] [--force]` | встановити скіли, запис MCP-сервера в `.mcp.json` і guideline в `CLAUDE.md` без Laravel Boost ([AI-асистенти](20-ai-assistants.md#встановлення-без-boost-larsadminai)) |
| `larsadmin:mcp` | запустити MCP-сервер для AI-асистентів (stdio; потрібен `laravel/mcp`; лише не в production) ([AI-асистенти](20-ai-assistants.md#mcp-сервер)) |
| `larsadmin:prune-audit [--days=]` | видалити записи журналу аудиту, старші за `audit.retention_days` (90 днів) ([Журнал аудиту](17-audit-log.md#очищення)) |
| `larsadmin:prune-notifications [--days=]` | видалити сповіщення панелі, запуски фонових дій і їхні файли, старші за `notifications.retention_days` (30 днів) ([Сповіщення](18-notifications.md#очищення)) |

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
| `actions.queued_max_all`, `actions.queued_max_active` | | `10000`, `3` | Ліміти фонових дій: записів для «усіх знайдених» і активних запусків на користувача |
| `actions.stale_after`, `actions.queued_stale_after` | | `3600`, `86400` | Через скільки секунд запуск без «серцебиття» (без `$timeout`, плюс 60 с) або в черзі вважається завислим ([Сповіщення](18-notifications.md#завислі-запуски)) |
| `actions.queue`, `actions.queue_connection` | `LARSADMIN_QUEUE`, `LARSADMIN_QUEUE_CONNECTION` | `null` | Черга й з'єднання черги для фонових дій за замовчуванням |
| `audit.enabled` | `LARSADMIN_AUDIT` | `false` | Вмикає журнал аудиту ([Журнал аудиту](17-audit-log.md#конфігурація)) |
| `audit.connection` | `LARSADMIN_AUDIT_CONNECTION` | `null` | З'єднання БД журналу |
| `audit.*` | | `retention_days` 90, `max_value_length` 1000, `per_page` 25, `throttle` 60 | Таблиця, зберігання IP і User-Agent, очищення; див. [Журнал аудиту](17-audit-log.md#конфігурація) |
| `notifications.enabled` | `LARSADMIN_NOTIFICATIONS` | `false` | Вмикає дзвіночок і фонові дії ([Сповіщення](18-notifications.md#конфігурація)) |
| `notifications.realtime` | `LARSADMIN_REALTIME` | `auto` | Realtime через Reverb/Pusher: `auto`, `true`, `false` |
| `notifications.channel_prefix` | `LARSADMIN_CHANNEL_PREFIX` | `larsa_` | Префікс broadcast-каналів панелі |
| `notifications.*` | | `poll_interval` 60, `per_page` 15, `retention_days` 30, `throttle` 120 | Опитування, сторінка дзвіночка, очищення; див. [Сповіщення](18-notifications.md#конфігурація) |
| `extensions.source_maps` | `LARSADMIN_EXTENSION_SOURCE_MAPS` | `false` | Віддавати source maps (`*.map`) розширень; лише для налагодження ([Розширення](19-extensions.md#http-ендпоінти)) |
| `extensions.max_age` | | `31536000` | Строк кешу браузера для версійованих файлів розширень, секунд |
| `mcp.enabled` | `LARSADMIN_MCP` | `null` | MCP-сервер: `null` лише при `APP_ENV=local`, `true` також в інших середовищах, крім production, `false` вимкнено; у production не працює ніколи ([AI-асистенти](20-ai-assistants.md#коли-сервер-працює)) |

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

## Content Security Policy

Якщо застосунок задає CSP, увімкніть nonce стандартним `Vite::useCspNonce()` у middleware, що виконується
до рендера сторінок панелі: inline-скрипт теми, import map розширень, тег `app.js` і теги `modulepreload`
отримують атрибут `nonce`. Без nonce сувора CSP блокує import map, і розширення не завантажуються (панель працює). Приклад:
[Розширення: CSP і nonce](19-extensions.md#csp-і-nonce).

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

`tests/Ai` перевіряє скіли (`resources/boost/skills`): кожна сторінка посібника, команда `larsadmin:*`, ключ
конфігу, змінна `LARSADMIN_*` і публічний клас мають бути згадані в якомусь скілі, а все, що скіли згадують,
має існувати. Тож **нове чи змінене публічне API оновлює скіл у тому самому PR**. Кожна сторінка посібника
має бути і в `docs/guide/`, і в `docs/guide/en/`.
