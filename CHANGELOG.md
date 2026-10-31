# Changelog

Усі помітні зміни пакета `maxcxam/larsadmin` документуються в цьому файлі.

Формат базується на [Keep a Changelog](https://keepachangelog.com/uk/1.1.0/),
версіонування на [Semantic Versioning](https://semver.org/lang/uk/).

## [Unreleased]

## [1.6.0] - 2026-10-31

### Added

- **AI-асистенти (FR-064).** Пакет постачає 15 скілів (`resources/boost/skills/larsadmin-*`: setup, entities,
  fields-layout, relations, authorization-security, filters, actions, metrics-dashboards, files,
  global-search, audit-log, notifications-realtime, ui-i18n-theming, extending, testing) і guideline
  `resources/boost/guidelines/core.md`. З Laravel Boost 2.x їх встановлює `php artisan boost:install`
  (Boost 1.8 читає лише guidelines). Посібник: `docs/guide/20-ai-assistants.md`.
- **Команда `larsadmin:ai {--skills} {--mcp} {--claude-md} {--path=.claude/skills} {--force}`** для
  встановлення без Boost: копіює скіли (з маркером `.larsadmin-skill`; змінені вручну й чужі теки
  пропускаються без `--force`), додає сервер `larsadmin` у `.mcp.json` (наявний запис `larsadmin` замінює
  лише `--force`), один раз додає рядок імпорту guidelines у `CLAUDE.md`. Без опцій питає інтерактивно;
  з `--no-interaction` це `--skills --mcp`. Пише лише всередині `base_path()` і не через посилання.
  `.mcp.json` пишеться без екранування `/` і Unicode; файл з дубльованими ключами чи числами, які
  перезапис змінив би, команда не переписує, а показує запис для ручного додавання.
  Розпізнає Boost: не дублює guidelines у `CLAUDE.md`, якщо їх уже вставив Boost, а скіли, встановлені
  Boost, пропускає з порадою `php artisan boost:update` або `--force`.
- **Локальний MCP-сервер `larsadmin`** (stdio, `laravel/mcp`) і команда `larsadmin:mcp`, що його запускає.
  Інструменти: `search-docs` і `read-docs` (англійські посібники пакета), `application-info`,
  `list-entities`, `describe-entity`, `list-dashboards`, `list-extensions` (метадані зареєстрованих Entity,
  полів, дій, фільтрів, метрик, дашбордів, tools і розширень) та `generate` (ті самі генератори, що й
  команди `larsadmin:*`; за замовчуванням лише план з вмістом файлів, запис з `write: true`, усе або нічого).
  `list-extensions` показує Composer-пакет і PSR-4 namespace-и кожного розширення; `describe-entity` для
  полів дає `rules` (повні правила, якщо їх можна обчислити без коду хоста), `rulesComputed` і
  `declaredRules` (оголошені правила для створення й оновлення).
- **Конфіг `mcp.enabled`** (`LARSADMIN_MCP`, `null`): `null` вмикає сервер лише з `APP_ENV=local`, `true`
  у будь-якому не-production середовищі, `false` вимикає.
- **Посібники** `docs/guide/20-ai-assistants.md` і `docs/guide/21-testing.md` (тестування Entity, policy,
  дій, фільтрів, метрик і розширень) з англійськими перекладами.
- **`Maxcxam\Larsadmin\Files\FileSafety`** (стабільний фасад): `rule()`, `blockedExtension()`,
  `storedExtension()`, `sanitizeName()`, `asciiName()`, `isRasterImage()`, `response()`. Розширення, що
  зберігає чи віддає файли, користується тими самими перевірками й заголовками, що `File`/`Image`, без
  власної копії. Spike медіатеки перейшов на фасад (його копію й `FileSafetyParityTest` видалено).
- **`Field::authorizedTo(string $ability)`**: відповіді policy `view`/`update`/`delete` для запису, для
  якого поле розв'язується. Це права, які панель уже обчислила для рядка, тож нових викликів policy немає.
  `false` у контексті створення, у дії, для поля, створеного вручну, і для іншої здатності.
  `Larsadmin::findField()` повертає поле з правами для запису.
- **Поля розширень у модальних вікнах дій:** `Field::inAction()` і стабільний хук
  `Field::actionValue(Request)` (значення для `ActionFields`; за замовчуванням як у 1.5: `fill()` у тимчасову
  модель і читання `fillAttribute()`). `ActionModal` рендерить поля розширень і перевизначення через той
  самий реєстр і `ExtensionBoundary`, що й форма запису, і передає повний контракт форми: `errors`,
  `errorsByKey`, `describedBy`, `context: 'create'`, `resourceId` (ключ одного запису, інакше `null`) і
  новий prop `action: { uriKey, entity }`. Серіалізоване поле дії має ключ `inAction: true`.
- **Тестові помічники:** `InteractsWithLarsadmin::serializeField()` отримав параметр `$abilities`
  (`['view' => true, …]`) для `authorizedTo()`; `mountField()` у `kit/testing.js` приймає опцію `action`.
- `composer.json`: `suggest` `laravel/mcp` (`^0.7.1|^1.0`, Laravel 12 потребує 12.41.1+) і `laravel/boost`
  (`^2.0`); `laravel/mcp` лише в `require-dev`, панель від нього не залежить.

### Changed

- **`Larsadmin::API_VERSION` і `apiVersion` (JS): `'1.6'`.** Розширення, що використовує нове API
  (`authorizedTo()`, `inAction()`, `actionValue()`, prop `action`, `FileSafety`), декларує `requires: '^1.6'`
  і `"maxcxam/larsadmin": "^1.6"`; скелет `larsadmin:extension` генерує саме так. Розширення з `'^1.5'`
  працюють як раніше.
- Усі генератори (`larsadmin:entity`, `:action`, `:filter`, `:dashboard`, `:value`, `:trend`, `:partition`,
  `:progress`, `:field`, `:card`, `:tool`, `:extension`) працюють через спільний внутрішній `Scaffolder`,
  той самий, що й MCP-інструмент `generate`, і дають однакові файли. Генератори класів застосунку тепер, як
  і scaffold розширень, **відмовляються писати поза `base_path()` чи через символічне посилання або
  junction** на шляху.
- **Генератори (artisan і `generate`):** увесь план перевіряється до першого запису; сегменти шляху лише з
  крапок чи з крапкою в кінці відхиляються; каталог пакета розширення (`--path`, `--extension`) не може бути
  в `vendor`, `node_modules`, `storage`, `bootstrap`, `public` чи теці, що починається з крапки (без
  урахування регістру). Зарезервована назва PHP відхиляється (у `generate` у кожному сегменті імені).
- Згенеровані дії, фільтри, картки й tools отримують читабельну назву (`RateComment` → `Rate Comment`)
  замість назви класу. Підказки наступних кроків artisan-команд і `generate` пропонують `::make()`.
- `Action::validatedFields()` бере значення кожного поля з `actionValue()`; назви полів для повідомлень
  валідації дії беруться з `validationAttributeNames()` (зокрема вкладені ключі). Модальне вікно дії
  показує під полем першу помилку вкладених ключів (`attribute.*`).
- Фонові дії нормалізують значення полів до постановки в чергу: значення, що не є скаляром, масивом, датою
  чи enum, кидає `LogicException` з ім'ям поля ще до створення запису запуску; обробник отримує збережені
  значення.
- Поле з `multipart: true` у `fields()` дії кидає `LogicException`, як `File` і `Image` (завантаження в діях
  у 1.6 не підтримуються).
- `File`/`Image` віддають URL файлів за `authorizedTo('view')` замість окремого прапорця.
- Як `@internal` позначено члени `Larsadmin`, які не є API розширень: `locale()`, `locales()`,
  `navigation()`, `searchOrder()`, `modelUsesScout()`, `dashboardForKey()`, `home()`,
  `dashboardNavigation()`, `metricCacheScope()`, `timezone()`. Також явно внутрішні `Field::withAbilities()`,
  `Field::forAction()`, `FileTypes`, `FileResponse`, `StoredFile`, класи консольних команд, `Http`, `Query`,
  `Support` та інші службові класи.

### Security

- **Модель безпеки MCP:** сервер ніколи не працює в production (незалежно від `LARSADMIN_MCP`), без
  `laravel/mcp` не реєструється, лише stdio (без HTTP-маршрутів). Інструменти інтроспекції не виконують
  замикань, не читають БД і не повертають даних записів, опцій з БД, повідомлень винятків, секретів чи
  конфігу поза allowlist. `generate` не пише в production, пише класи застосунку лише в `app/Larsadmin`,
  відмовляється від шляхів поза `base_path()` і посилань.
- MCP: `generate` позначено як destructive (`force` перезаписує файли); розширення він створює лише під
  `packages/` чи `extensions/`. Гейт вважає production також `isProduction()` і назви середовищ `prod` та
  `live` (без урахування регістру). `describe-entity` не повертає значень правил `in:` / `not_in:` (лише
  назву правила).
- `FileSafety::response()` кидає `InvalidArgumentException` для `$ttlMinutes` менше 1.
- **`FileSafety` замість копій:** розширення більше не мусять копіювати перевірки безпеки файлів, тож
  розбіжність на кшталт SECX-03 не повториться.
- **Fail closed:** `authorizedTo()` повертає `false`, коли відповідь невідома; поле розширення, недоступне
  в модальному вікні дії, показує «Недоступно: розширення не завантажено» і не надсилається.

### Upgrade notes

- **Нічого не обов'язково.** Застосунки й розширення 1.5 працюють без змін. Оновіть асети:
  `php artisan larsadmin:publish`.
- **AI-асистенти (необов'язково):** `composer require laravel/boost --dev` і `php artisan boost:install`, або
  `composer require laravel/mcp --dev` і `php artisan larsadmin:ai --skills --mcp`. Див.
  `docs/guide/20-ai-assistants.md`.
- Якщо генератор `larsadmin:*` тепер відмовляється писати, на шляху під коренем застосунку є символічне
  посилання чи junction: запишіть файли в каталог без посилань.
- Код, що викликав тепер внутрішні методи `Larsadmin` (`locale()`, `navigation()`, `timezone()` тощо),
  працює, але вони не входять у контракт 1.x.
- Розширення з копією перевірок файлів (як було в spike медіатеки) може перейти на `FileSafety` і
  `"maxcxam/larsadmin": "^1.6"`.
- **Нові члени `Field`, що можуть конфліктувати з вашими спадкоємцями:** `authorizedTo()`, `inAction()`,
  `actionValue()`, `withAbilities()`, `forAction()`. Метод з несумісною сигнатурою дає fatal error під час
  завантаження класу.

## [1.5.0] - 2026-10-31

### Added

- **Публічний API розширень (FR-058).** Розширення є Composer-пакетом (або каталогом застосунку) з
  PHP-класами й зібраними Vue-компонентами: власні поля, картки, фільтри, сторінки меню (tools), маршрути й
  переклади. Встановлюється одним `composer require`, без npm, `vendor:publish` і правок конфігу в хості.
  Панель і розширення ділять один екземпляр Vue та Inertia через import map (`vue`, `@inertiajs/vue3`,
  `larsadmin` → `dist/runtime/vue.js`, `runtime/inertia.js`, `runtime/larsadmin.js`). Посібник:
  `docs/guide/19-extensions.md`.
- **`Larsadmin`:** `API_VERSION` (`'1.5'`), `extension()`, `registeredExtensions()`, `extensionFor()`,
  `routes()` (маршрути розширень під `{path}/ext`, імена `larsadmin.ext.*`, за гейтом панелі),
  `findRecord()` (`viewAny` → скоуп `detailQuery()` → policy, fail closed), `findField()` (поле, як його
  бачить панель: видимість 404, `readonly` 403), `audited()` (зміна поза формою з записом `updated` у
  журнал аудиту), `tools()`, `registeredTools()`, `toolForKey()`.
- **`Maxcxam\Larsadmin\Extensions\Extension`:** `script()`, `style()`, `translations()`, `version()`, `url()`.
  Файли віддає маршрут `GET {path}/extensions/{vendor}/{package}/{version}/{file}` прямо з зареєстрованого
  каталогу (лімітер `larsadmin-files`), версія в шляху, `immutable`-кеш для поточної версії.
- **Модуль `larsadmin` (JS SDK):** `apiVersion`, `defineExtension()` (з `requires: '^MAJOR.MINOR'`),
  `useI18n`, `useLarsadmin`, `useJsonRequest`, `useJsonResource`, `useCardData`, `useToasts`, `useTheme`,
  `useNumberFormat`, `buildUrl`, `withParams`, компоненти `Icon`, `CardShell`, `ConfirmDialog`.
- **Поля:** `Field::$multipart` / `multipart()` (форма надсилається як `multipart/form-data`),
  `hasAuditValue()` / `auditValue()` (аудит поля без колонки), `fillInto(Request, Model, FileJournal)`
  (файли поля в журналі збереження), `bindTo()` і решта хуків спадкоємців стали стабільним API, зокрема
  хуки для перевизначення `__construct(string $name, ?string $attribute = null, ?callable $resolveCallback = null)`,
  `resolveDefault()`, `rulesFor()`, `validationAttributeNames()`, `isRequired()`, `sortable()`.
- **Фільтри:** `Filter::serialize(Request, $value)` став стабільним хуком: фільтр розширення додає props
  до `parent::serialize()`, зберігаючи базові ключі.
  `FileJournal::created()` / `obsolete()` стабільні. Компоненти форми розширень отримують `errors`,
  `errorsByKey`, `describedBy`, `context`, `resourceId`; компоненти відображення `context`.
- **Картки:** `Card::withMeta()`; новий `Maxcxam\Larsadmin\Metrics\DataCard` (`rules()`, `data()`,
  `cacheFor()`, `cacheKeyParts()`, `entityQuery()`) з даними з наявного ендпоінта метрик
  (`200 {"data": …}`, 422 для невалідних параметрів); новий `Maxcxam\Larsadmin\Metrics\CardRequest`.
- **Tools:** `Maxcxam\Larsadmin\Tools\Tool` (`name()`, `uriKey()`, `icon()` зі стабільного `Tool::ICONS`,
  `canSee()`, `props()`), розділ «Інструменти» в бічному меню, сторінка `GET {path}/tools/{tool}`
  (невидимий tool відповідає 404 і не має пункту меню).
- **Перевизначення вбудованих компонентів** (`override: true`) для `text-field`, `password-field`,
  `textarea-field`, `number-field`, `boolean-field`, `select-field`, `date-field`, `datetime-field` (частини
  `form`, `display`, `index`, `detail`), `select-filter`, `boolean-filter`, `date-range-filter`,
  `value-metric`, `trend-metric`, `partition-metric`, `progress-metric`. Лише UI; при збої працює вбудований
  компонент; з `APP_DEBUG=true` журнал перевизначень у консолі.
- **Ізоляція збоїв:** `ExtensionBoundary`, placeholder «Недоступно» / «Завантаження розширення…»,
  завантаження розширень паралельно з очікуванням до 5 с, довантаження після входу без перезавантаження,
  toast про незавантажене чи несумісне розширення лише з `APP_DEBUG=true`.
- **Переклади розширень:** `Extension::translations('ns')` віддає `trans('ns::ui')` в JavaScript як
  `t('ns::key')`. Нові ключі панелі: `extension_unavailable`, `extension_loading`, `extension_failed`,
  `extension_incompatible`, `field_unavailable_hint`, `tools`.
- **Команди:** `larsadmin:extension {name} [--path=packages] [--field=] [--card=]` (скелет пакета з
  прикладами поля, `DataCard`, тестами Pest і vitest), `larsadmin:field`, `larsadmin:card [--data]`,
  `larsadmin:tool` з `--extension=`.
- **Kit автора** у Composer-архіві (`kit/`): пресет Vite `kit/vite.js` (запобіжник проти другої копії
  Vue/Inertia), `kit/sdk.js` для vitest, `kit/testing.js` (`withPanel`, `mountField`, `mountDisplay`,
  `mountCard`; `mountField` з опцією `errorsByKey`), `kit/theme.css` (тема Tailwind на токенах `--la-*`).
- **`Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin`:** `serializeField()` (з параметром `$entity`),
  `fillField()` (як панель: `fillInto()` з журналом файлів у транзакції, при помилці записані файли
  видаляються), `cardData()`, `assertExtensionRegistered()`, `extensionAssetResponse()`.
- **Перевірка публічного API:** `kit/check-public-api.mjs` і знімок `kit/public-api.json` у Composer-архіві.
  Автори запускають `node vendor/maxcxam/larsadmin/kit/check-public-api.mjs <каталог-розширення>`: скрипт
  перевіряє, що розширення використовує лише стабільну поверхню й перевизначає лише стабільні хуки
  (`Field`, `Filter`, `Card`/`DataCard`, `Tool`); `tests/` розширення не перевіряється (тести паритету). У
  репозиторії панелі `npm run check:api` перевіряє саму поверхню.
- **Конфіг `extensions`:** `source_maps` (`LARSADMIN_EXTENSION_SOURCE_MAPS`, `false`), `max_age` (`31536000`).
- **CSP:** inline-скрипти панелі, import map, тег `app.js` і теги `modulepreload` отримують `nonce`, якщо
  застосунок викликав `Vite::useCspNonce()`.
- **`<link rel="modulepreload">`** у кореневому шаблоні для статичних імпортів `app.js` (з
  `dist/.vite/manifest.json`; без manifest тегів немає): чанки vue, inertia і shared вантажаться паралельно
  одразу після `app.js`, без додаткових послідовних запитів.
- Спільні Inertia-пропи `larsadmin.extensions`, `larsadmin.extensionTranslations`, `larsadmin.debug` і
  `tools` (лише коли зареєстровано розширення чи tools і лише для користувачів, що пройшли гейт).

### Changed

- **Структура `dist/`:** `app.js` тепер невеликий вхідний модуль; код панелі в `chunks/shared-*.js`, Vue й
  Inertia в `chunks/vue-*.js` і `chunks/inertia-*.js`; нові `dist/runtime/vue.js`, `runtime/inertia.js`,
  `runtime/larsadmin.js` для import map.
- Вбудовані поля, фільтри й картки реєструються в тому самому реєстрі, що й розширення;
  `registerField`, `registerFilter`, `registerCard` лишилися внутрішніми обгортками.
- **Поле з невідомим ключем `vendor/name`** показує placeholder і не надсилається з форми (fail closed).
  Невідомий ключ без `/` поводиться як раніше (текст / текстовий input).
- Форма визначає поля файлів за явним ключем `fileField: true`, який надсилають `File`, `Image` і їхні
  спадкоємці, а не за формою значення; поле з `multipart: true` теж переводить форму в multipart.
- `Entity::save()` викликає `fillInto()` для кожного поля (за замовчуванням `fill()`); журнал аудиту бере
  `auditValue()` для полів з `hasAuditValue()`. Вбудовані поля поводяться як раніше.
- `MetricCard` розділено на публічний `CardShell` і логіку метрик; розмітка вбудованих метрик не змінилася.
- `Larsadmin::assetsVersion()` враховує версії розширень, коли вони зареєстровані.
- Без зареєстрованих розширень і tools спільні пропи й Inertia-версія такі самі, як у 1.4; HTML
  відрізняється лише тегами `modulepreload`.
- Розширення реєструються в порядку серверного списку, незалежно від порядку завершення імпорту; імпорт,
  що не завершився за 30 с, позначає розширення як незавантажене.
- `ExtensionBoundary` вимикає компонент лише при помилках render, `setup`, хуків життєвого циклу чи
  завантажувача асинхронного компонента. Помилки обробників подій, watcher-ів і асинхронного коду
  пишуться в консоль (з `APP_DEBUG=true` ще й toast), а введене значення поля зберігається й надсилається.
- `DataCard::entityQuery()` приймає лише зареєстровані Entity (клас чи `uriKey`); `Card::withMeta()` кидає
  `InvalidArgumentException` для базових ключів (`component`, `uriKey`, `name`, `width`, `helpText`,
  `endpoint`); `Larsadmin::audited()` кидає `InvalidArgumentException`, якщо запис не є моделлю Entity.
- Внутрішні (`@internal`) члени стабільних класів позначено явно: `DataCard::cardRequest()`, `resolve()`,
  `cacheKey()` (`resolve()` і `cacheKey()` тепер `final`), конструктор і допоміжні методи `Extension`
  (конструктор приватний), конструктор `CardRequest`, `Extension::NAME_PATTERN`, `Tool::COMPONENT_PATTERN`,
  `Tool::$canSee`.
- `composer.json`: `extra.branch-alias` `dev-main` → `1.x-dev`.

### Security

- **Модель довіри:** розширення є довіреним кодом; межа безпеки лишається на сервері (policies,
  `canSee()`, скоупінг, валідація). Посібник прямо попереджає встановлювати лише довірені розширення.
- **Код розширень лише для користувачів панелі:** список розширень і їхні файли отримують лише
  користувачі, що пройшли гейт `viewLarsadmin`; сторінка входу не завантажує код розширень.
- **Віддача файлів:** лише всередині `realpath` зареєстрованого каталогу (traversal, кодування, `\`, NUL,
  абсолютні шляхи, symlink назовні дають 404), dotfiles і `.vite/` закриті, allow-list типів, SVG з
  `Content-Security-Policy: sandbox`, source maps вимкнені за замовчуванням, `X-Content-Type-Options:
  nosniff`, `Cross-Origin-Resource-Policy: same-origin`. Завантажувач приймає лише URL того самого origin.
- **Перевірка вводу реєстрації:** назви розширень і ключі tools перевіряються з `\z` (кінцевий `\n` не
  проходить); порожній, відносний чи symlink-каталог розширення відхиляється (інакше `realpath('')` віддав би
  файли з робочого каталогу).
- **Scaffold:** команди не пишуть поза `base_path()` через symlink чи junction (перевірка за `realpath`
  найближчого наявного предка); PSR-4 namespace з `composer.json` перевіряється як PHP namespace перед
  вставкою в код.
- **`DataCard`:** параметри лише через `rules()` (решта відкидається), ті самі перевірки власника й
  `canSee()`, що в метрик, кеш з ключем на користувача (`cacheKey()` `final`); картка, яку не може
  отримати колбек `metricCacheScopeUsing()`, не кешується. При попаданні в кеш `viewAny` Entity, прочитаних
  через `entityQuery()`, перевіряється знову: користувач з відкликаним правом отримує 403 (SECX-02).
  `entityQuery()` приймає лише зареєстровані Entity (SECX-06).
- **SECX-01:** віддача файлів розширень перевіряє розв'язаний шлях повторно, тож короткі імена Windows 8.3
  (`VITE~1/manifest.json`) не обходять заборону dotfiles.
- **SECX-04:** scaffold-команди відмовляються писати, якщо під коренем застосунку на шляху є будь-яке
  символічне посилання чи junction, зокрема «висяче».
- **SECX-05:** `useJsonRequest()` (SDK) надсилає CSRF-токен лише на URL того самого origin.
- **SECX-07:** переможець конфлікту ключів між розширеннями визначається порядком реєстрації на сервері, а
  не мережею.
- **Скопійований код безпеки:** посібник вимагає тестів паритету для коду, скопійованого з внутрішніх
  класів панелі (приклад: `FileSafetyParityTest` у spike медіатеки); перевірка публічного API дозволяє
  такі тести в `tests/`.
- **Fail closed:** поле недоступного чи зламаного розширення не надсилається; перевизначення вбудованих
  компонентів змінюють лише UI, серверна валідація й авторизація від клієнта не залежать.
- JSON import map екранує `<`, `>`, `&` і лапки.

### Upgrade notes

- **Оновіть асети:** `php artisan larsadmin:publish` (або `php artisan vendor:publish --tag=larsadmin-assets
  --force`). Публікуйте **весь** каталог: `app.js` тепер невеликий вхідний модуль, а код лежить у нових
  `chunks/shared-*`, `chunks/vue-*`, `chunks/inertia-*`; з'явився каталог `runtime/`. Копія лише `app.js` і
  `app.css` ламає панель.
- **Без розширень нічого не змінюється:** HTML, пропи й Inertia-версія як у 1.4.
- **Перевизначений `resources/views/vendor/larsadmin/app.blade.php`** має отримати import map і атрибути
  `nonce` з шаблону пакета, інакше розширення не завантажаться.
- **CSP:** якщо застосунок задає суворий `script-src`, увімкніть `Vite::useCspNonce()`, інакше import map
  блокується, а поля розширень показують «Недоступно».
- **`Larsadmin::metricCacheScopeUsing()`:** колбек, чий другий параметр типізовано лише як `Metric`, ніколи
  не отримує `DataCard`, і такі картки не кешуються. Щоб кешувати картки з даними, типізуйте параметр як
  `Metric|DataCard`, `Card` або `mixed`. Нетипізований колбек тепер може отримати будь-яку `Card`: не
  викликайте в ньому методів лише `Metric` (наприклад, `ranges()`).
- Спадкоємці `DataCard` не можуть мати власних методів `resolve()` чи `cacheKey()` (вони `final`).
- **Нові члени базових класів, що можуть конфліктувати з вашими спадкоємцями:** `Field::$multipart`,
  `multipart()`, `hasAuditValue()`, `auditValue()`, `fillInto()`; `Card::$meta`, `withMeta()`. Властивість з
  іншим типом дає fatal error під час завантаження класу; перейменуйте її або приведіть до сумісної сигнатури.
- Власні поля з ключем `component` вигляду `vendor/name` без зареєстрованого розширення тепер не
  надсилаються з форми (раніше показувалися текстовим input-ом).
- Власне поле, чиє значення лише схоже на формат полів файлів (`{ present, keep, upload }`), більше не
  переписується формою: воно надсилається як є. Спадкоємців `File` і `Image` це не зачіпає.
- **`branch-alias`:** гілка `main` пакета тепер `1.x-dev`, тож розширення з `"maxcxam/larsadmin": "^1.5"`
  встановлюються проти панелі з гілки (з `minimum-stability: dev`).
- Тести, що викликають `Larsadmin::flushState()`, знімають і розширення, зареєстровані провайдерами.

### Known issues

- Перше завантаження панелі приблизно на 27 КБ gzip більше, ніж у 1.4, також без розширень: спільні
  чанки Vue та Inertia не проходять tree-shaking (додаткові послідовні запити прибрано `modulepreload`).
- `FileTypes` і `FileResponse` внутрішні: розширення, що віддає файли, тримає власну копію перевірок
  безпеки з тестами паритету (публічний відповідач заплановано на 1.6). Копія в spike медіатеки вже
  розходилася з ядром (SECX-03); тепер її звіряє з ядром `FileSafetyParityTest`.
- Поля не отримують прав `view`/`update` на кожен запис; поле, якому вони потрібні, перевіряє policy саме
  (N викликів на сторінку index). Заплановано на 1.6.
- Поля розширень у модальних вікнах дій не підтримуються (1.6).
- `Larsadmin::flushState()` знімає розширення, зареєстровані провайдерами під час boot: у тестах
  реєструйте їх знову після нього.
- `FileJournal` видаляє файли, але не каталоги: після відкинутого завантаження можуть лишатися порожні
  каталоги медіа.
- `larsadmin:extension` завжди пише пакет у `PATH/VENDOR/PACKAGE`.
- Розширення без збірки пишуться лише render-функціями (панель постачає Vue без компілятора шаблонів).

## [1.4.1] - 2026-10-31

### Fixed

- Повторне виконання `app.js`. Ліниві чанки зібраного `dist/` (`NotificationPanel`, `echo`) імпортували
  точку входу як `../app.js`, тоді як сторінка завантажує її як `app.js?id=<hash>`. Браузер вважав це двома
  різними модулями, тож під час першого відкриття дзвіночка (або завантаження realtime) застосунок
  запускався вдруге: другий екземпляр Inertia, дублікати спільного стану, опитування й слухачів. Тепер увесь
  статично завантажуваний код лежить в одному спільному чанку `chunks/shared-*.js`, `app.js` лише імпортує
  його, а ліниві чанки імпортують той самий чанк. Збірка падає, якщо будь-який чанк імпортує точку входу.

## [1.4.0] - 2026-10-31

### Added

- **Сповіщення в панелі (FR-034).** Дзвіночок у шапці між пошуком та ім'ям користувача: лічильник
  непрочитаних («99+»), випадний список (по 15, «Показати ще»), «Позначити всі прочитаними», позначення
  прочитаним і видалення окремих сповіщень, спливні повідомлення для нових (до трьох одночасно). Дзвіночок
  показує лише сповіщення типу `larsadmin` з таблиці `notifications` застосунку, адресовані користувачу
  guard-а панелі з трейтом `Notifiable`. Без realtime лічильник опитується кожні 60 с, лише коли вкладка
  видима (після помилок інтервал подвоюється до 5 хвилин). Доступність WCAG 2.1 AA, переклади uk/en.
  Посібник: `docs/guide/18-notifications.md`.
- **`Maxcxam\Larsadmin\Notifications\LarsadminNotification`**: `make()`, `title()`, `body()`, `level()`,
  `info()`/`success()`/`warning()`/`danger()`, `icon()`, `url()`, `sendTo()`. Канал `database`,
  `databaseType()` = `larsadmin`; клас можна наслідувати (наприклад, додати `mail`).
- **Фонові дії (FR-057).** `Action::$queued` / `queued()`, `$queue` / `onQueue()`, `$queueConnection` /
  `onQueueConnection()`, `$timeout`, `runsQueued()`. Дія виконується в черзі одним job-ом
  `RunQueuedAction` на запуск (`tries = 1`, без повторів), пакетами по `chunkSize` у транзакціях;
  прогрес і результат зберігаються в новій таблиці `larsadmin_action_runs`. Розділ «Фонові задачі» в
  дзвіночку: прогрес (активні запуски опитуються кожні 3 с, через 2 хвилини кожні 10 с), «Скасувати»
  (у черзі одразу, під час виконання після поточного пакета), «Очікує на обробник черги» через хвилину.
  Після завершення власник отримує сповіщення: повідомлення дії, посилання «Відкрити» для `redirect()`,
  «Завантажити» для `download()` і `file()`. Кожен запуск надсилає сповіщення рівно один раз, навіть за
  повторної доставки job-а. З драйвером черги `sync` результат приходить одразу, як від синхронної дії.
  Модальне вікно фонової дії попереджає про виконання у фоні.
- **Завислі запуски.** Запуск `running` без «серцебиття» (`updated_at`, оновлюється після кожного пакета)
  довше за `$timeout` дії (або `actions.stale_after`, 3600 с) плюс 60 с і запуск `queued` довше за
  `actions.queued_stale_after` (86 400 с) завершуються один раз зі статусом `failed`, з повідомленням
  власнику й стиранням `payload`. Перевірка відбувається під час підрахунку ліміту, опитування `status`, у
  списку й перегляді запусків і в `larsadmin:prune-notifications`; завислий запуск скасовується одразу.
- **Попередження про `retry_after`.** Якщо `$timeout` фонової дії не менший за `retry_after` з'єднання
  черги, постановка пише попередження в лог.
- **Сесія дзвіночка.** Фоновий запит, що отримав 401/419, не перезавантажує сторінку: опитування
  зупиняється, показується повідомлення «Сеанс завершився». Позначення прочитаним, видалення й
  скасування не відкочуються відповіддю списку, що була в дорозі.
- **`ActionResponse::file(string $disk, string $path, ?string $filename = null)`**: панель сама віддає файл з
  диска як завантаження. У фоновій дії файл віддає `GET {path}/runs/{run}/download` лише власнику, а
  видаляє `larsadmin:prune-notifications` разом із запуском. У синхронній дії посилання
  `GET {path}/downloads/{token}` зашифроване, прив'язане до користувача й дійсне `files.url_ttl` хвилин;
  воно працює й з вимкненими сповіщеннями. У фоновій дії панель копіює файл у
  `larsadmin/runs/{run}/{filename}` на `files.disk` і віддає та видаляє лише цю копію.
- **Realtime (опційно).** Коли broadcaster за замовчуванням це Reverb або Pusher з ключем, панель отримує
  сигнали миттєво (`notifications.realtime` = `auto`). Власне з'єднання лише на сторінках панелі,
  приватний канал `private-larsa_inbox.{token}` з HMAC-токеном замість id користувача, подія
  `.larsadmin.signal`. `laravel-echo` і `pusher-js` вбудовано в окремий файл `dist/chunks/echo-*.js`,
  який вантажиться лише з увімкненим realtime.
- **Подія `Maxcxam\Larsadmin\Events\ActionRunFinished`** (після кінцевого статусу запуску) і модель
  `Maxcxam\Larsadmin\Actions\Queued\ActionRun` (лише для читання).
- **Команда `larsadmin:prune-notifications {--days=}`**: завершує завислі запуски, потім видаляє
  сповіщення панелі, запуски фонових дій і копії їхніх файлів `file()` (лише `larsadmin/runs/{run}/…`),
  старші за `notifications.retention_days` (30 днів), пакетами по 1000. Розклад налаштовує застосунок.
- **`Larsadmin::notificationsEnabled()`.**
- **Конфіг `notifications`**: `enabled` (`LARSADMIN_NOTIFICATIONS`, `false`), `poll_interval` (60; `0` або
  15..3600), `per_page` (15; 5..50), `retention_days` (30), `throttle` (120), `realtime`
  (`LARSADMIN_REALTIME`, `auto`), `channel_prefix` (`LARSADMIN_CHANNEL_PREFIX`, `larsa_`),
  `realtime_client.host|port|scheme` (`LARSADMIN_REALTIME_HOST|PORT|SCHEME`). У `actions`:
  `queued_max_all` (10000), `queued_max_active` (3), `stale_after` (3600), `queued_stale_after` (86400),
  `queue` (`LARSADMIN_QUEUE`), `queue_connection`
  (`LARSADMIN_QUEUE_CONNECTION`), `runs_table` (`larsadmin_action_runs`), `runs_connection`
  (`LARSADMIN_RUNS_CONNECTION`).
- **Міграція** `create_larsadmin_action_runs_table` (тег `larsadmin-migrations`).
- **Маршрути** (група `auth_middleware`, лімітер `larsadmin-notifications`): `GET api/notifications`,
  `GET api/notifications/status`, `POST api/notifications/read`, `POST api/notifications/{id}/read`,
  `DELETE api/notifications/{id}`, `GET api/runs`, `GET api/runs/{run}`, `POST api/runs/{run}/cancel`,
  `POST broadcasting/auth`; з лімітером `larsadmin-files`: `GET runs/{run}/download`,
  `GET downloads/{token}`. Спільні Inertia-пропи `larsadmin.notifications` і `larsadmin.run`; у пропах
  дій з'являється `queued`, коли сповіщення ввімкнено.

### Changed

- Синхронні дії виконуються через винесений `ActionRunner` (`@internal`); поведінка не змінилася.
  Найсуворіша відповідь тепер ранжує `file` нарівні з `redirect`/`download`.
- Спливні повідомлення панелі показуються стеком (`ToastStack`); flash-повідомлення поводяться як раніше.
- Журнал аудиту: `ActionAudit` приймає додаткові `meta`; записи фонової дії мають `meta.run`, у JSON
  журналу дія позначається `action.queued: true`.
- `larsadmin:install` публікує і нову міграцію та нагадує, як увімкнути сповіщення.
- Опублікований стаб `LarsadminServiceProvider` містить закоментований приклад `LarsadminNotification`.

### Security

- **Повторна авторизація у фоновому обробнику.** Обробник діє як користувач, що запустив дію, і
  повторює всі перевірки: `viewLarsadmin`, реєстрацію Entity, `viewAny`, `canSee()` дії,
  `authorizedToRunStandalone()` або `authorizedToRun()` для кожного запису, `indexQuery()`/`detailQuery()`.
  Невідомий користувач, Entity, дія чи помилка розшифрування завершують запуск помилкою (fail closed).
  Вибір «усі знайдені» перебудовується з фільтрів і пошуку в обробнику.
- **SECN-01: атомарний ліміт активних запусків.** Підрахунок і створення запуску виконуються під
  блокуванням кешу для кожного користувача з повторним підрахунком, тож паралельні запити не перевищать
  `queued_max_active`. Потрібне сховище кешу з блокуваннями.
- **SECN-02: ізольована сесія.** У фоновій дії `session()`, фасад `Session` і `$request->session()` це
  окрема порожня сесія в пам'яті для кожного запуску; значення одного запуску не видно наступному в тому
  самому обробнику. Відтворений запит бере хост з `app.url` або `larsadmin.domain`, а не із заголовка
  `Host`.
- **SECN-03: файли `file()` фонових дій.** Панель віддає й видаляє лише власну копію в
  `larsadmin/runs/{run}/`, файл застосунку не чіпає. Приклади в документації використовують унікальні
  назви (ULID); додано пораду щодо ін'єкції формул у CSV.
- **SECN-04: 404 без назви класу.** Відповіді 404 для сповіщень і запусків не містять назви класу
  моделі; невалідний `cursor` дає 422.
- **SECN-05: суворі регулярні вирази.** Перевірка URL (`PanelUrl`) і префікса каналу використовує `\z`,
  тож завершальний `\n` більше не проходить.
- **SECN-06: авторизація під час довгого запуску.** Перед кожним пакетом обробник перечитує користувача
  й знову перевіряє доступ до панелі; ліміт `queued_max_all` перевіряється ще раз після перерахунку в
  обробнику.
- **Зашифрований payload.** Вибір і провалідовані значення полів (зокрема `Password`) зберігаються в
  `payload`, зашифрованому `APP_KEY` (`encrypted:array`), і стираються після завершення запуску. У черзі
  лежить лише id запуску. JSON запуску не містить ні `payload`, ні диска й шляху файлу.
- **Лише власник.** Сповіщення шукаються лише серед сповіщень типу `larsadmin` поточного користувача,
  запуски лише серед його запусків; чуже чи неіснуюче відповідає 404 (id валідуються як UUID/ULID).
- **Ізольовані realtime-канали.** Канали `larsa_*` авторизує лише `POST {path}/broadcasting/auth` панелі,
  який відхиляє (403) будь-який канал без префікса; `/broadcasting/auth` застосунку їх не авторизує.
  Назва каналу містить HMAC-токен з міткою призначення, а не id користувача. Внутрішній хост Reverb
  у браузер не передається; клієнт вважається підключеним лише після успішної підписки. Сигнали несуть лише ідентифікатори й прогрес,
  без заголовків, текстів і посилань. Секрет і `app_id` broadcaster-а в браузер не передаються.
  `window.Echo` і `window.Pusher` застосунку панель не чіпає.
- **Посилання.** `LarsadminNotification::url()` і результати `redirect()`/`download()` фонових дій
  приймають лише URL того самого застосунку і перевіряються ще раз під час читання; клієнт відкриває
  лише посилання на тому самому origin. Тексти показуються як простий текст, рівні й іконки з білого
  списку.
- **Ліміти:** до `queued_max_active` (3) активних запусків на користувача, до `queued_max_all` (10 000)
  записів для «усіх знайдених», лімітер `larsadmin-notifications` (120 на хвилину).
- Усі JSON-відповіді мають `Cache-Control: no-store, private`; завантаження `file()` віддаються як
  `attachment` з `nosniff`.

### Upgrade notes

- **Оновіть асети:** `php artisan vendor:publish --tag=larsadmin-assets --force` (або
  `php artisan larsadmin:publish`). Публікуйте весь каталог: дзвіночок і клієнт realtime лежать в окремих
  файлах `dist/chunks/`, які вантажаться на вимогу.
- **Можливість вимкнено за замовчуванням**, `composer update` нічого не змінює: нові маршрути
  відповідають 404, а дії з `$queued` виконуються синхронно. Щоб увімкнути: `php artisan
  make:notifications-table` (якщо таблиці ще немає), `php artisan vendor:publish --tag=larsadmin-migrations`,
  `php artisan migrate`, `LARSADMIN_NOTIFICATIONS=true`, обробник черги (`php artisan queue:work`) і
  `Schedule::command('larsadmin:prune-notifications')->daily()`. Модель користувача панелі має
  використовувати `Notifiable`.
- **Налаштуйте `retry_after`** з'єднання черги більшим за `--timeout` обробника й `$timeout` кожної
  фонової дії (інакше в лозі з'явиться попередження, а черга видаватиме job повторно).
- Якщо `REVERB_HOST` внутрішній, задайте `LARSADMIN_REALTIME_HOST` (і за потреби `_PORT`, `_SCHEME`):
  внутрішній хост у браузер не передається.
- **Нова міграція** `create_larsadmin_action_runs_table`. Назву таблиці й з'єднання
  (`actions.runs_table`, `actions.runs_connection`) задавайте до `migrate`.
- **Нові члени базового `Action`, що можуть конфліктувати з членами ваших дій:** властивості
  `public bool $queued`, `public ?string $queue`, `public ?string $queueConnection`, `public ?int $timeout`
  і методи `queued(bool $queued = true): static`, `onQueue(?string $queue): static`,
  `onQueueConnection(?string $connection): static`, `runsQueued(): bool`. Якщо ваша дія вже оголошує член з
  такою назвою, перейменуйте його або приведіть до сумісної сигнатури: властивість з іншим типом
  призводить до fatal error під час завантаження класу, а наявна властивість `$queued = true` перемкне дію
  у фоновий режим після ввімкнення сповіщень.
- **`laravel-echo` і `pusher-js` вбудовано в `dist/`**: застосунку нічого встановлювати не треба. Realtime
  вмикається сам, якщо `BROADCAST_CONNECTION` це `reverb` або `pusher`; `LARSADMIN_REALTIME=false`
  вимикає його. Якщо застосунок задає CSP, додайте адресу WebSocket у `connect-src`.
- Не використовуйте префікс `larsa_` для broadcast-каналів застосунку (або змініть
  `LARSADMIN_CHANNEL_PREFIX`).
- Опублікований `config/larsadmin.php` не потребує змін; щоб налаштувати можливість, додайте секцію
  `notifications` і ключі `actions.*` (див. `docs/guide/18-notifications.md`).

### Known issues

- Дзвіночок показує лише сповіщення типу `larsadmin` для моделі користувача guard-а панелі з
  `Notifiable`; інші сповіщення застосунку в панелі не видно.
- Адміністратори не бачать і не можуть скасувати чужі фонові запуски.
- У `handle()` фонової дії немає сесії, cookies і заголовків оригінального запиту, а дати з полів
  приходять рядками ISO-8601. `canSee()`, `canRun()` і `fields()`, що залежать від сесії, отримають
  порожні значення.
- Поля `File` і `Image` у діях, зокрема фонових, не підтримуються.
- Фонова дія не повторюється автоматично; з драйвером `sync` не діють таймаут і скасування.
- Файл `ActionResponse::file()` застосунку лишається на диску і в синхронній, і у фоновій дії: його
  прибирає застосунок (панель видаляє лише свою копію).
- Таймаут для визначення завислого запуску читається із зашифрованого `payload`, а не з окремої колонки.
- Завислий запуск визначається лише під час перевірки (ліміт, опитування, список запусків, prune).
- Ліміт активних запусків атомарний лише зі сховищем кешу, що підтримує блокування.
- Зміна `APP_KEY` змінює токени realtime-каналів: відкриті вкладки втрачають realtime до
  перезавантаження (опитування працює).
- Сторінки журналу аудиту поки не показують позначку «у фоні» для записів фонової дії (є лише в JSON).

## [1.3.1] - 2026-10-31

### Fixed

- PHP 8.5: значення `NAN` у diff журналу аудиту більше не спричиняє попередження «unexpected NAN value was coerced to string» і записується як `"NAN"`.

## [1.3.0] - 2026-10-31

### Added

- **Журнал аудиту (FR-054).** Панель записує, хто, коли і що змінив через неї: створення, зміну,
  видалення (зокрема soft delete), запуск дій (по одному запису на кожен оброблений запис зі спільним
  `batch_id`; для `standalone`-дії один запис), прив'язування й відв'язування `BelongsToMany`. Для
  кожної зміни зберігаються значення полів Entity «було → стало», користувач (тип, ключ, guard, знімок
  підпису), IP-адреса й User-Agent. Посібник: `docs/guide/17-audit-log.md`.
- **Перегляд.** Панель «Історія змін» унизу сторінки запису (10 записів, «Показати ще») і сторінка
  «Журнал аудиту» в бічному меню з фільтрами за розділом, подією, користувачем і датою, пагінацією
  «Попередня / Наступна» без підрахунку загальної кількості, IP і User-Agent у розгорнутому записі.
  Доступність WCAG 2.1 AA, переклади uk/en.
- **Значення.** `BelongsTo` зберігає ключ, назва визначається під час перегляду в межах прав глядача;
  `File`/`Image` зберігають назви й шляхи (у браузер ідуть лише назви); `Select` показує підпис опції.
  Кожне значення обрізається до `max_value_length` (1000), уся різниця запису до 64 КБ. Порівняння як
  у перевірці «брудних» атрибутів Eloquent: надісланий `"5"` проти збереженого `5` і `true` проти `1`
  не є зміною, а `"007"` → `"7"` є; збереження без змін записів не створює. Збереження чи видалення,
  скасоване подією моделі, записів не створює.
- **Користувач у записі.** У JSON зміна без користувача має `actor: null`, інакше
  `{ label, id, system: false }`. Інтерфейс показує підпис з ключем (`Олена Коваль (#12)`) або позначку
  «Система»; однакові підписи у фільтрі доповнюються ` (#id)`. Список користувачів фільтра кешується для
  кожного глядача на 5 хвилин.
- **API.** `Entity::$auditable` (за замовчуванням `true`, opt-out) і `Entity::audited()`;
  `Field::sensitive()` / `isSensitive()`; `Larsadmin::auditEnabled()` і
  `Larsadmin::auditActorLabelUsing(?Closure)` (підпис користувача; за замовчуванням `name`, потім
  `email`, потім `#{id}`).
- **Модель `Maxcxam\Larsadmin\Audit\AuditEntry`** (лише читання) і подія
  **`Maxcxam\Larsadmin\Events\AuditRecorded`**: диспатчиться після коміту, лише якщо має слухачів, з
  колекцією записаних `AuditEntry` (для пакетних вставок `id = null`, кореляція за `batch_id`). Виняток
  у слухачі передається в `report()` і не ламає запит.
- **Команда `larsadmin:prune-audit {--days=}`**: видаляє записи, старші за `audit.retention_days`
  (90 днів) або `--days`, пакетами по 1000. Розклад налаштовує застосунок.
- **Конфіг `audit`**: `enabled` (`LARSADMIN_AUDIT`, `false`), `table` (`larsadmin_audit_entries`),
  `connection` (`LARSADMIN_AUDIT_CONNECTION`), `retention_days` (90, `null` зберігає все), `store_ip`,
  `store_user_agent` (`true`), `max_value_length` (1000, не менше 100), `per_page` (25, межі 1..100),
  `throttle` (60 запитів на хвилину на користувача, `null` вимикає).
- **Перша міграція пакета** `create_larsadmin_audit_entries_table` і тег публікації
  `larsadmin-migrations`. Міграція не підключається до `migrate` неявно.
- **Маршрути** `GET {path}/audit` (`larsadmin.audit.index`, Inertia `Audit/Index`) і
  `GET {path}/api/entities/{entity}/{id}/audit` (`larsadmin.api.audit`, JSON) з лімітером
  `larsadmin-audit`. `page` від 1 до 1000 (історія запису: інакше 422); для Entity з
  `$auditable = false` історія запису відповідає 404. Спільний Inertia-проп `larsadmin.audit` (`null`
  або `{ url }`) і проп `audit` сторінки запису.
- **Дії.** Під час дії не пишуть окремих записів лише записи її поточного пакета (їх покриває запис дії);
  інші збереження через Entity записуються як звичайно зі спільним `batch_id` запуску.

### Changed

- `larsadmin:install` додатково публікує міграцію журналу (`larsadmin-migrations`) і нагадує, як його
  ввімкнути.
- Опублікований стаб `LarsadminServiceProvider` містить закоментований приклад gate `viewLarsadminAudit`.
- `Entity::save()` і `Entity::deleteRecord()` пишуть запис журналу в своїй транзакції, коли Entity
  аудитується. Сигнатури не змінилися; з вимкненим журналом поведінка та сама.
- Відповідь 404 для запису поза областю (`detailQuery()` тощо) у всій панелі більше не містить назви класу
  моделі: JSON-відповідь раніше включала повідомлення `ModelNotFoundException` з класом моделі.

### Security

- **Gate `viewLarsadminAudit` закритий за замовчуванням.** Пакет його не визначає, тож журнал не бачить
  ніхто, доки застосунок не визначить gate. `allow_local` на нього не діє.
- **Скоупінг перегляду.** Сторінка «Журнал аудиту» показує записи лише Entity, зареєстрованих зараз, на
  яких глядач має `viewAny`. Історія запису додатково вимагає запис у межах `detailQuery()` (інакше 404)
  і `view` (інакше 403). Назви й посилання на записи та пов'язані записи показуються лише в межах
  `detailQuery()` і `view`, інакше `#id`. Зміни полів, прихованих `canSee`, і полів, яких уже немає,
  не показуються.
- **Маскування.** Записуються як «змінено» без значень: поля `Password` (завжди) і `->sensitive()`;
  атрибути з `$hidden` моделі та атрибути поза непорожнім `$visible`; касти `hashed`, `encrypted`,
  `encrypted:*`, `AsEncrypted*` і будь-який клас касту, у назві якого є «encrypt». Під час перегляду
  маскування застосовується повторно, а поля, приховані і зі списку, і зі сторінки запису (наприклад,
  `onlyOnForms()`), теж показуються як «Приховано». Значення полів дії маскуються за правилами `Password`
  і `sensitive()`. Accessor-и, що розшифровують значення, і JSON-колонки з секретами позначайте
  `->sensitive()` (SECA-01).
- **Незмінність.** Маршрутів для зміни чи видалення записів журналу немає. `AuditEntry` має
  `$guarded = ['*']`, а `save()`, `push()`, `update()`, `increment()`, `delete()` і події
  `updating`/`deleting` кидають `LogicException`; пише записи лише пакет, видаляє лише
  `larsadmin:prune-audit`. Для справжньої стійкості до підробки використовуйте права БД або окреме
  з'єднання журналу (SECA-02).
- **Окреме з'єднання.** Помилка запису журналу на окремому з'єднанні (після коміту зміни) логується з
  рівнем `critical` (SECA-10).
- **Глобальна сторінка не обмежується за рядками (SECA-08).** Будь-хто з gate `viewLarsadminAudit` і
  `viewAny` на Entity бачить усі записи журналу цієї Entity, зокрема поза своїм `indexQuery()`. Gate
  це межа довіри.
- **Лімітер `larsadmin-audit`** (`audit.throttle`, 60 на хвилину) і обмеження `page` до 1000 на маршрутах
  журналу.
- **404 без назви класу.** Відповідь 404 у панелі не розкриває клас моделі.
- **Персональні дані (SECA-09).** Посібник описує, як видалити чи знеособити записи людини запитом у БД;
  інтерфейсу для цього в панелі немає.
- **Атомарність.** Запис журналу вставляється в транзакції зміни: відкат зміни прибирає запис, а помилка
  вставки відкочує зміну. Ввімкнений журнал без таблиці відкочує кожну зміну з підказкою про міграцію.
- Відповіді журналу мають `Cache-Control: no-store, private`.

### Upgrade notes

- **Оновіть асети:** `php artisan vendor:publish --tag=larsadmin-assets --force` (або
  `php artisan larsadmin:publish`). Без цього сторінка «Журнал аудиту» порожня: у старій збірці немає
  сторінки `Audit/Index`.
- **Журнал вимкнено за замовчуванням**, `composer update` нічого не змінює. Щоб увімкнути:
  `php artisan vendor:publish --tag=larsadmin-migrations && php artisan migrate`, визначте gate
  `viewLarsadminAudit`, задайте `LARSADMIN_AUDIT=true` і заплануйте
  `Schedule::command('larsadmin:prune-audit')->daily()`. Без розкладу записи не видаляються; за
  замовчуванням команда зберігає 90 днів.
- Це **перша міграція пакета**. Назву таблиці й з'єднання (`audit.table`, `audit.connection`) задавайте
  до `migrate`.
- **Нові члени базового `Entity`, що можуть конфліктувати з членами ваших Entity:**
  `public static bool $auditable`, `public static function audited(): bool` і
  `public function auditedFields(Request $request): Collection` (`@internal`). Якщо ваша Entity вже
  оголошує член з такою назвою, перейменуйте його або приведіть до сумісної сигнатури: властивість з
  іншим типом чи нестатичний член призводить до fatal error під час завантаження класу.
- **Нові члени базового `Field`, що можуть конфліктувати з вашими класами полів:**
  `sensitive(bool $sensitive = true): static` і `isSensitive(): bool`.
  Власні класи полів з методами таких назв мають бути сумісні з цими сигнатурами.
- Опублікований `config/larsadmin.php` не потребує змін; щоб налаштувати журнал, додайте секцію `audit`
  (див. `docs/guide/17-audit-log.md`).

### Known issues

- Записуються лише зміни, зроблені через панель. Зміни, які застосунок робить деінде (свої контролери,
  черги, tinker), у журнал не потрапляють.
- Поля беруться з `fields()` для користувача, що робить зміну: якщо `fields()` умовний, поля, не
  повернуті для цього користувача, не записуються. Обмежуйте видимість через `canSee()`.
- Різниця дії охоплює лише поля цільової Entity; зміни інших таблиць у `handle()` не записуються.
- Окреме з'єднання журналу (`audit.connection`) не дає атомарності: запис пишеться після коміту, а
  помилка вставки лише логується (`critical`) і передається в `report()`.
- Зміна зашифрованого атрибута, чиє збережене значення вже не розшифровується (наприклад, після зміни
  `APP_KEY`), усе ще кидає виняток з перевірки «брудних» атрибутів Eloquent (CRA-15).
- Сторінка «Журнал аудиту» не перевіряє `indexQuery()` для кожного запису: власник `viewLarsadminAudit`
  бачить історію всіх записів Entity, на яких має `viewAny`. Gate це межа довіри.
- Новий користувач може з'явитися у фільтрі «Користувач» із затримкою до 5 хвилин (кеш).
- Accessor-и, що розшифровують значення, і JSON-колонки з секретами автоматично не маскуються: позначте їх
  `->sensitive()`.
- Атрибути pivot `BelongsToMany` не записуються; прив'язування видно лише в історії батьківського запису.
- Немає відкату зміни з журналу, експорту й пошуку по вмісту змін.

## [1.2.0] - 2026-10-30

### Added

- **Глобальний пошук (Ctrl/⌘+K).** Командна палітра в шапці панелі шукає записи одразу в кількох
  Entity й групує результати за Entity в порядку бічного меню. Кнопка «Пошук…» (на вузьких екранах
  іконка) і гаряча клавіша Ctrl+K / ⌘K, що працює й на українській розкладці. Навігація стрілками,
  Enter відкриває запис, Ctrl/⌘+Enter, середня кнопка або Ctrl/⌘+клік у новій вкладці, Esc закриває; підсвічування збігу, рядок
  «Показати всі» для Entity з `$search`, повідомлення про часткові результати й помилки, доступність
  WCAG 2.1 AA. Посібник: `docs/guide/16-search.md`.
- **API Entity для пошуку.** Участь вмикається явно: `public static bool $globallySearchable`
  (за замовчуванням `false`). `$globalSearchLimit` (ліміт результатів, межі 1..20), `$subtitle` і
  `subtitle()` (другий рядок результату: колонка або `relation.attribute` одного рівня; enum показується
  як `value`/`name`), `usesScout()` і `scoutQuery(Request,
  $search)` для Laravel Scout.
- **SQL-пошук** використовує ту саму логіку, що й поле пошуку списку (`$search`, ключ, `relation.column`,
  екранування `LIKE`), спершу записи, чия назва починається з терміна. Терміни від 2 символів,
  цифрові (пошук за ID) від 1: коротший цифровий термін порівнюється лише з ключем, якщо ключ є в
  `$search` і видимий.
- **Laravel Scout.** Модель з трейтом `Laravel\Scout\Searchable` шукається через рушій Scout
  автоматично, якщо Scout встановлено. Рушій пропонує лише ключі (до `search.scout_candidates`, межі
  1..500), рядки завжди завантажуються через `indexQuery()` у порядку рушія. Scout необов'язковий:
  лише в `suggest`
  (`laravel/scout` ^11.0).
- **Ендпоінт `GET {path}/api/search`** (маршрут `larsadmin.api.search`, лімітер `larsadmin-search`):
  JSON з групами, `more`, `indexUrl`, `failed` і `partial`. Помилка однієї Entity не ламає відповідь:
  Entity потрапляє в `failed`, виняток у `report()`.
- **Конфіг `search`.** Ключі `limit` (5), `min_length` (2), `throttle` (60 запитів на хвилину на
  користувача, `null` вимикає), `scout_candidates` (50), `time_budget_ms` (3000, `null` вимикає): після
  вичерпання бюджету решта Entity пропускається, відповідь має `partial: true`. Конфіг без секції
  працює зі значеннями за замовчуванням.
- **Спільний Inertia-проп `larsadmin.search`**: `null`, якщо користувачеві недоступна жодна Entity з
  пошуком (тоді кнопки й гарячої клавіші немає), інакше `{ minLength, maxLength }`.

### Changed

- Розрахунок атрибутів полів, прихованих `canSee`, винесено з `Entity::applySearch()` у
  `Entity::unseenAttributes(Request)` (`@internal`). Поведінка пошуку списку не змінилася.

### Security

- **Скоупінг Scout-результатів.** Ключі з рушія ніколи не повертаються напряму: записи завантажуються
  через `indexQuery()` Entity, ключі поза областю користувача й застарілі ключі відкидаються. Резервного
  SQL-пошуку при помилці рушія немає.
- **Перевірка кожного результату.** Entity без `viewAny` мовчки пропускається й не згадується у
  відповіді (також у `failed`); кожен результат перевіряється на `view`, записи без дозволу
  відкидаються. Виняток під час перевірки `viewAny` означає «заборонено».
- **Приховані поля.** SQL-шлях не шукає й не сортує за колонками полів, прихованих `canSee`. Якщо всі
  колонки `$search` приховані (або єдина колонка це цілий ключ, а термін не цифровий), Entity не
  повертає нічого замість усіх рядків.
- **Назви й підзаголовки зі зв'язків.** `$title`/`$subtitle` виду `relation.attribute` показуються лише
  тоді, коли пов'язана модель має зареєстровану Entity, користувач має на ній `viewAny`, атрибут не
  прихований `canSee` і пов'язаний запис у межах її `detailQuery()`. Поле, приховане `canSee` на самій
  Entity, теж не показується. Зв'язок завантажується одним запитом, без lazy loading.
- **`canSee` і Scout.** На Scout-шляху збіг визначає індекс рушія (ADR-021): тримайте поля, приховані
  `canSee`, поза `toSearchableArray()`.
- **Посилання палітри.** Палітра показує лише посилання на той самий origin.
- **Відмова в `indexQuery()`.** `abort(403)`/`abort(404)` мовчки пропускає Entity; інші HTTP-помилки
  потрапляють у `failed`.
- **Валідація й обмеження.** `search` обов'язковий рядок до 100 символів (інакше 422), лімітер
  `larsadmin-search`, `Cache-Control: no-store, private`, без кешування результатів.

### Upgrade notes

- **Нові члени базового `Entity`:** `$globallySearchable`, `$globalSearchLimit`, `$subtitle`,
  `subtitle()`, `usesScout()`, `scoutQuery()`, `unseenAttributes()`. Якщо ваша Entity вже оголошує
  член з такою назвою, перевірте його сумісність. Особливо `$subtitle`: властивість з іншим типом
  (`public static $subtitle` без типу, `string` замість `?string`) або нестатична властивість
  призводить до fatal error під час завантаження класу. Перейменуйте свій член або приведіть його до
  `public static ?string $subtitle`. Так само метод `subtitle()` має бути сумісний з
  `public function subtitle(): ?string`.
- Опублікований `config/larsadmin.php` не потребує змін; щоб налаштувати пошук, додайте секцію `search`
  (див. `docs/guide/16-search.md`).
- Глобальний пошук вимкнено, доки жодна Entity не ввімкне `$globallySearchable`.

### Known issues

- Бюджет часу перевіряється перед кожною Entity: один повільний запит не переривається. Обмежте час
  запитів у базі (statement timeout).
- Scout-кандидати можуть «голодувати»: якщо `indexQuery()` відсікає більшість ключів з рушія,
  результатів менше за ліміт. Пом'якшення: `scoutQuery()` або більший `search.scout_candidates`.
- Scout-індекс не знає про `canSee`: поля в `toSearchableArray()` шукаються для всіх, хто бачить
  список. Вимкнення: `usesScout(): false`.
- «Показати всі» відкриває список з SQL-пошуком по `$search`, тож для Scout-Entity список може
  відрізнятися від результатів палітри.
- Немає історії запитів; гаряча клавіша лише Ctrl/⌘+K.
- Рушій Scout `database` не екранує `%` і `_` у терміні, тож вони працюють як шаблони `LIKE` (SECS-3).
- На SQLite `LIKE` ігнорує регістр лише для латиниці, тож «огляд» не знаходить «Огляд». Це стосується
  й пошуку списку та рушія Scout `database` на SQLite; MySQL і PostgreSQL цього обмеження не мають.

## [1.1.0] - 2026-10-30

### Added

- **Поля `File` і `Image`.** Завантаження файлів на диск Laravel прямо з форми створення й
  редагування: кнопка вибору, перетягування файлів, прибирання й повернення до збереження,
  попередній перегляд нових зображень, прогрес завантаження. `Image` приймає лише растрові
  зображення (`jpg`, `jpeg`, `png`, `gif`, `webp`, `avif`) і показує мініатюри в списку, на сторінці
  запису й у формі. Методи `multiple()`, `maxFiles()`, `disk()`, `public()`, `private()`, `path()`,
  `types()`, `maxSize()`, `storeOriginalName()`, `prunable()`, `removable()`, `filesOf()`;
  `Image::thumbnail()` для власних URL мініатюр. Посібник: `docs/guide/15-files.md`.
- **Формат зберігання.** Один файл: шлях у колонці (і за бажанням оригінальна назва в окремій
  колонці). `multiple()`: JSON-масив `{path, name, size, mime}` в одній колонці, з cast `array` і без
  нього. Ім'я на диску випадкове, розширення визначається за вмістом.
- **Приватні файли.** Для дисків `local` відносно підписані посилання маршруту
  `larsadmin.files.show` з терміном дії; права (`viewAny`, `detailQuery`, `view`, видимість поля,
  `canSee`) перевіряються під час кожного завантаження. Для S3 тимчасові URL диска. Безпечні
  заголовки відповіді (`nosniff`, CSP `sandbox`, вкладення для всього, крім растрових зображень),
  лімітер `larsadmin-files`.
- **Безпека завантажень.** Перевірка типу за вмістом і за іменем (`types()`), список заблокованих
  розширень з перевіркою подвійних розширень. Шлях ніколи не приходить з браузера: форма надсилає
  токени наявних файлів. Деталі в розділі «Security» нижче.
- **Очищення файлів.** Замінені й прибрані файли та файли видалених записів видаляються після коміту
  транзакції (увімкнено за замовчуванням, `files.prune`, `->prunable(false)`); при невдалому
  збереженні нові файли видаляються. М'яке видалення файли зберігає.
- **`Entity::deleteRecord(Request)`.** Видалення запису в транзакції з очищенням файлів; кнопка
  видалення панелі використовує його, рекомендовано для власних дій видалення.
- **Конфіг `files`.** Ключі `disk` (за замовчуванням `local`, `LARSADMIN_FILES_DISK`), `directory`,
  `max_size`, `max_files`, `prune`, `url_ttl`, `throttle`, `blocked_extensions`. Конфіг без секції
  працює зі значеннями за замовчуванням.
- **`Field::validationAttributeNames()`.** Назви ключів валідації поля для повідомлень; за
  замовчуванням `[атрибут => назва]`.

### Changed

- Форми з полями файлів надсилаються як `multipart/form-data` (оновлення через `POST` з
  `_method=PUT`); решта форм надсилає JSON, як і раніше. Запит, більший за `post_max_size` (413),
  показує повідомлення замість сторінки помилки.
- Кнопка видалення запису виконує видалення в транзакції (`Entity::deleteRecord()`).
- Поля `File` і `Image` у `fields()` дії та `sortable()` на них кидають `LogicException`.

### Security

- **Список заблокованих розширень** перевіряється і в сирому, і в очищеному імені файлу (без
  кінцевих крапок і пробілів), тож символи, які очищення прибирає, не дають обійти перевірку.
- **Розширений список за замовчуванням** `files.blocked_extensions`: серверні скрипти (сімейство PHP,
  CGI, Perl, Python, Ruby, shell, ASP, JSP, ColdFusion, SSI), виконувані файли й скриптові хости
  Windows, розмітка й скрипти для браузера (HTML, MHT, SVG, XML, XSL, JS, SWF), конфігурація сервера.
- **Мінімальний список для публічних дисків.** Розмітка, скрипти для браузера й PHP (`svg`, `html`,
  `xml`, `js`, `php`, `htaccess` тощо) на публічних дисках заблоковані завжди, навіть якщо прибрати
  їх з конфігу. Поле з `public()` на диску `local` без `url` кидає виняток.
- **Назви файлів** очищуються від керівних і форматувальних символів Unicode (перемикачі напрямку
  тексту, символи нульової ширини). Без `storeOriginalName()` файл показується й завантажується під
  назвою поля, а не під випадковим іменем з диска.
- **Кешування.** Вкладення віддаються з `Cache-Control: private, no-store` (і так само для тимчасових
  URL S3 через `ResponseCacheControl`); кешуються лише зображення, показані inline.
- **Токени файлів** обчислюються з окремим доменом HMAC і не збігаються з іншими підписами на
  `APP_KEY`.
- **Безпечне одночасне редагування.** Прибираються лише файли, які форма показала й користувач
  прибрав: файли, додані іншим користувачем, і токени зі старим `APP_KEY` нічого не видаляють.
  `removable(false)` тепер діє й на сервері.

## [1.0.2] - 2026-10-30

### Added

- **Політика безпеки.** `SECURITY.md`: підтримувані версії та приватне повідомлення про вразливості
  через GitHub private vulnerability reporting.
- **Покриття тестами в CI.** Покриття PHP-коду вимірюється (pcov, PHP 8.4 / Laravel 13); збірка
  падає, якщо воно нижче 90 % (зараз 95,8 %).

### Changed

- **Ліцензія.** Пакет тепер розповсюджується за ліцензією MIT. Версії 1.0.0 і 1.0.1 лишаються
  під GPL-3.0-only.

## [1.0.1] - 2026-10-30

### Added

- **Англійські посібники.** Усі посібники розробника перекладено англійською: `docs/guide/en/`
  (ті самі назви файлів). Українські оригінали лишаються в `docs/guide/`. Кожна сторінка обох мов
  має під заголовком посилання на версію іншою мовою. README пакета веде на англійські посібники.

### Fixed

- **Тести.** Набір тестів проходить на Laravel 12 і Pest 4. Тест покриття українських повідомлень
  валідації не залежить від версії Laravel: `lang/uk/validation.php` має містити всі ключі
  англійського файлу фреймворку, а зайві ключі з новішої версії дозволені. Набір даних у тесті
  метрик має ключі, тож Pest 4 більше не сприймає його як callable.

## [1.0.0] - 2026-10-30

Перший публічний реліз. Ліцензія GPL-3.0.

### Added

- **Панель.** Адмін-панель для Laravel на Inertia v3 + Vue 3. Вимоги: PHP 8.3+, Laravel 12 або 13.
  Фронтенд постачається зібраним, Node.js у застосунку не потрібен.
- **Встановлення.** Команди `larsadmin:install` і `larsadmin:publish`; теги публікації
  `larsadmin-config`, `larsadmin-assets`, `larsadmin-lang`, `larsadmin-provider`.
  Конфіг: `name`, `domain`, `path`, `guard`, `locale`, `locales`, `middleware`, `auth_middleware`,
  `allow_without_policy`, `allow_local`.
- **Entities.** Клас `Entity` з `$model`, `$title`, `$search` (колонки, ключ, `relation.column`), `$with`,
  `$label`, `$singularLabel`, `$group`, `$perPage`, `$perPageOptions`, `$displayInNavigation`;
  автореєстрація з `app/Larsadmin`; хуки `indexQuery`, `detailQuery`, `relatableQuery`;
  сортування, пошук і пагінація; генератор `larsadmin:entity`.
- **Поля.** `ID`, `Text`, `Textarea`, `Number`, `Boolean`, `Date`, `DateTime`, `Password`, `Select`
  (у тому числі enum); видимість (`hideFromIndex`, `onlyOnForms` та ін.); `default`, `help`,
  `placeholder`, `nullable`, `readonly`, `resolveUsing`, `displayUsing`, `fillUsing`.
- **Валідація.** `rules`, `creationRules`, `updateRules`; правила-рядки з `|`; плейсхолдер `{{id}}`;
  неявні правила за типом поля.
- **Зв'язки.** `BelongsTo` (пошук, `relatableQueryUsing`, `relatableLimit`, прихований пов'язаний запис,
  параметри `via*`), `HasMany` і `BelongsToMany` як панелі на сторінці перегляду;
  приєднання й від'єднання з правами `attachAny{Model}`, `attach{Model}`, `detach{Model}` та подіями
  `Attached` і `Detached`.
- **Авторизація.** Gate `viewLarsadmin`, режим `allow_local`, власна перевірка через `Larsadmin::auth()`,
  політики моделей (fail closed), опція `allow_without_policy`, обмеження рядків через
  `indexQuery`/`detailQuery`.
- **Фільтри.** `SelectFilter`, `BooleanFilter`, `DateRangeFilter`, значення за замовчуванням,
  `canSee`, власні фільтри та генератор `larsadmin:filter` (з опцією `--force`). Стан у `?filters[key]=value`.
- **Дії.** Клас `Action` з полями модального вікна, підтвердженням, `destructive`, `standalone`,
  `canSee`/`canRun`, обробкою пакетами у транзакціях, масовою дією над усіма записами
  (`resources: 'all'`); відповіді `ActionResponse::message/danger/redirect/download`;
  генератор `larsadmin:action`.
- **Локалізація.** Українська (за замовчуванням) та англійська, перемикач мови, `Larsadmin::resolveLocaleUsing()`,
  перевизначення перекладів через `lang/vendor/larsadmin`, українські повідомлення валідації
  (`lang/uk/validation.php` копіюється під час інсталяції, наявний файл не перезаписується).
- **Теми.** Інтерфейс у стилі GitHub зі світлою, темною та системною темою й перемикачем.
- **Безпека входу.** Окрема сторінка входу з обмеженням частоти спроб (5 на хвилину для email + IP,
  30 на хвилину для IP).

- **Колонки та видимість полів.** `width()`, `align()`, `wrap()`, `truncate()`, `hideByDefault()`;
  умовні `readonly(Closure)`, `hide*/show*(Closure)` і `canSee(Closure)` (приховані й заборонені поля
  пропускаються на сервері); `Entity::$sort` (сортування за замовчуванням); меню «Колонки»
  (показ/приховування, порядок, «Скинути», збереження в браузері).
- **Блоки полів.** `Layout\Panel` (`description()`, `columns(1|2)`, `collapsible()`, `collapsed()`) і
  `Layout\Tabs` у `fields()`; `Field::fullWidth()` для двоколонкових панелей. Блоки лише групують поля:
  валідація, збереження й список не змінюються. Після невдалого збереження згорнуті панелі з помилками
  розгортаються, відкривається перша вкладка з помилкою, вкладки показують кількість помилок.
  Зв'язок `HasMany`/`BelongsToMany` у панелі, вкладені блоки й дубльовані атрибути дають `LogicException`;
  `collapsible()`/`collapsed()` на панелі без назви дають `InvalidArgumentException`.
- **Дії для рядка.** Меню «⋯» у рядку списку для дій з `showInline()`; нові методи `showOnIndex()`,
  `showOnDetail()`, `onlyInline()`. Сервер заздалегідь визначає доступні дії для кожного рядка й
  запису (без додаткових запитів і викликів політики для дій без `canRun()`); меню рядка й сторінки
  перегляду показують лише доступні дії. Меню рядка біля нижнього краю відкривається вгору, а його
  кнопка має назву запису в доступній назві. Дії поза меню списку (`onlyInline()`, `onlyOnDetail()`)
  виконуються рівно для одного запису (`Action::runsOnSingleRecord()`, інакше 422).
- **Метрики.** Картки `Value`, `Trend`, `Partition`, `Progress`, кожна з власним JSON-ендпоінтом.
  - Періоди `N` днів, `TODAY`, `MTD`, `QTD`, `YTD`, `ALL` (напіввідкриті, `[початок, кінець)`) з
    порівнянням з попереднім періодом; підписи днів у правильній формі множини.
  - Хелпери `count`, `sum`, `average`, `min`, `max`, `countByDays/Weeks/Months` тощо. Точки трендів
    рахуються в часовому поясі панелі (коректно під час переходу на літній/зимовий час) одним
    переносним запитом. `Trend::$unit` (`day`/`week`/`month`) задає періоди за замовчуванням
    (7/30/90 днів, 4/12/26 тижнів, 3/6/12 місяців); рядкові періоди в Trend дають
    `InvalidArgumentException`, `showSumValue()` для `average`/`min`/`max` дає `LogicException`.
  - Value: стани «Зросло/Зменшилося на N%», «Без змін»; `0` вважається відсутністю даних, якщо не
    викликано `allowZeroResult()`.
  - Partition: `label()`, `labelsFrom()`, `booleanLabels()` (застосовується автоматично для колонок
    з кастом `boolean`), `colors()`, `limit()` (решта в «Інше»); `NULL`-група підписана «Без значення»;
    для `average`/`min`/`max` без загальної суми й відсотків.
  - Progress: `avoid()`.
  - Форматування `currency()`, `format()`, `prefix()`, `suffix()` (останні два не для Partition).
  - Опційний кеш `cacheFor()` (`larsadmin.metrics.cache_store`), окремо для кожного користувача,
    періоду, поясу й мови; додаткові частини ключа через `Metric::cacheKeyParts()` і
    `Larsadmin::metricCacheScopeUsing()` (наприклад, тенант чи команда).
- **Безпечні джерела метрик.** Клас Entity обмежується `indexQuery()` і потребує `viewAny`;
  `scoped(Entity::class, fn ($query) => ...)` / `EntitySource` звужує Entity, не виходячи за її межі.
  Клас моделі й builder не обмежуються (задокументовано).
- **Дашборди.** Клас `Dashboard`, вбудований `Main`, реєстрація в `LarsadminServiceProvider::dashboards()`
  (екземпляри або назви класів), домашній дашборд на `/admin` (перший видимий), сторінки
  `/admin/dashboards/{uriKey}`, пункти в бічному меню, `canSee()` для дашбордів і карток, ширини
  карток (`1/3`, `1/2`, `2/3`, `full`) і підказки. Дублікати `uriKey()` дашбордів або карток одного
  власника дають `InvalidArgumentException`. Картка з невідомим компонентом показує помилку.
- **Картки Entity.** `Entity::cards()` показує метрики над списком.
- **Часовий пояс метрик.** `Larsadmin::timezoneUsing()` і ключ `larsadmin.timezone` (`LARSADMIN_TIMEZONE`).
- **Генератори.** `larsadmin:dashboard`, `larsadmin:value`, `larsadmin:trend`, `larsadmin:partition`,
  `larsadmin:progress` (з `--force`). Шаблони метрик позначають тимчасове джерело без обмежень
  коментарем `// SECURITY: unscoped`.
- **Теми.** Палітра графіків `--la-chart-1..8` з контрастом щонайменше 3:1 у світлій і темній темі.
- **Документація.** Посібники в `docs/guide` пакета, зокрема розділи «Блоки полів», «Метрики»,
  «Дашборди».

### Security

Результати перевірки безпеки враховано до релізу:

- Дії `standalone` тепер вимагають права: `canRun()` (з `null` замість моделі) або `create` для Entity.
  Раніше `canRun()` ігнорувався (SF-1).
- URL записів (`show`, `edit`, `destroy`, `detach`) будує сервер; ключі зі `/`, `.`, `..` та керуючими
  символами отримують `null` і не можуть вивести запит за межі шляху (SF-2).
- Дія над «усіма знайденими» обмежена `larsadmin.actions.max_all` (1000), пакети виконуються у власних
  транзакціях, а часткова помилка повідомляє «Виконано для N з M» і логується (SF-3).
- Фільтри, приховані `canSee`, не застосовують значення за замовчуванням; задокументовано, що
  фільтри не є межею безпеки (SF-4).
- Пошук за `relation.column` враховує `indexQuery` пов'язаної Entity (SF-5).
- Політики працюють fail closed: якщо політика моделі є, рішення повністю за gate (враховуючи `before()`
  і `Gate::before`), відсутній метод означає заборону. Для моделей без політики діє
  `larsadmin.allow_without_policy` (за замовчуванням `true`) (SEC-1).
- Вхід у панель перевіряє доступ до панелі (`Larsadmin::check`) до створення сесії, тож `/admin/login`
  не стає другою загальною формою входу для користувачів, яким панель закрита (SEC-2).
- Записи (перегляд, редагування, оновлення, видалення) розв'язуються через `detailQuery`
  (за замовчуванням `indexQuery`), тож записи поза областю дають 404 (SEC-3).
- `Select` перевіряє на сервері, що значення є серед опцій (SEC-4).
- Доступ у `APP_ENV=local` без gate керується опцією `allow_local` (`LARSADMIN_ALLOW_LOCAL`, за
  замовчуванням `true`); ризик задокументовано (SEC-5).
- Окремий ліміт спроб входу `larsadmin-login`: 5 на хвилину для email + IP і 30 на хвилину для IP
  (SEC-6).
- `Text`, `Textarea` і `Password` мають неявне правило `string`, тож масиви й некоректний ввід
  відхиляються (SEC-7).
- `viewAny` перевіряється до пошуку запису, щоб відповіді 404/403 не розкривали існування записів
  (SEC-8).
- Панель пускає в `APP_ENV=local` будь-якого автентифікованого користувача, поки `allow_local`
  увімкнено. Вимкніть його (`LARSADMIN_ALLOW_LOCAL=false`) на спільних і публічних серверах.
- Метрики: авторизація не кешується. При попаданні в кеш `canSee()` дашборда й картки виконується
  знову, а `viewAny` кожного Entity-джерела перевіряється повторно (403, якщо право відкликали).
  Кеш ведеться окремо для guard і користувача (клас + ідентифікатор). Власні перевірки всередині
  `calculate()` при попаданні в кеш не виконуються: розміщуйте їх у `canSee()`.
- Ендпоінти метрик обмежені лімітером `larsadmin-metrics`: `larsadmin.metrics.throttle` (за
  замовчуванням 120 на хвилину на користувача, для гостей на IP; `null` вимикає).
- Дії, недоступні з меню списку (`onlyInline()`, `onlyOnDetail()`), відхиляють `resources: 'all'` і
  кілька ідентифікаторів (422).
- Кольори з `PartitionResult::colors()` перевіряються у браузері: приймаються лише `#hex`,
  `rgb()/rgba()`, `hsl()/hsla()`, `oklch()` і `var(--token)`, інше замінюється кольором палітри.

### Fixed

- Інтерфейс: бічна панель на всю висоту (UI-1), сторінка входу поза основним макетом (UI-2), немає
  горизонтальної прокрутки сторінки на мобільних (UI-3), таблиця прокручується у власному контейнері
  (UI-4), адаптивна панель інструментів (UI-5), текуча ширина вмісту (UI-6).

- Фільтр зі значенням за замовчуванням тепер можна очистити (явний `filters[key]=`).
- Відповіді 401/403 у JSON перекладаються.
- Масові дії: найсуворіша відповідь замість останньої; окреме повідомлення, коли записів не знайдено.
- Редірект після збереження за `via*` повертає на сторінку запису, якщо батька не можна переглядати.
- `DateRangeFilter` використовує напіввідкритий інтервал, дружній до індексів.

### Changed

- Пакет вимагає `laravel/framework` ^12.0|^13.0.

### Known issues

- SQLite: колонки лише з датою (`DATE`) у трендах потрапляють у точку попереднього дня (CR11-11).
- SQL метрик перевірено лише на SQLite; CI для PostgreSQL і MySQL поки немає (CR11-20).
- Немає тестів Vue-компонентів (CR11-20).
- На дашборді кожна картка робить окремий HTTP-запит (CR11-18).
- Без `viewAny` ендпоінт карток Entity відповідає 403, а не 404, тож розкриває, що Entity з таким
  ключем існує (SEC11-5).
- `PartitionResult` не має `prefix()`/`suffix()`.

[Unreleased]: https://github.com/maxcxam/larsadmin/compare/v1.6.0...HEAD
[1.6.0]: https://github.com/maxcxam/larsadmin/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/maxcxam/larsadmin/compare/v1.4.1...v1.5.0
[1.4.1]: https://github.com/maxcxam/larsadmin/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/maxcxam/larsadmin/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/maxcxam/larsadmin/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/maxcxam/larsadmin/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/maxcxam/larsadmin/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/maxcxam/larsadmin/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/maxcxam/larsadmin/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/maxcxam/larsadmin/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/maxcxam/larsadmin/releases/tag/v1.0.0
