# Changelog

Усі помітні зміни пакета `maxcxam/larsadmin` документуються в цьому файлі.

Формат базується на [Keep a Changelog](https://keepachangelog.com/uk/1.1.0/),
версіонування на [Semantic Versioning](https://semver.org/lang/uk/).

## [Unreleased]

Перший реліз (v1).

### Added

- **Панель.** Адмін-панель у стилі Nova на Inertia v3 + Vue 3. Вимоги: PHP 8.3+, Laravel 12 або 13.
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


### Security

Результати фінальної перевірки безпеки v1 враховано до релізу:

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
