# Changelog

Усі помітні зміни пакета `maxcxam/larsadmin` документуються в цьому файлі.

Формат базується на [Keep a Changelog](https://keepachangelog.com/uk/1.1.0/),
версіонування на [Semantic Versioning](https://semver.org/lang/uk/).

## [Unreleased]

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

[Unreleased]: https://github.com/maxcxam/larsadmin/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/maxcxam/larsadmin/releases/tag/v1.0.0
