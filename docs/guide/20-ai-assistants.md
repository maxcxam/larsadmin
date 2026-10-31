# 20. AI-асистенти: скіли й MCP-сервер
🇬🇧 [English](en/20-ai-assistants.md)

З версії 1.6 пакет постачає матеріали для AI-асистентів, які пишуть код застосунку (Claude Code, Cursor,
VS Code тощо): **guideline** з правилами пакета, **15 скілів** з рецептами для кожного напряму і локальний
**MCP-сервер** `larsadmin`, через який асистент шукає в цьому посібнику, бачить зареєстровані в застосунку
Entity й генерує класи тими самими генераторами, що й `php artisan larsadmin:*`.

Усе це **необов'язкове**. Панель не має нових обов'язкових залежностей і без AI-інструментів працює так само,
як у 1.5. AI не отримує доступу до даних записів: сервер віддає лише метадані коду й тексти посібника.

## Що постачається

| Що | Де в пакеті | Для чого |
| --- | --- | --- |
| Guideline | `resources/boost/guidelines/core.md` | коротко: що таке Larsadmin, обов'язкові правила, таблиця «задача → скіл», як користуватися MCP. Завантажується в кожну сесію, тому короткий (до 80 рядків) |
| Скіли | `resources/boost/skills/larsadmin-*/SKILL.md` (+ `references/*.md`) | інструкції для задач: коли брати, рецепти, шпаргалка API, правила безпеки, типові помилки, посилання на повний посібник. Асистент вантажить скіл лише тоді, коли береться за відповідну задачу |
| MCP-сервер | `php artisan larsadmin:mcp` | 8 інструментів: пошук і читання посібника, інтроспекція застосунку, генерація класів ([Інструменти](#інструменти)) |
| Команда `larsadmin:ai` | | встановлює скіли, запис MCP-сервера і guideline без Laravel Boost ([Без Boost](#встановлення-без-boost-larsadminai)) |

Скіли й guideline написані англійською. Вони посилаються на англійські посібники встановленої версії пакета
(`vendor/maxcxam/larsadmin/docs/guide/en/NN-name.md`), тож асистент читає документацію саме вашої версії.

| Скіл | Напрям | Посібник |
| --- | --- | --- |
| `larsadmin-setup` | встановлення, конфіг, оновлення, AI-інструменти | [01](01-installation.md), 20 |
| `larsadmin-entities` | Entity, запити, генератор | [02](02-entities.md) |
| `larsadmin-fields-layout` | поля, валідація, колонки, панелі й вкладки | [03](03-fields.md), [12](12-layout.md) |
| `larsadmin-relations` | `BelongsTo`, `HasMany`, `BelongsToMany` | [04](04-relations.md) |
| `larsadmin-authorization-security` | gate, policies, скоупінг, контрольний список безпеки | [05](05-authorization.md) |
| `larsadmin-filters` | вбудовані й власні фільтри | [06](06-filters.md) |
| `larsadmin-actions` | дії, поля дій, фонові дії | [07](07-actions.md), [18](18-notifications.md) |
| `larsadmin-metrics-dashboards` | метрики, картки, дашборди | [13](13-metrics.md), [14](14-dashboards.md) |
| `larsadmin-files` | `File`, `Image`, диски, `FileSafety` | [15](15-files.md) |
| `larsadmin-global-search` | глобальний пошук, Scout | [16](16-search.md) |
| `larsadmin-audit-log` | журнал аудиту | [17](17-audit-log.md) |
| `larsadmin-notifications-realtime` | дзвіночок, сповіщення, Reverb/Pusher | [18](18-notifications.md) |
| `larsadmin-ui-i18n-theming` | мови, переклади, теми, інтерфейс списків | [08](08-i18n.md), [09](09-theming.md), [11](11-ui.md) |
| `larsadmin-extending` | розширення, власні поля, картки, tools, JS SDK | [10](10-extending.md), [19](19-extensions.md) |
| `larsadmin-testing` | тести панелі й розширень | [21](21-testing.md) |

## Встановлення з Laravel Boost

[Laravel Boost](https://github.com/laravel/boost) сам знаходить guidelines і скіли пакетів, від яких прямо
залежить застосунок:

```bash
composer require laravel/boost --dev
php artisan boost:install
```

У `boost:install` оберіть `maxcxam/larsadmin` серед сторонніх пакетів. Guideline потрапляє у файл guidelines
вашого асистента (`CLAUDE.md`, `AGENTS.md` тощо), скіли копіюються в теку скілів асистента (для Claude Code
`.claude/skills/larsadmin-*`).

- **Скіли потребують Boost 2.x** (перевірено 2.4 і 2.10). Boost 1.x встановлює лише guideline.
- Boost бачить лише **прямі** залежності: `maxcxam/larsadmin` має бути в `require` вашого `composer.json`, а не
  лише залежністю іншого пакета (наприклад, доповнення).
- Після `composer update` оновіть файли командою `php artisan boost:update`.
- Guideline уже потрапляє в `CLAUDE.md` через Boost: **не** додавайте рядок імпорту (`larsadmin:ai --claude-md`).

Без інтерактивних питань (`--no-interaction`, наприклад у скрипті чи CI) `boost:install` бере агентів і пакети з
`boost.json` у корені застосунку:

```json
{"agents":["claude_code"],"packages":["maxcxam/larsadmin"]}
```

```bash
php artisan boost:install --no-interaction
```

**Вимкніть guideline `inertia-laravel/core`.** Inertia потрапляє в застосунок як залежність панелі, тож Boost
додає й свій guideline для Inertia. Він може підштовхнути асистента писати Inertia-сторінки у вашому застосунку,
хоча сторінки панелі вже є в пакеті. Файлу `config/boost.php` немає, доки його не опубліковано:

```bash
php artisan vendor:publish --tag=boost-config
```

```php
// config/boost.php
'guidelines' => [
    'exclude' => ['inertia-laravel/core'],
],
```

Потім виконайте `php artisan boost:update`. Так само `skills.exclude` вимикає окремі скіли. Імена guideline для
`exclude` показує підсумок `boost:install`.

Boost додає в `.mcp.json` лише власний сервер. Сервер `larsadmin` додайте окремо:

```bash
composer require laravel/mcp --dev
php artisan larsadmin:ai --mcp
```

## Встановлення без Boost: `larsadmin:ai`

Оберіть **один** спосіб встановлювати й оновлювати скіли: Boost (`boost:install`, `boost:update`) або
`larsadmin:ai --skills`. З Boost команда `larsadmin:ai` потрібна лише для `--mcp`.

```bash
php artisan larsadmin:ai [--skills] [--mcp] [--claude-md] [--path=.claude/skills] [--force]
```

Без опцій команда питає, що встановити (за замовчуванням обрано скіли й MCP). З `--no-interaction` і без
опцій вона виконує `--skills --mcp`. Ні Boost, ні `laravel/mcp` команді не потрібні.

| Опція | Що робить |
| --- | --- |
| `--skills` | копіює скіли `larsadmin-*` пакета в `{path}/larsadmin-*` (за замовчуванням `.claude/skills`). У кожну теку пише маркер `.larsadmin-skill` (версія пакета й хеш вмісту) |
| `--mcp` | додає в `.mcp.json` у корені застосунку сервер `larsadmin`; інші сервери й ключі файлу зберігаються. Друкує той самий фрагмент для інших клієнтів. Без `laravel/mcp` попереджає, що сервер не запуститься |
| `--claude-md` | дописує в кінець `CLAUDE.md` рядок імпорту guideline, лише якщо його ще немає; ваш текст не змінюється. Без опції команда лише друкує підказку з цим рядком |
| `--path=` | тека скілів відносно кореня застосунку |
| `--force` | перезаписує теки скілів, змінені вручну чи створені не цією командою, і наявний запис `larsadmin` у `.mcp.json` |

Запис у `.mcp.json` (формат Claude Code):

```json
{
    "mcpServers": {
        "larsadmin": {
            "command": "php",
            "args": ["artisan", "larsadmin:mcp"]
        }
    }
}
```

Рядок, який `--claude-md` додає в `CLAUDE.md`:

```text
@vendor/maxcxam/larsadmin/resources/boost/guidelines/core.md
```

Команда пише лише всередині кореня застосунку: шлях `--path`, що виходить за його межі чи проходить через
symlink (або junction у Windows), відхиляється до запису будь-якого файлу. Якщо `.mcp.json` не є JSON-об'єктом,
має дубльований ключ або число, яке PHP не збереже точно (ціле поза діапазоном, задовге дробове), файл не
змінюється: команда завершується з помилкою й друкує запис, який треба додати вручну.

### Інші MCP-клієнти

Сервер працює через stdio, тож будь-який клієнт MCP (Cursor, VS Code, Windsurf тощо) запускає його тією самою
командою: `php artisan larsadmin:mcp` у корені застосунку. Скопіюйте фрагмент, який друкує
`larsadmin:ai --mcp`, у конфіг свого клієнта. Скіли у форматі Agent Skills (`SKILL.md`) можна покласти в теку
скілів клієнта через `--path`.

## MCP-сервер

Сервер `larsadmin` побудовано на пакеті [`laravel/mcp`](https://github.com/laravel/mcp). Панель від нього не
залежить, встановіть його як dev-залежність:

```bash
composer require laravel/mcp --dev
```

Підтримуються `laravel/mcp` `^0.7.1` … `^1.0`. Йому потрібен Laravel 12.41.1+ або 13.

Транспорт лише **stdio**: клієнт сам запускає `php artisan larsadmin:mcp` як дочірній процес. HTTP-ендпоінта
немає, тож сервер недоступний мережею. Помилки команда пише в stderr, бо stdout є каналом MCP.

### Коли сервер працює

| `APP_ENV` | `LARSADMIN_MCP` не задано (`null`) | `LARSADMIN_MCP=true` | `LARSADMIN_MCP=false` |
| --- | --- | --- | --- |
| `local` | працює | працює | вимкнено |
| інше, крім `production` (`staging`, `testing`, dev-контейнер) | вимкнено | працює | вимкнено |
| `production`, `prod`, `live` (у будь-якому регістрі) | **ніколи** | **ніколи** | **ніколи** |

Production визначається і за `app()->isProduction()`, і за іменами середовища `production`, `prod`, `live` без
урахування регістру.

Ключ конфігу `larsadmin.mcp.enabled` (`env('LARSADMIN_MCP')`). Рядки `true`/`false`/`1`/`0`/`on`/`off`
розпізнаються; нерозпізнане значення вважається вимкненням.

Перевірку виконують у трьох місцях: під час реєстрації сервера, у команді `larsadmin:mcp` і на початку кожного
інструмента. Тож запуск в обхід команди (`php artisan mcp:start larsadmin`) чи конфіг, закешований в іншому
середовищі, production не відкриють. Коли перевірка не проходить, `larsadmin:mcp` завершується з кодом 1 і
пише причину, наприклад:

```text
The larsadmin MCP server runs only when APP_ENV=local; set LARSADMIN_MCP=true to run it in the [staging] environment (never in production).
```

Без `laravel/mcp` команда пише `The larsadmin MCP server needs the laravel/mcp package: composer require laravel/mcp --dev`.

## Інструменти

Сервер має 8 інструментів. Сім лише читають (`readOnly`, `idempotent`). `generate` позначено як `destructive`
(з `force: true` він перезаписує файли) і пише файли тільки на явний запит. Кожен повертає структурований JSON до 50 тис. символів; довші списки обрізаються з `"truncated": true`.
Помилки повертаються текстом без стеку викликів і без значень.

| Інструмент | Вхід | Що повертає |
| --- | --- | --- |
| `search-docs` | `query` (2–200 символів), `limit` (1–10, 5), `page` (опц.) | найкращі розділи посібника з уривком |
| `read-docs` | `page`, `section` (опц.) | зміст сторінки й Markdown розділу |
| `application-info` | | версії, налаштування панелі (за allowlist), функції, кількість реєстрацій |
| `list-entities` | | зареєстровані Entity коротко |
| `describe-entity` | `entity` (`uriKey` чи клас) | будова однієї Entity |
| `list-dashboards` | | дашборди і їхні картки |
| `list-extensions` | | розширення й tools |
| `generate` | `kind`, `name`, `options`, `write`, `force` | план файлів; з `write: true` записує їх |

### `search-docs`

Повнотекстовий пошук (BM25) по англійських посібниках установленої копії пакета (`docs/guide/en`), по розділах
`##` і `###`. Ідентифікатори шукаються як цілими (`larsadmin:entity`, `LARSADMIN_PATH`, `relatableQuery`), так і
частинами. `page` обмежує пошук однією сторінкою (`03-fields`); невідома сторінка дає помилку з переліком.

```json
{
  "version": "1.6.0",
  "results": [
    { "page": "04-relations", "title": "Relations", "section": "Which records can be selected",
      "anchor": "which-records-can-be-selected", "score": 12.4, "excerpt": "…" }
  ]
}
```

### `read-docs`

Сторінка посібника за ім'ям `NN-name` (лише зі списку наявних сторінок; шляхи від клієнта не відкриваються).
Без `section` повертає зміст і першу частину сторінки, з `section` (anchor або заголовок) розділ з підрозділами:
`{ page, title, sections: [{ title, anchor, level }], content, truncated }`. Вміст обрізається до 40 тис.
символів.

### `application-info`

Версії (`larsadmin`, `apiVersion`, `laravel`, `php`), налаштування панелі (`name`, `path`, `domainSet`, `guard`,
`locale`, `locales`, `timezone`, ім'я диска файлів, ім'я черги дій), увімкнені функції (`audit`, `notifications`,
`realtime`, `scout`, `globalSearch`), кількість Entity, дашбордів, tools і розширень, `mcp.writesAllowed` і де
лежать провайдер і каталог `app/Larsadmin`.

Конфіг читається за явним списком ключів. Ніколи не повертаються: домен (лише `domainSet: true|false`), хости,
порти й ключі realtime, з'єднання, будь-які ключі, паролі й токени.

### `list-entities` і `describe-entity`

`list-entities` повертає для кожної Entity `key`, `class`, `file`, `model`, `label`, `singularLabel`, `group`,
`inNavigation`, `globallySearchable`, `auditable` і кількість полів, дій, фільтрів і карток.

`describe-entity` (вхід `entity`: `uriKey` чи клас зареєстрованої Entity) повертає статичні властивості, чи
перевизначено `indexQuery`/`detailQuery`/`relatableQuery`, клас і файл policy, поля, блоки, дії (з їхніми
полями), фільтри, картки й попередження: немає policy, `$globallySearchable` без `$search`, фільтри без
перевизначеного `indexQuery`.

Інтроспекція бачить те, що видно **без користувача**. Прототипи полів, дій, фільтрів і карток будуються з
синтетичним запитом без автентифікації, і сервер **ніколи не**:

- виконує замикання (`canSee`, `canRun`, видимість, `readonly`): замість результату пише, що замикання є.
  У полях `contexts` і `readonly` це рядок `"closure"`, а `canSee` поля є прапорцем: `true` означає, що в поля
  є `canSee`-замикання (його не виконано, тож видимість для конкретного користувача невідома), `false` що
  замикання немає. У діях і фільтрах `canSee`/`canRun` мають значення `"closure"`, `"overridden"` (метод
  перевизначено в класі) або `"default"`;
- викликає `resolve()`, `calculate()`/`data()` карток, `Tool::props()`, `options()` фільтрів чи будь-який
  запит до моделі;
- повертає значення, `default`, `placeholder`, `help`, `withMeta()` чи `confirmText`;
- повертає опції `Select` (лише `{ source, count }`) і параметри правил, що можуть бути даними. Числові параметри
  показуються лише для правил розміру й меж (`min`, `max`, `size`, `between`, `digits`, `digits_between`,
  `decimal`, `gt`, `lt` тощо), формати повністю (`date_format`, `mimes`, `mimetypes`, `extensions`); `in`,
  `not_in`, `exists`, `unique`, `regex`, `required_if` та інші подаються лише іменем, навіть з числовими
  параметрами (вони можуть бути ID записів).

Правила валідації поля описують три ключі:

| Ключ | Що містить |
| --- | --- |
| `declaredRules` | `{ create, update }`: правила з `rules()` разом із `creationRules()` / `updateRules()` |
| `rules` | `{ create, update }`: повні правила, з якими валідує панель (спершу неявні правила типу поля, потім оголошені), або `null` |
| `rulesComputed` | `true`, якщо `rules` вдалося обчислити без коду хоста |

`rulesComputed: false` і `rules: null` бувають у полів розширень, `Select` (він будує `in` зі своїх опцій),
`File`/`Image` і полів з перевизначеним `rulesFor()`: неявні правила таких полів не виконуються. Тоді дивіться
документацію поля. Панелі зв'язків (`HasMany`, `BelongsToMany`) не валідуються: там усі три ключі `null` /
`false`. Неявне `nullable` на початку `rules` означає лише, що поле приймає порожнє значення.

Якщо `fields()`, `actions()`, `filters()` чи `cards()` вашої Entity кидає виняток без користувача (наприклад,
звертається до `$request->user()->…`), відповідь містить
`"unavailable": [{ "unavailable": "fields", "reason": "<клас винятку> without an authenticated user" }]`,
без тексту винятку. Решта опису повертається як звичайно.

### `list-dashboards` і `list-extensions`

`list-dashboards`: `key`, `class`, `file`, `name`, `inNavigation`, `isHome` (перший зареєстрований дашборд),
`canSee` і картки (`uriKey`, `class`, `type`, `width`, …). Дані метрик не обчислюються.

`list-extensions`: розширення (`name`, `path`, `version`, `script`, `style`, `translations`, `package`) і tools
(`uriKey`, `class`, `file`, `name`, `component`, `icon`). `Tool::props()` не викликається.

`package` показує, де шукати PHP-класи розширення: `{ name, namespaces, source }`, де `namespaces` є
PSR-4 namespace пакета, а `source` дорівнює `"installed.json"` (з `vendor/composer/installed.json`) або
`"composer.json"` (з `composer.json` у каталозі розширення); `null`, якщо пакет не знайдено. Ключі компонентів,
які розширення реєструє в JavaScript, не перелічуються.

### `generate`

Генерує класи тим самим кодом, що й artisan-команди (однакові stubs, однакова перевірка імен, байт у байт той
самий результат).

| Поле | Значення |
| --- | --- |
| `kind` | `entity`, `action`, `filter`, `dashboard`, `value`, `trend`, `partition`, `progress`, `field`, `card`, `data-card` (= `larsadmin:card --data`), `tool`, `extension` |
| `name` | ім'я класу StudlyCase, з `/` для вкладених namespace (`Blog/Post`); для `extension` ім'я Composer-пакета (`acme/larsadmin-rating`) |
| `options` | лише опції відповідної команди: `entity` `{ model }`; `action` `{ destructive }`; `field`, `card`, `data-card`, `tool` `{ extension }` (каталог пакета розширення); `extension` `{ path, field, card }`. Невідомий ключ дає помилку |
| `write` | `false` (за замовчуванням): лише план; `true`: записати файли |
| `force` | перезаписати наявні файли; лише разом з `write: true` |

Імена, що є зарезервованими словами PHP (`Class`, `List`, `Match` тощо), відхиляються, як і в artisan-командах.

Відповідь:

```json
{
  "kind": "action",
  "written": false,
  "files": [
    { "path": "app/Larsadmin/Actions/MarkLive.php", "status": "create", "contents": "<?php …" }
  ],
  "nextSteps": ["Return it from an entity's actions(Request $request): new \\App\\Larsadmin\\Actions\\MarkLive"]
}
```

`status`: `create` (файлу немає), `exists` (файл є і без `force` не зміниться), `overwrite` (файл був і його
перезаписано з `force: true`). `contents` повертається лише в плані (`write: false`).

Типовий порядок такий: асистент викликає `generate` без `write`, показує план, і лише після вашої згоди викликає
його знову з `write: true`. Правила запису:

- **усе або нічого**: якщо хоч один файл уже є, а `force` не задано, нічого не записується, а `nextSteps`
  пояснює, як перезаписати. Усі шляхи плану перевіряються до запису першого файлу;
- класи застосунку пишуться лише в `app/Larsadmin/**` (Vue-компоненти полів, карток і tools застосунку в
  `resources/larsadmin/js`), пакет розширення лише в каталог `path` (за замовчуванням `packages`), який має
  лежати в `packages/` чи `extensions/` кореня застосунку. Те саме для опції `extension` полів, карток і tools;
- ніколи не пише у `vendor`, `node_modules`, `storage`, `bootstrap`, `public` і теки, що починаються з крапки;
  сегменти шляху лише з крапок чи з крапкою в кінці відхиляються;
- шлях через symlink (зокрема «висячий») чи за межі кореня застосунку відхиляється;
- ніколи в production;
- жодних `composer`, `npm` чи іншого виконання коду: лише файли зі stubs. Що запустити далі, підказує
  `nextSteps`;
- кожен запис логується на рівні `info` з повідомленням `larsadmin.mcp.generate` і шляхами файлів.

Змінювати наявний код (додати поле в Entity тощо) асистент має власними засобами: `generate` лише створює нові
файли.

## Модель безпеки

- Сервер працює лише локально (stdio) і **ніколи в production** ([Коли сервер працює](#коли-сервер-працює)).
- Жоден інструмент не читає записи з бази, не виконує замикання хоста, SQL, artisan чи довільний код.
- Відповіді будуються лише з явних списків дозволених ключів; секрети конфігу й повідомлення винятків до них не
  потрапляють.
- Пише файли лише `generate`, лише з `write: true` і лише в межах застосунку.
- Скіли й guideline є звичайними текстовими файлами: вони не дають асистенту жодних прав, яких у нього не було.

Інтроспекція показує асистенту структуру адмінки, policy й правила валідації. Не вмикайте сервер
(`LARSADMIN_MCP=true`) у спільних середовищах, де до терміналу мають доступ сторонні люди.

## Оновлення скілів

Скіли мають ту саму версію, що й пакет. Після `composer update maxcxam/larsadmin` оновіть їх:

- з Boost: `php artisan boost:update`;
- без Boost: `php artisan larsadmin:ai --skills`.

`larsadmin:ai` замінює лише теки з маркером `.larsadmin-skill`, вміст яких не змінювався від встановлення. Теку,
яку ви відредагували чи створили самі, команда пропускає з попередженням (`it was changed by hand`); `--force`
замінює і її. Щоб зберегти власні правки, тримайте їх в окремому скілі з іншою назвою.

Guideline, імпортований через `CLAUDE.md` (лише без Boost), читається прямо з `vendor/` і оновлюється разом із
пакетом. З Boost guideline оновлює `boost:update`.

## Усунення проблем

| Симптом | Причина й рішення |
| --- | --- |
| Клієнт пише, що сервер `larsadmin` не запустився чи не знайдено | запустіть `php artisan larsadmin:mcp` вручну: команда пише причину в stderr. Найчастіше `APP_ENV` не `local` (задайте `LARSADMIN_MCP=true`) або немає `laravel/mcp` |
| `needs the laravel/mcp package` | `composer require laravel/mcp --dev` |
| `composer require laravel/mcp` не встановлюється на Laravel 12 | потрібен Laravel 12.41.1+: `composer update laravel/framework` |
| `LARSADMIN_MCP` змінено, але нічого не змінилось | конфіг закешовано: `php artisan config:clear` |
| `never runs in production` | так задумано; працюйте з локальною копією застосунку |
| `boost:install` не пропонує `maxcxam/larsadmin` або не ставить скіли | пакет не в `require` застосунку, або Boost 1.x (скіли з 2.x) |
| `larsadmin:ai` пише `Skipped … changed by hand` | тека скілу змінена вручну: `--force` замінить її |
| `larsadmin:ai` пише `Skipped … it was installed by Laravel Boost` | скіли встановив Boost: оновлюйте їх `php artisan boost:update` або передайте `--force`, щоб далі керувати ними через `larsadmin:ai` |
| `larsadmin:ai` пише `Skipped … not created by larsadmin:ai` | теку створено не цією командою (вручну чи іншим інструментом): `--force` замінить її |
| Асистент пише Inertia-сторінки чи Vue-компоненти в застосунку для звичайних задач панелі | Boost додав guideline `inertia-laravel/core`: виключіть його в `config/boost.php` ([з Boost](#встановлення-з-laravel-boost)) |
| `Skipped .mcp.json: the "larsadmin" server is already configured` | запис уже є; `--force` перезапише лише його |
| `The file [.mcp.json] has the duplicate key …` або `… has the number …, which cannot be kept exactly` | переписування файлу втратило б дані; додайте надрукований запис `larsadmin` вручну |
| `generate`: `must be below packages/ or extensions/` | MCP створює розширення лише в `packages/` чи `extensions/`; для іншого каталогу використайте `php artisan larsadmin:extension --path=…` |
| `describe-entity` повертає `unavailable` | `fields()`/`actions()`/`filters()`/`cards()` Entity падають без користувача; зробіть код стійким до `$request->user() === null` або ігноруйте: решта опису доступна |
| `generate` повертає `written: false` з `already exist` | файли вже є; викличте з `write: true` і `force: true`, якщо справді хочете перезаписати |
| `would be generated outside app/Larsadmin` | `name` починається з кореневого namespace (`App/Models/…`); задайте ім'я відносно `app/Larsadmin` (`Post`, `Blog/Post`) |

Далі: [Тестування](21-testing.md).
