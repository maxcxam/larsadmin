# 19. Розширення: публічний API
🇬🇧 [English](en/19-extensions.md) · 🌐 [larsadmin.dev](https://larsadmin.dev/uk/docs/extensions)

З версії 1.5 панель має публічний API розширень. Розширення є звичайним Composer-пакетом з PHP-класами
та зібраними Vue-компонентами. Воно додає власні **поля**, **картки** дашбордів, **фільтри**, сторінки
в бічному меню (**tools**), власні HTTP-маршрути й переклади, а також може замінити інтерфейс кількох
вбудованих компонентів.

Розширення встановлюється так:

```bash
composer require acme/larsadmin-rating
```

Хосту не потрібні npm, `vendor:publish` чи правки конфігу: пакет реєструється у своєму провайдері, а
панель сама віддає його файли. npm потрібен лише автору розширення, щоб зібрати власні Vue-компоненти.

Усе, що описано на цій сторінці, є **стабільним API 1.x** (див. [Версії й сумісність](#версії-й-сумісність)).
Що можна розширювати без JavaScript (Entity, дії, метрики, події), описано в [Розширення: огляд](10-extending.md).
Версія 1.6 додала права запису для полів (`authorizedTo()`), поля розширень у модальних вікнах дій
(`inAction()`, `actionValue()`, prop `action`) і публічний `Files\FileSafety`; розширення, що ними
користується, декларує `requires: '^1.6'`.

## Поняття

| Що | PHP | Vue (у `defineExtension()`) |
| --- | --- | --- |
| Поле | спадкоємець `Maxcxam\Larsadmin\Fields\Field` з `$component = 'acme/rating'` | `fields: { 'acme/rating': { form, display } }` |
| Статична картка | спадкоємець `Maxcxam\Larsadmin\Metrics\Card` | `cards: { 'acme/welcome': Welcome }` |
| Картка з даними | спадкоємець `Maxcxam\Larsadmin\Metrics\DataCard` | `cards: { 'acme/top-rated': TopRated }` + `useCardData()` |
| Фільтр | спадкоємець `Maxcxam\Larsadmin\Filters\Filter` з `$component = 'acme/stars'` | `filters: { 'acme/stars': StarsFilter }` |
| Сторінка (tool) | спадкоємець `Maxcxam\Larsadmin\Tools\Tool` | `tools: { 'acme/backups': BackupsPage }` |
| Перевизначення | (нічого) | `fields: { 'date-field': { form: Picker, override: true } }` |

Сервер і клієнт зіставляються **ключем компонента**. Ключі розширень мають вигляд `vendor/name`
(`^[a-z0-9-]+\/[a-z0-9-]+$`), ключі без `/` належать панелі. Префікс `larsadmin/` зарезервовано для
першосторонніх пакетів (наприклад, демо-розширення в sandbox), `app/` для розширення всередині
застосунку.

Як це працює: панель збирає Vue, Inertia і свій SDK в окремі модулі й додає на сторінку
[import map](https://developer.mozilla.org/docs/Web/HTML/Element/script/type/importmap), який зіставляє
specifier-и `vue`, `@inertiajs/vue3` і `larsadmin` з цими модулями. Розширення збирається як ES-модуль, у
якому ці імпорти лишаються зовнішніми. Тому панель і всі розширення працюють з **одним** екземпляром Vue
та Inertia: `ref`, `provide/inject` і `usePage()` у розширенні бачать ту саму сторінку, що й панель.

### Модель довіри

Встановлене розширення є **довіреним кодом**. Його PHP і так виконується з повними правами застосунку, а
його JavaScript виконується на сторінках панелі з правами поточного адміністратора: читає props сторінки й
може робити будь-які запити, які може робити користувач. **Встановлюйте лише розширення, яким довіряєте,
як і будь-який інший пакет.**

Межа безпеки лишається на сервері: усі дані й дії авторизує сервер (policies, `canSee()`, скоупінг
`indexQuery()`/`detailQuery()`, валідація). JavaScript розширення не може обійти ці перевірки більше, ніж
користувач у DevTools. Звідси головне правило для автора: **кожен маршрут розширення, що працює із
записом, авторизує його на сервері** через [`Larsadmin::findRecord()`](#findrecord).

Код розширень отримує лише користувач, який пройшов гейт панелі (`viewLarsadmin`). Гість і сторінка
входу не завантажують жодного файлу розширень.

Що ще робить панель:

- віддаючи файл розширення, перевіряє вже **розв'язаний** шлях (`realpath`), а не лише запитаний, тож
  обхідні імена (наприклад, короткі імена Windows 8.3 на кшталт `VITE~1`) не відкривають dotfiles і
  файли поза дозволеними;
- генератори (`larsadmin:extension`, `:field`, `:card`, `:tool`, а з 1.6 усі команди `larsadmin:*`, що
  створюють класи, і MCP-інструмент `generate`) перевіряють увесь план до першого запису й відмовляються
  писати поза коренем застосунку, якщо на шляху під ним є будь-яке символічне посилання чи junction
  (зокрема «висяче») або сегмент лише з крапок чи з крапкою в кінці; каталог пакета розширення не може бути
  в `vendor`, `node_modules`, `storage`, `bootstrap`, `public` чи теці, що починається з крапки (без
  урахування регістру), а `generate` створює розширення лише під `packages/` чи `extensions/`;
- `useJsonRequest()` додає CSRF-токен лише до запитів на той самий origin.

Розширення, що зберігає чи віддає файли, користується публічним `Maxcxam\Larsadmin\Files\FileSafety` (з 1.6):
ті самі перевірки завантажень і заголовки відповіді, що й у вбудованих `File`/`Image`
([Файли й зображення](15-files.md#filesafety-для-розширень)). Не копіюйте внутрішній код безпеки панелі;
якщо копія все ж неминуча, покрийте її **тестами паритету** з оригіналом, щоб вона не розійшлася з ним
після оновлення панелі.

## Швидкий старт

Скелет пакета з робочим прикладом поля й картки створює artisan-команда:

```bash
php artisan larsadmin:extension acme/larsadmin-rating
```

Команда пише пакет у `{--path}/{vendor}/{package}`, тобто `packages/acme/larsadmin-rating/`, і друкує
наступні кроки:

```bash
composer config repositories.acme-larsadmin-rating path packages/acme/larsadmin-rating
composer require acme/larsadmin-rating:@dev
cd packages/acme/larsadmin-rating && npm install && npm run build
```

Провайдер скелета реєструє розширення, лише коли існує `dist/`, тож після збірки воно зареєстроване. Додайте приклад поля в Entity і картку в дашборд:

```php
use Acme\LarsadminRating\Cards\ExampleCard;
use Acme\LarsadminRating\Fields\ExampleField;

ExampleField::make('Example field', 'example_field'), // у fields() Entity
ExampleCard::make(),                                  // у cards() дашборда чи Entity
```

Готово: форма показує поле розширення, а дашборд картку з даними з її ендпоінта.

Опції команди:

| Опція | За замовчуванням | Призначення |
| --- | --- | --- |
| `--path=` | `packages` | каталог відносно кореня застосунку; пакет потрапляє в `PATH/VENDOR/PACKAGE` |
| `--field=` | `ExampleField` | клас прикладу поля; ключ `{vendor}/example-field` |
| `--card=` | `ExampleCard` | клас прикладу `DataCard`; ключ `{vendor}/example-card` |
| `--force` | | перезаписати наявні файли |

Окремі частини додають команди `larsadmin:field`, `larsadmin:card` і `larsadmin:tool` з опцією
`--extension=` (каталог пакета відносно кореня застосунку):

```bash
php artisan larsadmin:field Rating --extension=packages/acme/larsadmin-rating
php artisan larsadmin:card TopRated --data --extension=packages/acme/larsadmin-rating
php artisan larsadmin:tool Backups --extension=packages/acme/larsadmin-rating
```

| Команда | Створює |
| --- | --- |
| `larsadmin:field {name}` | `src/Fields/{name}.php`, `resources/js/fields/{name}Input.vue`, `{name}Display.vue` |
| `larsadmin:card {name} [--data]` | `src/Cards/{name}.php` (`Card`, з `--data` `DataCard`), `resources/js/cards/{name}Card.vue` |
| `larsadmin:tool {name}` | `src/Tools/{name}.php`, `resources/js/tools/{name}Page.vue` |

Ключ компонента береться з vendor пакета: `acme/rating`, `acme/top-rated`, `acme/backups`. Команди **не
редагують** `extension.js`, а друкують рядки для `defineExtension()` (і для tool рядок
`Larsadmin::tools([...])` для провайдера). Без `--extension` клас потрапляє в `app/Larsadmin/{Fields,Cards,Tools}`,
Vue-файли в `resources/larsadmin/js/...`, а ключ має вигляд `app/rating` (див.
[Розширення в застосунку](#розширення-в-застосунку)).

> **Розробка панелі з гілки.** Скелет вимагає `maxcxam/larsadmin: ^1.6`. Якщо панель встановлена з
> гілки чи path-репозиторію, Composer бачить її як `dev-…`: гілка `main` має `branch-alias`
> `dev-main → 1.x-dev`, а для path-репозиторію задайте `"options": {"versions": {"maxcxam/larsadmin": "1.6.x-dev"}}`.
> Команда попереджає про це й пропонує `composer require … :@dev -W`.

## Структура пакета

```
packages/acme/larsadmin-rating/
├── composer.json            # require maxcxam/larsadmin ^1.6; dev: orchestra/testbench, pestphp/pest
├── package.json             # dev: vite, @vitejs/plugin-vue, vue, @inertiajs/vue3, tailwindcss,
│                            #      @tailwindcss/vite, vitest, jsdom, @vue/test-utils
├── vite.config.js           # plugins: [vue(), tailwindcss(), larsadmin()]
├── src/
│   ├── RatingServiceProvider.php
│   ├── Fields/ExampleField.php
│   └── Cards/ExampleCard.php
├── resources/
│   ├── js/
│   │   ├── extension.js                 # export default defineExtension({...})
│   │   ├── fields/ExampleFieldInput.vue, ExampleFieldDisplay.vue, ExampleFieldInput.test.js
│   │   └── cards/ExampleCard.vue
│   └── css/extension.css                # Tailwind utilities + kit/theme.css
├── lang/{en,uk}/ui.php
├── tests/                               # Pest.php, TestCase.php, Fixtures/, FieldTest, CardTest, HttpTest
├── dist/                                # результат npm run build; комітиться
└── README.md
```

`vite.config.js` скелета шукає kit панелі (`kit/vite.js`, `kit/sdk.js`, `kit/testing.js`, `kit/theme.css`)
спершу у власному `vendor/` пакета (після `composer install` у його каталозі), потім у `vendor/`
застосунку, і задає alias `larsadmin-kit` на знайдений каталог. Kit постачається в Composer-архіві панелі
(`vendor/maxcxam/larsadmin/kit`), окремого npm-пакета немає.

`dist/` комітиться, як і `dist/` самої панелі: хост, що встановлює розширення, не запускає npm.

## Реєстрація: `Larsadmin::extension()`

Розширення реєструється одним викликом у `boot()` свого провайдера:

```php
namespace Acme\LarsadminRating;

use Illuminate\Support\ServiceProvider;
use Maxcxam\Larsadmin\Larsadmin;

class RatingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'acme-rating');

        Larsadmin::extension('acme/larsadmin-rating', __DIR__.'/../dist')
            ->translations('acme-rating');
    }
}
```

`Larsadmin::extension(string $name, string $path): Extension` приймає назву Composer-пакета й
**абсолютний** шлях до каталогу зі зібраними файлами. За замовчуванням панель завантажує
`dist/extension.js` і, якщо він є, `dist/extension.css`. Виклик кидає `InvalidArgumentException`, якщо:

- `$name` не є коректною назвою Composer-пакета (`vendor/package`);
- `$path` порожній, відносний, не існує чи сам є символічним посиланням (junction у Windows);
- ця назва вже зареєстрована з іншим каталогом.

Повторний виклик з тією самою назвою й каталогом повертає той самий об'єкт (провайдери можуть
завантажуватися повторно в Octane й тестах). Окремого перемикача «вимкнути розширення» немає:
реєстрація і є ввімкненням, а видаляється розширення з Composer.

Fluent-методи `Maxcxam\Larsadmin\Extensions\Extension`:

| Метод | Призначення |
| --- | --- |
| `script(string $file = 'extension.js')` | entry-модуль відносно `$path` |
| `style(?string $file = 'extension.css')` | стилі; `null` вимикає, відсутній файл пропускається |
| `translations(string $namespace, string $group = 'ui')` | віддати `trans("{$namespace}::{$group}")` у JavaScript (див. [Переклади](#переклади)) |
| `version(): string` | 12 символів md5 від `.vite/manifest.json` (або від entry-скрипта й стилів); стоїть в URL файлів |
| `url(string $file): string` | версійований URL файлу з `$path` |
| `$name`, `$path` | назва й `realpath` каталогу (readonly) |

Також є `Larsadmin::registeredExtensions()` (усі, у порядку реєстрації) і `Larsadmin::extensionFor($name)`.

Файли віддає маршрут панелі `GET {path}/extensions/{vendor}/{package}/{version}/{file}` прямо з
зареєстрованого каталогу, без публікації в `public/`. Версія стоїть у шляху, тож відносні імпорти чанків
розширення її теж отримують, а браузер кешує файли на рік (`immutable`). Коли версія змінюється
(`composer update`), змінюється й Inertia-версія панелі: відкриті вкладки перезавантажуються під час
наступної навігації. Деталі в [HTTP-ендпоінтах](#http-ендпоінти).

### Розширення в застосунку

Той самий механізм працює без пакета. Застосунок реєструє каталог у своєму
`app/Providers/LarsadminServiceProvider.php`:

```php
public function boot(): void
{
    parent::boot();

    Larsadmin::extension('app/admin', resource_path('larsadmin/dist'));
}
```

і збирає `resources/larsadmin/js/extension.js` окремим конфігом Vite з тим самим пресетом:

```js
// vite.larsadmin.config.js; збірка: npx vite build -c vite.larsadmin.config.js
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import larsadmin from './vendor/maxcxam/larsadmin/kit/vite.js'

export default defineConfig({
    plugins: [vue(), larsadmin({ entry: 'resources/larsadmin/js/extension.js', outDir: 'resources/larsadmin/dist' })],
})
```

`larsadmin:field`, `larsadmin:card` і `larsadmin:tool` без `--extension` створюють файли саме тут, з
ключами `app/…`. `name` у `defineExtension()` має бути `'app/admin'`.

## `defineExtension()` і `requires`

Entry-модуль розширення має **default export** з результатом `defineExtension()`:

```js
import { defineExtension } from 'larsadmin'
import '../css/extension.css'
import RatingInput from './fields/RatingInput.vue'
import RatingDisplay from './fields/RatingDisplay.vue'
import TopRatedCard from './cards/TopRatedCard.vue'
import StarsFilter from './filters/StarsFilter.vue'
import BackupsPage from './tools/BackupsPage.vue'

export default defineExtension({
    name: 'acme/larsadmin-rating', // те саме, що в Larsadmin::extension()
    requires: '^1.6',              // діапазон API панелі
    fields: {
        'acme/rating': { form: RatingInput, display: RatingDisplay },
    },
    cards: { 'acme/top-rated': TopRatedCard },
    filters: { 'acme/stars': StarsFilter },
    tools: { 'acme/backups': BackupsPage },
    setup({ app }) {
        // необов'язково: app.component(), app.directive(), плагіни Vue
    },
})
```

- `defineExtension()` лише перевіряє форму об'єкта (помилка: `TypeError` з назвою розширення) і
  позначає його. Реєструє компоненти завантажувач панелі.
- `name` має збігатися з назвою, переданою в `Larsadmin::extension()`, інакше розширення не
  завантажується.
- `requires` підтримує лише форму `^MAJOR.MINOR`. Вона задоволена, коли major збігається з
  `apiVersion` панелі, а minor панелі не менший. `'^1.6'` працює з API `1.6`, `1.7`, … і не працює з
  `1.5` чи `2.0`. Розширення, що не користується API 1.6, може лишити `'^1.5'`.
  Несумісне розширення не завантажується, а його поля [fail closed](#недоступні-й-зламані-розширення).
- Частини поля: `form` (форми створення й редагування), `display` (index і detail), необов'язкові
  `index` і `detail` (замінюють `display` у своєму контексті) і `panel` (окремий блок detail-сторінки,
  див. [Відображення](#vue-відображення)).
- `setup({ app })` викликається один раз, коли Vue-застосунок уже створено. Помилка в `setup` позначає
  розширення як незавантажене й знімає всі його ключі.
- Компоненти можна завантажувати ліниво: `defineAsyncComponent(() => import('./Heavy.vue'))`. Чанки
  розширення завантажуються відносно його URL.
- Ключ без `/` чи зайнятий іншим розширенням відхиляється з `console.error`, решта розширення працює.
  Перший зареєстрований ключ виграє.
- Вбудований ключ без `override: true` відхиляється з `console.warn` (див. [Перевизначення](#перевизначення-вбудованих-компонентів)).

Без збірки розширення можна написати лише render-функціями (`h()`): панель постачає Vue без компілятора
шаблонів.

## Власні поля

### PHP-клас

Власне поле є спадкоємцем `Field` (або `Text`, `Number`, …) з ключем розширення в `$component`. Робочий
приклад з демо-розширення sandbox:

```php
namespace Acme\LarsadminRating\Fields;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\Field;

class Rating extends Field
{
    public string $component = 'acme/rating';

    protected int $max = 5;

    public function max(int $max): static
    {
        $this->max = $max;

        return $this;
    }

    protected function defaultAlign(): string
    {
        return 'center';
    }

    protected function typeRules(): array
    {
        return ['integer', 'between:1,'.$this->max];
    }

    protected function resolveValue(mixed $value): mixed
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected function fillValue(Request $request): mixed
    {
        $value = $request->input($this->attribute);

        return is_numeric($value) ? (int) $value : null;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'max' => $this->max];
    }
}
```

```php
Rating::make('Рейтинг', 'rating')->max(5)->nullable()->sortable(),
```

Стабільні члени `Field` для спадкоємців:

| Член | Призначення |
| --- | --- |
| `public string $component` | ключ Vue-компонента; для розширень обов'язково `vendor/name` |
| `jsonSerialize(): array` | `[...parent::jsonSerialize(), 'max' => …]`; ключі спадкоємця перемагають базові |
| `withMeta(array $meta)` | довільні props без перевизначення `jsonSerialize()` |
| `$multipart`, `multipart(bool $multipart = true)` | поле надсилає файли (див. [нижче](#поля-що-надсилають-файли)) |
| `resolveValue()`, `formatForDisplay()`, `fillValue()`, `fillString()`, `typeRules()`, `implicitRules()`, `acceptsNullInput()`, `defaultAlign()` | захищені хуки |
| `fill(Request, Model): ?Closure` | заповнення; повернуте замикання виконується після `save()` (для пов'язаних даних) |
| `fillInto(Request, Model, FileJournal): ?Closure` | заповнення з журналом файлів збереження |
| `fillAttribute()`, `resolve()`, `eagerLoads()`, `applySort()` | публічні хуки |
| `bindTo(Request, string $entity)` | поле дізнається свій Entity |
| `authorizedTo(string $ability): bool` | з 1.6: чи може користувач `view`/`update`/`delete` запис, для якого поле розв'язується ([Поля](03-fields.md#права-на-запис-authorizedto)) |
| `inAction(): bool` | з 1.6: поле належить `fields()` дії |
| `actionValue(Request): mixed` | з 1.6: хук, значення поля для `ActionFields` дії ([Дії](07-actions.md#поля-розширень-у-модальному-вікні)) |
| `hasAuditValue()`, `auditValue(Model)` | аудит поля без колонки |
| `__construct(string $name, ?string $attribute = null, ?callable $resolveCallback = null)`, `resolveDefault()`, `rulesFor()`, `validationAttributeNames()`, `isRequired()`, `sortable()` | хуки, які можна перевизначати (наприклад, поле без колонки забороняє `sortable()`) |

Перевизначати можна лише члени з цієї таблиці. Перевизначення будь-якого іншого (`isShownOn()`,
`isReadonlyFor()`, `serialize()`, …) не є API, і [перевірка публічного API](#перевірка-публічного-api) на
ньому падає.

Серіалізовані ключі базового поля: `component`, `name`, `attribute`, `value`, `displayedAs`, `sortable`,
`nullable`, `readonly`, `required`, `help`, `placeholder`, `align`, `wrap`, `truncate`, `fullWidth`,
`multipart` (лише коли `true`) і `inAction` (з 1.6, лише `true` для поля дії). У мінорних версіях можуть з'являтися нові ключі, тож називайте власні
ключі так, щоб вони не збіглися з майбутніми (наприклад, `ratingMax`), а в компонентах ігноруйте невідомі.

Правила, видимість (`showOnIndex()`, `canSee()`, …), `readonly()`, `sensitive()` та інші методи
працюють так само, як для вбудованих полів ([Поля](03-fields.md)).

### Vue: форма

Компонент форми малює **лише контрол**. Label, позначку «обов'язкове», help і текст помилки малює
панель; стан форми, «брудну» форму, скидання й надсилання теж веде панель, тож компонент ніколи не
надсилає форму сам.

| Prop / подія | Тип | Зміст |
| --- | --- | --- |
| `field` | Object | серіалізоване поле: `attribute`, `name`, `value`, `readonly`, `required`, `placeholder`, `help`, власні ключі |
| `modelValue` / `update:modelValue` | any | значення у стані форми; початкове `field.value`. Не змінюйте `field` |
| `invalid` | Boolean | є помилка валідації поля |
| `errors` | Array\<string\> | повідомлення для `attribute` і вкладених ключів `attribute.*` |
| `errorsByKey` | Object | ті самі повідомлення за ключами: `{ 'gallery': '…', 'gallery.upload.1': '…' }` |
| `describedBy` | String \| null | id елемента з помилкою чи help, для `aria-describedby` |
| `context` | `'create'` \| `'update'` | контекст форми; у модальному вікні дії `'create'` |
| `resourceId` | String \| Number \| null | ключ запису під час редагування; у дії ключ запису, якщо дія виконується над одним записом, інакше `null` |
| `action` | Object \| undefined | з 1.6: `{ uriKey, entity }` у модальному вікні дії ([Дії](07-actions.md#поля-розширень-у-модальному-вікні)); форма запису його не передає |

Вимоги: кореневий фокусований елемент має `:id="field.attribute"` (на нього вказує `for` label-а) і
поважає `field.readonly`.

```vue
<script setup>
defineProps({
    field: { type: Object, required: true },
    modelValue: { default: null },
    invalid: { type: Boolean, default: false },
    errors: { type: Array, default: () => [] },
    describedBy: { type: String, default: null },
    context: { type: String, default: 'update' },
    resourceId: { type: [String, Number], default: null },
})

defineEmits(['update:modelValue'])
</script>

<template>
    <input
        :id="field.attribute"
        type="text"
        class="larsadmin-input"
        :class="{ 'larsadmin-input-invalid': invalid }"
        :value="modelValue ?? ''"
        :placeholder="field.placeholder ?? undefined"
        :readonly="field.readonly"
        :aria-invalid="invalid ? 'true' : undefined"
        :aria-describedby="describedBy ?? undefined"
        @input="$emit('update:modelValue', $event.target.value)"
    />
</template>
```

Сервер валідує значення як завжди (правила поля), тож перевірка на клієнті лише для зручності.

### Vue: відображення

Компоненти `display`, `index` і `detail` отримують:

| Prop | Зміст |
| --- | --- |
| `field` | серіалізоване поле з `value` і `displayedAs` (у таблиці `displayedAs` уже обрізаний за `truncate`) |
| `context` | `'index'` \| `'detail'` |

`index` і `detail` необов'язкові: без них в обох контекстах працює `display`.

Частина `panel` малює окремий блок під полями detail-сторінки для поля, серіалізованого з `panel: true`
(наприклад, `->withMeta(['panel' => true])`). Компонент отримує `field`, `entity` (`{ uriKey }`) і
`record` (`{ id }`), як вбудована панель зв'язків.

### Поля, що надсилають файли

Поле, яке завантажує файли, вмикає `multipart`:

```php
class Gallery extends Field
{
    public string $component = 'acme/gallery';

    public bool $multipart = true; // або ->multipart() на екземплярі
}
```

Поле серіалізується з `multipart: true`, і форма надсилається як `multipart/form-data` (оновлення як
`POST` з `_method=PUT`), так само, як форма з полем `File`. Значення поля надсилається як є: Inertia
перетворює об'єкти з `File` на записи `FormData`. Тобто компонент емітить, наприклад,
`{ present: 1, upload: [file1, file2] }`, а сервер читає `$request->file("{$attribute}.upload")`.

Обмеження multipart: порожній масив не передається зовсім. Якщо значення вашого поля масив, надсилайте
разом з ним прапорець присутності (як `present` вище) або вважайте відсутній ключ порожнім масивом
(див. [Розширення: огляд](10-extending.md#власні-поля-з-масивом-і-форми-з-файлами)).

`fileField: true` є внутрішнім ключем, який надсилають `File`, `Image` та їхні спадкоємці: за ним форма
перетворює значення на формат вбудованих полів файлів (`{ present, known, keep, upload }`). Власні поля не
повинні його задавати. Поле розширення, чиє значення лише схоже на цей формат, надсилається без змін.

Помилки вкладених ключів (`gallery.upload.1`) приходять у `errors` і `errorsByKey`; панель показує під
полем першу з них.

Поле з `multipart: true` у `fields()` дії не підтримується (`LogicException`, як для `File`/`Image`):
дія заповнює тимчасову модель, і завантаження лишилися б без власника.

### Хуки збереження: `fillInto()` і `FileJournal`

Диски не транзакційні. Щоб файли, які записало поле, не лишалися після невдалого збереження,
перевизначте `fillInto()` замість `fill()` і реєструйте файли в журналі збереження:

```php
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Files\FileJournal;

public function fillInto(Request $request, Model $record, FileJournal $journal): ?Closure
{
    $uploads = $request->file("{$this->attribute}.upload", []);

    return function (Model $record) use ($uploads, $journal) {
        foreach ($uploads as $file) {
            $path = $file->store("gallery/{$record->getKey()}", 'public');

            // Видаляється, якщо збереження завершиться помилкою чи rollback-ом.
            $journal->created('public', $path);
        }

        foreach ($record->galleryImages()->whereIn('id', $this->removedIds())->get() as $image) {
            $image->delete();

            // Видаляється лише після коміту зовнішньої транзакції.
            $journal->obsolete('public', $image->path);
        }
    };
}
```

- `Entity::save()` викликає `fillInto()` для кожного поля. За замовчуванням `fillInto()` викликає
  `fill()`, тож наявні поля працюють як раніше.
- `$journal->created(string $disk, string $path)`: файл, який записало це збереження. Видаляється, якщо
  збереження не вдалося, зокрема коли помилка сталася у відкладеному замиканні.
- `$journal->obsolete(string $disk, string $path)`: файл, який збереження зробило зайвим. Видаляється
  після коміту.
- Решта `FileJournal` (створення, `discardCreated()`, очищення) внутрішня.
- Журнал є лише в збереженні форми. Маршрути розширення керують файлами самі.

Повний приклад: поле `Media` доповнення
[`maxcxam/larsadmin-medialibrary`](https://github.com/maxcxam/larsadmin-medialibrary) (випущене як 1.0, код у
`packages/larsadmin-medialibrary` монорепо) реєструє через журнал файли медіа, їхні перетворення й відповідальні
зображення та файли видалених медіа. Це повне розширення лише на API цієї сторінки: multipart-поле, `fillInto()`,
`bindTo()`, `authorizedTo()`, значення аудиту, маршрути розширення з `findRecord()`, `findField()` і `audited()`,
та `FileSafety`.

### Аудит: `hasAuditValue()` і `auditValue()`

Журнал аудиту записує значення колонок моделі. Поле без колонки (медіа, теги в іншій таблиці, …) потрапляє
в аудит, якщо повертає своє значення само:

```php
public function hasAuditValue(): bool
{
    return true;
}

public function auditValue(Model $record): mixed
{
    // Свіжий запит, не кешований зв'язок: значення читається до й після збереження.
    return $record->tags()->orderBy('name')->pluck('name')->all();
}
```

`auditValue()` має повертати значення, придатне для `json_encode`, і бути дешевим: воно виконується двічі
на кожне збереження з увімкненим аудитом. `sensitive()` маскує його як завжди. Вбудовані поля цих хуків
не перевизначають, тож їхній аудит не змінився. Зміни з маршрутів розширення аудитуються через
[`Larsadmin::audited()`](#audited).

### Прив'язка до Entity: `bindTo()`

`bindTo(Request $request, string $entity)` викликається один раз, коли Entity будує свої поля. Поле, якому
потрібен свій Entity (щоб будувати URL маршрутів розширення чи перевіряти policy), запам'ятовує його:

```php
protected ?string $entity = null;

public function bindTo(Request $request, string $entity): void
{
    $this->entity = $entity; // class-string<Entity>
}
```

Права запису (`view`, `update`, `delete`) поле отримує через `authorizedTo()` (з 1.6) без власних
викликів policy: це відповіді, які панель уже обчислила для рядка ([Поля](03-fields.md#права-на-запис-authorizedto)).

### Поле в модальному вікні дії

З 1.6 поля розширень працюють у `fields()` дії. Поле знає про це з `inAction()` (його `context` лишається
`create`), а значення для `ActionFields` дає хук `actionValue(Request $request)`: за замовчуванням це те, що
`fill()` пише в `fillAttribute()` тимчасової моделі. Перевизначте його, якщо `fill()` пише значення
інакше; для фонових дій значення має переживати JSON. Компонент форми отримує додатковий prop `action`.
Докладно: [Дії: поля розширень у модальному вікні](07-actions.md#поля-розширень-у-модальному-вікні).

## Картки

### Статична картка

Статична картка не має даних з сервера, лише props:

```php
namespace Acme\LarsadminRating\Cards;

use Maxcxam\Larsadmin\Metrics\Card;

class Welcome extends Card
{
    public string $component = 'acme/welcome';

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'message' => __('acme-rating::ui.welcome')];
    }
}
```

```php
Welcome::make()->width('1/2')->help('…')->withMeta(['links' => [...]]),
```

Стабільні члени `Card`: `$component`, `$width`, `$meta`, `name()`, `uriKey()`, `width()`, `help()`,
`canSee()`, `withMeta(array $meta)` (додає ключі після базових; базовий ключ `component`, `uriKey`, `name`,
`width`, `helpText` чи `endpoint` кидає `InvalidArgumentException`) і `jsonSerialize()`. Картка отримує в
Vue prop `card` з ключами `component`, `uriKey`, `name`, `width`, `helpText`, `endpoint` і власними.
Картки реєструються в `cards()` дашборда чи Entity, як метрики ([Дашборди](14-dashboards.md)).

```vue
<script setup>
import { CardShell } from 'larsadmin'

defineProps({ card: { type: Object, required: true } })
</script>

<template>
    <CardShell :card="card">
        <p class="px-4 pb-4 text-sm">{{ card.message }}</p>
    </CardShell>
</template>
```

### `DataCard`

`Maxcxam\Larsadmin\Metrics\DataCard` завантажує дані з власного JSON-ендпоінта картки. Це той самий
ендпоінт і та сама авторизація, що в метрик: гейт панелі, видимість дашборда (або `viewAny` Entity для
карток над списком), `canSee()` картки, ліміт `larsadmin-metrics`.

```php
namespace Acme\LarsadminRating\Cards;

use App\Larsadmin\Post;
use Maxcxam\Larsadmin\Metrics\CardRequest;
use Maxcxam\Larsadmin\Metrics\DataCard;

class TopRated extends DataCard
{
    public string $component = 'acme/top-rated';

    public string $width = '1/2';

    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }

    public function data(CardRequest $request): mixed
    {
        return [
            'items' => $this->entityQuery($request, Post::class, fn ($query) => $query->whereNotNull('rating'))
                ->orderByDesc('rating')
                ->limit((int) $request->get('limit', 5))
                ->get(['id', 'title', 'rating'])
                ->all(),
        ];
    }
}
```

| Метод | Призначення |
| --- | --- |
| `rules(): array` | правила валідації query-параметрів; невалідні дають 422, **усі інші параметри відкидаються** |
| `data(CardRequest $request): mixed` | дані картки, придатні для `json_encode` |
| `cacheFor()` | строк кешу (`int` секунд, `DateInterval`, `DateTimeInterface`); `null` (за замовчуванням) вимикає |
| `cacheKeyParts(CardRequest $request): array` | додаткові частини ключа кешу |
| `entityQuery(CardRequest $request, string $entity, ?Closure $constraint = null): Builder` | захищений: запит Entity зі скоупом |

Ендпоінт відповідає `200 {"data": …}`. Сирий `$request->request->query()` у `data()` не
використовуйте: валідовані параметри є лише в `CardRequest`.

`Maxcxam\Larsadmin\Metrics\CardRequest` (readonly):

| Член | Зміст |
| --- | --- |
| `$request` | поточний `Illuminate\Http\Request` |
| `$parameters` | лише провалідовані ключі з `rules()` |
| `$timezone` | часовий пояс панелі |
| `$now` | поточний момент (`CarbonImmutable`) у цьому поясі |
| `$owner` | `dashboard:{key}` або `entity:{key}` |
| `user()` | користувач guard-а панелі |
| `get(string $key, mixed $default = null)` | провалідований параметр (dot-нотація) |

`CardRequest` будує панель (конструктор внутрішній); у тестах користуйтеся `cardData()`. Методи
`DataCard::cardRequest()`, `resolve()` і `cacheKey()` внутрішні, а `resolve()` і `cacheKey()` ще й `final`:
не називайте так власні методи картки.

### `entityQuery()`

`entityQuery()` є відповідником `Metric::scoped()` для карток з даними: повертає запит Entity, обмежений
його `indexQuery()`, після перевірки `viewAny`. Без `viewAny` кидає `AuthorizationException`, і
ендпоінт відповідає 403. `$entity` приймає лише **зареєстрований** Entity: його клас або `uriKey`; інше
кидає `InvalidArgumentException`. Обмеження
`$constraint` загортається в окрему групу `where`, тож `orWhere` усередині не виходить за межі скоупу.

`data()` без `entityQuery()` бачить усе, що може прочитати запит: моделлю чи query builder-ом користуйтеся
лише для глобальних даних і ховайте таку картку через `canSee()`.

### Кешування

```php
public function cacheFor(): int
{
    return 300;
}

public function cacheKeyParts(CardRequest $request): array
{
    return [$request->user()?->current_team_id];
}
```

Ключ кешу містить власника (дашборд чи Entity), клас і `uriKey` картки, провалідовані параметри, часовий
пояс, мову, guard і **користувача** (клас + id), результат `Larsadmin::metricCacheScopeUsing()` і
`cacheKeyParts()`. Сховище: `larsadmin.metrics.cache_store`.

При попаданні в кеш `data()` не викликається, а повторно виконуються авторизація власника (дашборд чи
`viewAny` Entity), `canSee()` картки і `viewAny` кожного Entity, з якого дані прочитано через
`entityQuery()`: користувач, у якого це право відкликали, отримує 403 і на закешованих даних. Інших
джерел (модель, query builder, зовнішній API) картка не знає. Тому:

- якщо `data()` залежить від чогось, крім користувача й параметрів (тенант, команда, роль, сесія),
  поверніть це з `cacheKeyParts()` або з глобального `metricCacheScopeUsing()`, інакше користувач
  отримає дані чужої області;
- власні перевірки доступу всередині `data()` на закешованих даних не спрацюють, переносьте їх у
  `canSee()`.

Колбек `metricCacheScopeUsing()` отримує картку другим аргументом. Якщо цей параметр типізовано лише як
`Metric`, колбек картку отримати не може, і така картка **ніколи не кешується** (fail closed). Типізуйте
параметр як `Metric|DataCard`, `Card` чи `mixed`, якщо хочете кешувати картки з даними.

### Vue: `useCardData()` і `CardShell`

```vue
<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { CardShell, useCardData, useI18n, useLarsadmin } from 'larsadmin'

const props = defineProps({ card: { type: Object, required: true } })

const { t } = useI18n()
const { entityUrl } = useLarsadmin()
const { status, data, reload } = useCardData(() => props.card, { limit: 5 })

const items = computed(() => data.value?.items ?? [])
</script>

<template>
    <CardShell :card="card" :status="status" :ready="data !== null" :empty="items.length === 0" @retry="reload()">
        <ol class="divide-y divide-muted px-4 pb-4 text-sm">
            <li v-for="item in items" :key="item.id" class="py-2">
                <Link :href="entityUrl('posts', item.id)" class="text-accent-fg hover:underline">{{ item.title }}</Link>
            </li>
        </ol>
    </CardShell>
</template>
```

`useCardData(card, params = {})` робить `GET card.endpoint` з `params` у query і повертає
`{ status, data, reload }`. `card` і `params` можуть бути значенням, ref-ом або getter-ом; ref чи getter
`params` відстежується, і дані перезавантажуються при зміні. `status` дорівнює `idle`, `loading`,
`error` або `forbidden`; `data` тримає останнє вдале значення під час перезавантаження і стає `null` після
помилки.

`CardShell` є оболонкою картки, як у вбудованих метрик: заголовок, tooltip з `help()`, skeleton,
стани «немає доступу» й «помилка» з кнопкою «Повторити» (подія `retry`).

| Prop / слот | Зміст |
| --- | --- |
| `card` | серіалізована картка (обов'язковий) |
| `status` | статус з `useCardData()` / `useJsonResource()` |
| `ready` | є що показати (поки `false` під час першого завантаження, видно skeleton) |
| `empty` | показати стан «немає даних» замість слота |
| `skeletonClass` | клас skeleton-а, за замовчуванням `h-24` |
| слоти `default`, `actions` | вміст і елементи в шапці (наприклад, вибір періоду) |

## Фільтри

Фільтр розширення є звичайним спадкоємцем `Filter` ([Фільтри](06-filters.md)) з ключем розширення в
`$component`; серверна логіка (`apply()`, `normalize()`, `options()`) не змінюється.

```php
class StarsFilter extends Filter
{
    public string $component = 'acme/stars';
    // apply(), normalize(), options() як у будь-якого фільтра
}
```

Vue-компонент отримує `filter` (`key`, `label`, `component`, `options` для фільтрів з варіантами,
`value`, `default`) і `modelValue` / `update:modelValue`. Власні props додає стабільний хук
`serialize(Request $request, mixed $value): array`; результат має містити всі базові ключі
`parent::serialize()`:

```php
public function serialize(Request $request, mixed $value): array
{
    return [...parent::serialize($request, $value), 'max' => 5];
}
```
 Значення потрапляє в query-рядок, тож це лише
скаляр або плоский об'єкт рядків. Сервер нормалізує його через `normalize()`.

## Tools: сторінки в меню

Tool є сторінкою в бічному меню (розділ «Інструменти») з власним Vue-компонентом.

```php
namespace Acme\LarsadminRating\Tools;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Tools\Tool;

class Backups extends Tool
{
    public string $component = 'acme/backups';

    public function icon(): ?string
    {
        return 'database';
    }

    public function props(Request $request): array
    {
        return ['lastRun' => cache('backups.last_run')];
    }
}
```

```php
// boot() провайдера розширення чи застосунку
Larsadmin::tools([
    Backups::class,
    // Backups::make()->canSee(fn (Request $request) => $request->user()->isAdmin()),
]);
```

| Член | Призначення |
| --- | --- |
| `$component` | ключ компонента розширення, обов'язково `vendor/name` |
| `name()` | назва в меню й заголовок сторінки; за замовчуванням `__()` від назви класу |
| `uriKey()` | ключ в URL; за замовчуванням kebab-case назви класу |
| `icon()` | іконка меню з `Tool::ICONS`; `null` чи невідома назва дає іконку за замовчуванням |
| `canSee(Closure)` / `authorizedToSee(Request)` | видимість; без `canSee()` tool бачать усі, хто пройшов гейт панелі |
| `props(Request $request): array` | props сторінки (JSON) |
| `make(...$args)` | створити екземпляр |

Сторінка: `GET {path}/tools/{uriKey}`. Невідомий чи невидимий tool відповідає 404 (не 403, щоб не
розкривати його існування). `Larsadmin::tools()` кидає `InvalidArgumentException` для об'єкта, що не є
`Tool`, для ключа без `/` і для двох класів з однаковим `uriKey`. Також є `Larsadmin::registeredTools()` і
`Larsadmin::toolForKey($key)` (без перевірки видимості).

Vue-компонент отримує `tool` (`name`, `uriKey`, `component`) і `props` (з `props()`); панель малює його в
своєму layout під заголовком `tool.name`. Дані, яких немає в `props()`, компонент завантажує з
[маршрутів розширення](#маршрути-розширення).

```vue
<script setup>
defineProps({
    tool: { type: Object, required: true },
    props: { type: Object, default: () => ({}) },
})
</script>

<template>
    <div class="larsadmin-box p-4">{{ props.lastRun }}</div>
</template>
```

Іконки (`Tool::ICONS`, у JS `Icon.NAMES`): `alert`, `arrow-down`, `arrow-up`, `bell`, `check`,
`chevron-down`, `chevron-left`, `chevron-right`, `dash`, `database`, `device-desktop`, `download`, `eye`,
`file`, `globe`, `history`, `home`, `kebab`, `lock`, `menu`, `moon`, `pencil`, `plus`, `search`,
`sign-out`, `sun`, `trash`, `upload`, `x`.

## Маршрути розширення

Власні JSON-маршрути реєструються через `Larsadmin::routes()` у провайдері:

```php
use Acme\LarsadminRating\Http\ResetRatingController;
use Illuminate\Support\Facades\Route;
use Maxcxam\Larsadmin\Larsadmin;

Larsadmin::routes(function () {
    Route::post('acme-rating/{entity}/{id}/reset', ResetRatingController::class)->name('acme-rating.reset');
});
```

Маршрути потрапляють у групу `{path}/ext` з іменами `larsadmin.ext.…` і middleware панелі
(`larsadmin.middleware` + `larsadmin.auth_middleware`), тобто `POST /admin/ext/acme-rating/posts/1/reset`
з іменем `larsadmin.ext.acme-rating.reset`. Мутаційні маршрути отримують CSRF від групи `web`. Той самий
об'єкт `Closure` реєструється один раз. З `route:cache` замикання виконуються під час кешування, як
звичайні маршрути пакетів.

**`routes()` гарантує лише гейт панелі.** Решту авторизації (policy, скоупінг, видимість поля) маршрут
робить сам, через помічники нижче.

```php
namespace Acme\LarsadminRating\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Larsadmin;

class ResetRatingController
{
    public function __invoke(Request $request, string $entity, string $id): JsonResponse
    {
        $record = Larsadmin::findRecord($request, $entity, $id, 'update');
        $field = Larsadmin::findField($request, $entity, $record, 'rating');

        Larsadmin::audited($request, $entity, $record, function (Model $record) use ($field) {
            $record->forceFill([$field->attribute => null])->save();
        });

        return response()->json(['ok' => true]);
    }
}
```

З Vue такий маршрут викликається через SDK:

```js
import { useJsonRequest, useLarsadmin } from 'larsadmin'

const { url } = useLarsadmin()
const { send } = useJsonRequest()

const { ok, status, data } = await send('POST', url('ext', 'acme-rating', 'posts', 1, 'reset'))
```

### `findRecord()`

```php
Larsadmin::findRecord(Request $request, string $entity, int|string $id, string $ability = 'view'): Model
```

Знаходить запис так, як це робить панель: `viewAny` Entity (403), скоуп `detailQuery()` (404 для рядків
поза ним), потім ability policy на записі (403). `$entity` є `uriKey` Entity; невідомий дає 404.
`$ability`: `view`, `update` або `delete` (інше кидає `InvalidArgumentException`). Викликайте його в
**кожному** маршруті, що працює із записом.

### `findField()`

```php
Larsadmin::findField(Request $request, string $entity, Model $record, string $attribute, string $context = Field::UPDATE): Field
```

Повертає поле Entity для запису так, як його бачить панель: прив'язане (`bindTo()`), показане в
`$context` цьому користувачу й для цього запису (інакше 404), а для `create`/`update` ще й не
`readonly` (інакше 403). Повертає клон, тож його можна `resolve()`-ити. Викликайте після `findRecord()`.
`$context`: `Field::INDEX`, `DETAIL`, `CREATE` або `UPDATE`. З 1.6 `authorizedTo()` поля відповідає для
цього запису (у `create` завжди `false`); policy питається лише тоді, коли поле викликає `authorizedTo()`.

### `audited()`

```php
Larsadmin::audited(Request $request, string $entity, Model $record, Closure $change): mixed
```

Виконує зміну запису поза формою й записує її в журнал аудиту як `updated`: знімок полів Entity до,
`$change($record)`, знімок після, різниця, маскування як завжди. Усе в одній транзакції; повертає результат
замикання. Запису немає, якщо нічого не змінилося, аудит вимкнено чи Entity не аудитується. Поля з
`hasAuditValue()` теж потрапляють у різницю. Запис, що не є моделлю цього Entity, кидає
`InvalidArgumentException`.

## Переклади

Розширення має `lang/{locale}/ui.php`:

```php
// lang/uk/ui.php
return [
    'stars' => ':count зірка|:count зірки|:count зірок',
    'not_rated' => 'Без оцінки',
];
```

Провайдер реєструє файли стандартно й віддає групу в JavaScript:

```php
$this->loadTranslationsFrom(__DIR__.'/../lang', 'acme-rating');

Larsadmin::extension('acme/larsadmin-rating', __DIR__.'/../dist')
    ->translations('acme-rating'); // група 'ui' за замовчуванням
```

```js
const { t, tc, locale } = useI18n()

t('acme-rating::not_rated')
tc('acme-rating::stars', 3) // "3 зірки"
```

У JavaScript ключ має вигляд `namespace::key` (група не пишеться), вкладені масиви дають
`namespace::group.key`. У PHP (назви класів, правила) переклади звичайні: `__('acme-rating::ui.not_rated')`.
Вбудовані ключі панелі не містять `::`, тож колізій немає. Мови панелі спільні; якщо для мови перекладу
немає, діє fallback-локаль Laravel, а без неї `t()` повертає ключ. Хост може перевизначити переклади в
`lang/vendor/acme-rating` (стандарт Laravel). Скелет містить `uk` і `en`.

## Стилі й теми

Розширення збирає власні utility-класи Tailwind CSS v4 з темою панелі:

```css
/* resources/css/extension.css */
@import "tailwindcss/utilities" layer(utilities.extensions) source(none);
@import "larsadmin-kit/theme.css";
@source "../js";
```

- `layer(utilities.extensions)` кладе класи в підшар шару `utilities` панелі: вони й далі перемагають
  базові стилі та компоненти панелі, але ніколи — її власні utility-класи. Звичайний
  `@import "tailwindcss/utilities";` дає CSS *поза шарами*, і його `.hidden { display: none }` перемагає
  шаровий `md:block` панелі, ховаючи частини її сторінок (так робив скелет до 1.6.5).
- `source(none)` разом з `@source "../js"`: генеруються лише класи компонентів розширення, а не слова з
  `README.md` чи PHP-файлів, схожі на класи.
- Компроміс: utility-клас розширення програє однойменному класу панелі.

`kit/theme.css` імпортує стандартну тему Tailwind як *reference*: класи `p-4`, `size-6`, `text-sm`,
`rounded`, `md:grid-cols-2` генеруються, але змінні теми не дублюються, а беруться з панелі. Кольори
відображено на токени `--la-*`, тож вони самі змінюються зі світлою й темною темою:

| Utility | Токен |
| --- | --- |
| `text-fg`, `text-fg-muted`, `text-fg-subtle`, `text-fg-on-emphasis` | `--la-fg-*` |
| `bg-canvas`, `bg-canvas-subtle`, `bg-canvas-inset` | `--la-canvas-*` |
| `border-default`, `border-muted` | `--la-border-*` |
| `*-accent-fg`, `*-accent-emphasis`, `*-accent-subtle` | `--la-accent-*` |
| `*-success-*`, `*-danger-*` (`fg`, `emphasis`, `subtle`) | `--la-success-*`, `--la-danger-*` |
| `bg-neutral-muted`, `bg-header`, `text-header-fg`, `bg-overlay`, `shadow-overlay` | інші токени |

Стабільний CSS-контракт: токени `--la-*` ([Теми](09-theming.md#токени-дизайну)) і класи
`larsadmin-input`, `larsadmin-input-invalid`, `larsadmin-checkbox`, `larsadmin-btn`,
`larsadmin-btn-primary`, `larsadmin-btn-secondary`, `larsadmin-btn-danger`, `larsadmin-btn-sm`,
`larsadmin-box`, `larsadmin-box-header`, `larsadmin-label`, `larsadmin-label-success`,
`larsadmin-label-accent`, `larsadmin-counter`. Utility-класи з `app.css` панелі контрактом **не є**: їх
набір залежить від коду панелі, тож розширення збирає власні.

Для графіків, які малюють кольори самі, `useTheme().resolved` повертає `'light'` чи `'dark'`
(вибір користувача або налаштування ОС); палітра графіків: `--la-chart-1` … `--la-chart-8`.

## Публічний JS API

### Модуль `larsadmin`

Усі експорти нижче входять у SemVer-контракт 1.x (у мінорах лише додаються):

| Експорт | Опис |
| --- | --- |
| `apiVersion` | рядок `'1.6'` (`Larsadmin::API_VERSION`) |
| `defineExtension(options)` | опис розширення ([вище](#defineextension-і-requires)) |
| `useI18n()` | `{ t, tc, locale }`; ключі розширень `t('acme-rating::key')` |
| `useLarsadmin()` | `{ url(...segments), entityUrl(uriKey, ...segments) }` під шляхом панелі; сегменти кодуються, `.` і `..` заборонені |
| `useJsonRequest()` | `{ send(method, url, body?, { background }) }` → `{ ok, status, data, expired? }`; `X-XSRF-TOKEN` для мутацій лише на URL того самого origin; на 401/419 запит `GET` чи з `background: true` повертає `expired: true`, інший перезавантажує сторінку |
| `useJsonResource()` | `{ status, load(url, { background }), cancel }`; скасовує застарілий запит; `status`: `idle`, `loading`, `error`, `forbidden` |
| `useCardData(card, params?)` | `{ status, data, reload }` для `DataCard` |
| `useToasts()` | `{ push({ message, type, title?, timeout? }), dismiss(id) }`; `type`: `success`, `info`, `warning`, `error`; посилання не підтримуються |
| `useTheme()` | `{ resolved }`: `'light'` \| `'dark'` |
| `useNumberFormat()` | `{ format(value, options) }`: опції `Intl.NumberFormat` плюс `prefix`/`suffix`, мовою панелі; `null` і `NaN` дають «—» |
| `buildUrl(base, segments)`, `withParams(url, params)` | чисті помічники URL |
| `Icon` | `<Icon name="pencil" />`; назви в `Icon.NAMES` (ті самі, що `Tool::ICONS`); декоративна, підпис дає елемент керування |
| `CardShell` | оболонка картки ([вище](#vue-usecarddata-і-cardshell)) |
| `ConfirmDialog` | доступний діалог підтвердження |

Також стабільні specifier-и import map `vue` (Vue 3 runtime, `^3.5`) і `@inertiajs/vue3` (Inertia v3,
той самий екземпляр, що в панелі): `usePage()`, `Link`, `router` у розширенні працюють зі сторінкою панелі.
З shared props контрактом є лише `larsadmin.locale`, `larsadmin.path` і `user`.

**Внутрішнє (не контракт):** усе інше в `resources/js/**` панелі, зокрема `registerField`,
`registerCard`, `registerFilter`, `Combobox`, `EntityForm`, `FieldBlocks` і решта структури shared props.
Імпортувати файли панелі напряму (`vendor/maxcxam/larsadmin/resources/js/...`) не можна: їх немає в
Composer-архіві.

### Kit автора (`vendor/maxcxam/larsadmin/kit`)

| Файл | Експорти |
| --- | --- |
| `kit/vite.js` | `larsadmin(options)` (і default): пресет Vite |
| `kit/testing.js` | `withPanel()`, `mountField()`, `mountDisplay()`, `mountCard()` |
| `kit/theme.css` | тема Tailwind для розширень |
| `kit/sdk.js` | збірка модуля `larsadmin` для vitest (пресет підставляє її сам) |

## Перевизначення вбудованих компонентів

Розширення може замінити **інтерфейс** кількох вбудованих компонентів явним `override: true`:

```js
export default defineExtension({
    name: 'acme/larsadmin-ui',
    requires: '^1.5',
    fields: {
        // лише форма; index і detail лишаються вбудованими
        'boolean-field': { form: BooleanSwitch, override: true },
    },
    cards: {
        'value-metric': { component: BigNumberCard, override: true },
    },
    filters: {
        'date-range-filter': { component: RangePicker, override: true },
    },
})
```

Для полів частини (`form`, `display`, `index`, `detail`) перевизначаються окремо. Картки й фільтри
перевизначаються в об'єктній формі `{ component, override: true }`.

### Що можна перевизначити

| Тип | Ключі | Частини |
| --- | --- | --- |
| Поля | `text-field`, `password-field`, `textarea-field`, `number-field`, `boolean-field`, `select-field`, `date-field`, `datetime-field` | `form`, `display`, `index`, `detail` |
| Фільтри | `select-filter`, `boolean-filter`, `date-range-filter` | компонент |
| Картки | `value-metric`, `trend-metric`, `partition-metric`, `progress-metric` | компонент |

Решта вбудованих ключів (`id-field`, `file-field`, `image-field`, `belongs-to-field`, `has-many-field`,
`belongs-to-many-field`) і частина `panel` не перевизначаються (`console.warn`): їхні формати й
ендпоінти внутрішні. Вбудований ключ без `override: true` теж відхиляється з `console.warn`. Одну частину
ключа може перевизначити лише одне розширення: перше виграє, наступні відхиляються з `console.error`.

### Контракт

Перевизначення отримує ті самі props і події, що й вбудований компонент, плюс загальний контракт
розширень. Усе це стабільне в 1.x:

- **поля, форма:** `field`, `modelValue` / `update:modelValue`, `invalid` і контракт
  [форми](#vue-форма) (`errors`, `errorsByKey`, `describedBy`, `context`, `resourceId`);
  **відображення:** `field`, `context`.
- **ключі `field`:** базові ([вище](#php-клас)) плюс `text-field` / `password-field` `inputType`;
  `textarea-field` `rows`; `number-field` `min`, `max`, `step`; `select-field` `options`
  (`[{ value, label }]`).
- **форма значення** в `update:modelValue` та сама, що у вбудованого: рядок (`text`, `password`,
  `textarea`), число (`number`), `true`/`false` (`boolean`), значення опції (`select`), `YYYY-MM-DD`
  (`date-field`), `YYYY-MM-DDTHH:mm:ss` (`datetime-field`, як `datetime-local`).
- **фільтри:** `filter`, `modelValue` / `update:modelValue`; значення `'1'`, `'0'` чи `null` для
  `boolean-filter`, `{ from, to }` (рядки `YYYY-MM-DD`) чи `null` для `date-range-filter`, значення опції
  для `select-filter`.
- **картки:** `card` з ключами `Card` плюс `ranges` (`[{ value, label }]`) і `defaultRange`. Відповідь
  ендпоінта `{ range, result }`; форма `result` описана в
  [Метриках](13-metrics.md#формат-відповіді-ендпоінта).

Внутрішня розмітка, CSS-класи й дочірні компоненти вбудованих компонентів контрактом не є.

### Безпека й fallback

- Перевизначення змінює **лише клієнтський рендер**. Ключ `component` на сервері, правила, `fill()`,
  `canSee()`, policies, скоупінг і формат значення, який приймає сервер, не змінюються: сервер валідує
  ввід як завжди.
- Поки розширення завантажується, рендериться вбудований компонент (без skeleton).
- Якщо розширення не завантажилося, несумісне чи впав його `setup`, перевизначення не застосовується.
- Якщо компонент-перевизначення падає під час рендера, панель до кінця сесії сторінки повертає цей ключ
  (частину) до вбудованого компонента й пише помилку в консоль. Значення поля **надсилається**, бо його
  обробляє вбудований компонент.
- З `APP_DEBUG=true` після завантаження в консолі видно `console.info` зі списком «хто що перевизначив».

## Недоступні й зламані розширення

Помилки розширень ізольовано, панель працює далі:

- Завантажувач імпортує всі розширення паралельно й чекає на них до 5 секунд, потім монтує панель, а
  пізні розширення домальовуються, коли завантажаться. Реєструються вони в порядку серверного списку
  (порядку `Larsadmin::extension()`), а не в порядку завершення імпорту, тож переможець конфлікту ключів
  не залежить від мережі. Поки хоч одне завантажується, компонент розширення показує skeleton
  «Завантаження розширення…».
- Розширення не завантажується (`console.error` з причиною), якщо імпорт не вдався чи не завершився за
  30 секунд, default export не є `defineExtension()`, `name` не збігається з серверним, `requires`
  несумісний або впав `setup()`.
- `ExtensionBoundary` вимикає компонент розширення до кінця сесії сторінки лише тоді, коли той не може
  відрендеритися: помилка в render, `setup`, хуках життєвого циклу чи завантажувачі асинхронного
  компонента. Помилки в обробниках подій, watcher-ах і асинхронному коді лише пишуться в консоль з назвою
  розширення (з `APP_DEBUG=true` ще й toast): компонент лишається, а введене користувачем значення
  зберігається й надсилається.

Що бачить користувач на місці недоступного компонента `vendor/name`:

| Місце | Placeholder |
| --- | --- |
| форма | рамка «Недоступно: розширення не завантажено» з підказкою «Значення цього поля не буде змінено під час збереження.» |
| index, detail | «—» з підказкою «Недоступно…» |
| картка, tool, panel | блок з назвою й «Недоступно…» |
| фільтр | назва фільтра й «Недоступно…» |

**Fail closed у формі.** Поле з недоступним компонентом розширення (не завантажилося, несумісне,
впало) **не надсилається**: сервер лишає збережене значення, а на створенні спрацює `required`. Ключ без
`/`, невідомий панелі, як і раніше показується текстовим полем (сумісність з 1.4).

**Debug-toast.** Лише з `APP_DEBUG=true` користувач панелі ще й бачить toast «Розширення X не
завантажено» або «Розширення X потребує API ^1.7, а панель надає 1.6». У продакшні є лише консоль і
placeholder-и.

## CSP і nonce

Файли панелі й розширень лежать на тому самому origin, тож для них достатньо `script-src 'self'` і
`style-src 'self'`. Inline-скрипти панелі (визначення теми й import map), тег `app.js` і теги `modulepreload` отримують
атрибут `nonce`, якщо застосунок увімкнув стандартний механізм Laravel `Vite::useCspNonce()`:

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        $response = $next($request);
        $response->headers->set('Content-Security-Policy', "script-src 'self' 'nonce-".Vite::cspNonce()."'");

        return $response;
    }
}
```

Middleware має виконуватися до рендера сторінки панелі (наприклад, у групі `web` або в
`larsadmin.middleware`). Без nonce сувора CSP блокує import map: панель працює, а поля розширень показують
«Недоступно». `'strict-dynamic'` сумісний: модулі, які імпортує довірений `app.js`, дозволені. SRI не
застосовується (той самий origin, ті самі файли деплою). Без зареєстрованих розширень import map на
сторінці немає.

## Версії й сумісність

- `Larsadmin::API_VERSION` (PHP) і `apiVersion` (JS) дорівнюють `'1.6'`: це `MAJOR.MINOR` версії, у
  якій востаннє розширювався публічний API. Мінор без змін API його не піднімає.
- PHP-сумісність гарантує Composer (`"maxcxam/larsadmin": "^1.6"` у `composer.json` розширення;
  `"^1.5"` для розширення без API 1.6). `requires` у JavaScript ловить застарілий `dist/`.
- **Заморожено в межах 1.x:** Vue 3 (≥ 3.5) і Inertia v3, specifier-и import map, контракти компонентів
  цієї сторінки, експорти модуля `larsadmin` і kit, CSS-контракт, PHP-члени зі списку стабільних.
  Перехід на Vue 4 чи Inertia v4 можливий лише в 2.0.
- **Мінори лише додають:** нові props компонентів, нові експорти SDK, нові ключі серіалізації.
  Розширення мають ігнорувати невідомі props і ключі.
- **Депрекації:** член отримує `@deprecated` (PHP) або одноразовий `console.warn` з
  `APP_DEBUG=true` (JS), лишається до кінця major і видаляється в наступному; CHANGELOG має розділ
  «Deprecated» із заміною.

Стабільні PHP-класи й члени:

| Клас | Стабільні члени |
| --- | --- |
| `Larsadmin` | `API_VERSION`, `extension()`, `registeredExtensions()`, `extensionFor()`, `routes()`, `findRecord()`, `findField()`, `audited()`, `tools()`, `registeredTools()`, `toolForKey()`, `assetsVersion()` |
| `Extensions\Extension` | `$name`, `$path`, `script()`, `style()`, `translations()`, `version()`, `url()` |
| `Fields\Field` | див. [PHP-клас поля](#php-клас) |
| `Files\FileJournal` | `created()`, `obsolete()` |
| `Files\FileSafety` | з 1.6: `rule()`, `blockedExtension()`, `storedExtension()`, `sanitizeName()`, `asciiName()`, `isRasterImage()`, `response()` ([Файли й зображення](15-files.md#filesafety-для-розширень)) |
| `Metrics\Card` | `$component`, `$width`, `$meta`, `name()`, `uriKey()`, `width()`, `help()`, `canSee()`, `withMeta()`, `jsonSerialize()` |
| `Metrics\DataCard` | `rules()`, `data()`, `cacheFor()`, `cacheKeyParts()`, `entityQuery()` |
| `Metrics\CardRequest` | `$request`, `$parameters`, `$timezone`, `$now`, `$owner`, `user()`, `get()` |
| `Tools\Tool` | `$component`, `ICONS`, `make()`, `name()`, `uriKey()`, `icon()`, `canSee()`, `authorizedToSee()`, `props()` |
| `Filters\Filter` | `$component`, `make()`, `apply()`, `name()`, `key()`, `options()`, `default()`, `normalize()`, `canSee()`, `serialize()` |
| `Testing\InteractsWithLarsadmin` | `serializeField()`, `fillField()`, `cardData()`, `assertExtensionRegistered()`, `extensionAssetResponse()` |

На решту задокументованих класів панелі (Entity, вбудовані поля, фільтри, метрики, дії, дашборди,
`Layout\Panel`, `Layout\Tabs`, події, `LarsadminNotification`) розширення може посилатися як і застосунок.
Внутрішні члени не є API, навіть якщо вони публічні в PHP: `Entity::fieldPrototypes()`, `isShownOn()`,
`isReadonlyFor()` (для них є `findField()`), конструктор і допоміжні методи `Extension`, конструктор
`CardRequest`, `DataCard::cardRequest()`/`resolve()`/`cacheKey()`, `Extension::NAME_PATTERN`,
`Tool::COMPONENT_PATTERN`, `Tool::$canSee`, `Field::withAbilities()`/`forAction()`, `Files\FileTypes`,
`Files\FileResponse` і все з позначкою `@internal`. З 1.6 внутрішніми позначено також `Larsadmin::locale()`,
`locales()`, `navigation()`, `searchOrder()`, `modelUsesScout()`, `dashboardForKey()`, `home()`,
`dashboardNavigation()`, `metricCacheScope()` і `timezone()`.

### Перевірка публічного API

Повний список стабільної поверхні лежить у `kit/public-api.json`, а `kit/check-public-api.mjs` перевіряє
її (Node ≥ 20, без залежностей). Обидва файли входять у Composer-архів панелі, тож розширення
перевіряється з його каталогу (шляхи відносні до поточного каталогу):

```bash
node vendor/maxcxam/larsadmin/kit/check-public-api.mjs .
```

Скрипт перевіряє дві речі:

- **розширення використовує лише стабільну поверхню.** JS/Vue/CSS імпортує лише `vue`,
  `@inertiajs/vue3`, сторонні пакети, власні файли, експорти `larsadmin` і файли kit (не файли панелі з
  `resources/js` і не підшляхи `larsadmin/...`); PHP посилається лише на класи `Maxcxam\Larsadmin\…` зі
  списку й не викликає внутрішніх членів;
- **розширення перевизначає лише стабільні хуки.** Клас, що наслідує `Field`, `Filter`, `Card` /
  `DataCard` чи `Tool` (прямо чи через власні класи), може перевизначати лише стабільні члени свого
  базового класу.

Каталог `tests/` розширення не перевіряється: тести можуть посилатися на внутрішні класи, наприклад у
тестах паритету зі скопійованим кодом панелі. У репозиторії панелі `npm run check:api` перевіряє саму
поверхню: що нічого зі знімка не зникло й нічого не додано без запису в знімок.

## Тестування

### Pest (PHP)

Скелет містить `tests/TestCase.php` на Orchestra Testbench (SQLite у пам'яті, міграції Laravel,
фікстурна модель користувача, відкритий гейт панелі) і `tests/Pest.php`:

```php
pest()->extend(TestCase::class)->use(InteractsWithLarsadmin::class)->in(__DIR__);
```

Трейт `Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin` (також для PHPUnit) використовує лише публічний
API:

| Метод | Що робить |
| --- | --- |
| `serializeField(Field $field, ?Model $record = null, string $context = Field::DETAIL, ?string $entity = null, array $abilities = []): array` | серіалізує поле, як панель для сторінки; з `$entity` (клас чи `uriKey`) спершу викликає `bindTo()`; `$abilities` (з 1.6, напр. `['view' => true]`) задає відповіді `authorizedTo()` для запису (без запису й у `create` ігнорується) |
| `fillField(Field $field, Model $record, array $input, array $files = [], string $context = Field::UPDATE): Model` | валідує ввід правилами поля (`ValidationException`), як панель викликає `fillInto()` з журналом файлів, зберігає й виконує відкладене замикання в транзакції; при помилці видаляє файли, зареєстровані через `created()` |
| `cardData(DataCard $card, array $query = [], ?Authenticatable $user = null, string $owner = 'dashboard:test'): mixed` | `canSee()` (`AuthorizationException`), `rules()` (`ValidationException`), `data()` |
| `assertExtensionRegistered(string $name)` | розширення зареєстроване |
| `extensionAssetResponse(string $name, string $file): TestResponse` | `GET` версійованого URL файлу як поточний користувач |

```php
it('serializes the rating', function () {
    $post = new Post(['rating' => 4]);

    expect($this->serializeField(Rating::make('Рейтинг', 'rating')->max(5), $post))
        ->toMatchArray(['component' => 'acme/rating', 'value' => 4, 'max' => 5]);
});

it('rejects invalid card parameters', function () {
    $this->cardData(TopRated::make(), ['limit' => 'many']);
})->throws(ValidationException::class);
```

- `Larsadmin::flushState()` скидає **все** статичне, зокрема розширення, маршрути й tools, які
  зареєстрували провайдери під час boot. Якщо ваші тести його викликають, реєструйте розширення знову в
  `setUp()` після `flushState()` або не викликайте його в тестах розширення.
- HTTP-тест скелета (`tests/HttpTest.php`) перевіряє ендпоінт картки й віддачу `dist/extension.js`
  (пропускається, поки немає `npm run build`).

### vitest (Vue)

Пресет у режимі тестів підставляє `larsadmin` → `kit/sdk.js`, вмикає `jsdom` і виключає `vendor/**` і
`dist/**`. Помічники `kit/testing.js` (через alias `larsadmin-kit` скелета) монтують компоненти з
контрактом панелі без Inertia-застосунку:

```js
import { describe, expect, it } from 'vitest'
import { mountDisplay, mountField } from 'larsadmin-kit/testing.js'
import RatingInput from './RatingInput.vue'
import RatingDisplay from './RatingDisplay.vue'

const translations = { 'acme-rating': { stars: ':count зірка|:count зірки|:count зірок' } }

describe('RatingInput', () => {
    it('emits the chosen number', async () => {
        const wrapper = mountField(RatingInput, { field: { attribute: 'rating', value: 2, max: 5 }, panel: { translations } })

        await wrapper.findAll('input[type="radio"]')[3].setValue(true)

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([4])
    })
})

describe('RatingDisplay', () => {
    it('renders on the index', () => {
        const wrapper = mountDisplay(RatingDisplay, { field: { attribute: 'rating', value: 4, max: 5 }, context: 'index', panel: { translations } })

        expect(wrapper.text()).toContain('4')
    })
})
```

| Помічник | Що монтує |
| --- | --- |
| `withPanel({ locale, translations, path, props })` | опції mount, що дають SDK сторінку панелі (`useI18n`, `useLarsadmin`, …) |
| `mountField(Component, { field, modelValue, errors, errorsByKey, action, context, resourceId, panel, options })` | форму з контрактом; `v-model` підключено; `errors` вмикає `invalid` і `describedBy` `{attribute}-error`; `action: { uriKey, entity }` (з 1.6) монтує поле як у модальному вікні дії (`inAction: true`, `context` за замовчуванням `'create'`) |
| `mountDisplay(Component, { field, context, panel, options })` | відображення; `displayedAs` за замовчуванням `String(value)` |
| `mountCard(Component, { card, panel, options })` | картку з серіалізованими ключами `Card` |

`options` передається в `mount()` `@vue/test-utils`.

Тестування Entity, policy, дій, фільтрів і метрик застосунку описано в [Тестування](21-testing.md).

## Збірка й публікація

```bash
npm run build   # dist/extension.js, extension.css, chunks/, .vite/manifest.json
npm run dev     # перезбирає після кожної зміни (vite build --watch)
npm test        # vitest
vendor/bin/pest # після composer install у каталозі пакета
```

Пресет `larsadmin({ entry = 'resources/js/extension.js', outDir = 'dist' })` збирає ES-бібліотеку
`dist/extension.js`, усі стилі в один `dist/extension.css`, чанки в `dist/chunks/`, manifest для версії й
лишає `vue`, `@inertiajs/vue3` і `larsadmin` зовнішніми. Запобіжник **зупиняє збірку**, якщо в бандл
потрапила власна копія Vue, `@vue/*`, Inertia чи SDK (друга копія Vue є найпідступнішою помилкою: тихо
ламає реактивність і `usePage()`). Імпортуйте їх лише як `vue`, `@inertiajs/vue3` і `larsadmin`, без
глибоких шляхів і alias-ів.

Публікація:

1. Закомітьте `dist/` після `npm run build`.
2. `composer.json` пакета вимагає `"maxcxam/larsadmin": "^1.6"` і реєструє провайдер в
   `extra.laravel.providers` (скелет робить обидва).
3. Поставте тег версії й опублікуйте пакет (Packagist чи приватний репозиторій).

Хост встановлює пакет `composer require` і більше нічого не робить. Після `composer update` змінюється
версія файлів, і відкриті вкладки перезавантажуються під час наступної навігації.

## Усунення проблем

| Симптом | Причина й рішення |
| --- | --- |
| Збірка падає з «contains its own copy of Vue, Inertia or the larsadmin SDK» | імпорт Vue/Inertia через глибокий шлях чи alias, або залежність тягне власний Vue; імпортуйте лише `vue`, `@inertiajs/vue3`, `larsadmin` |
| Поля показують «Недоступно», у консолі нічого про розширення | import map заблоковано CSP: увімкніть `Vite::useCspNonce()` ([CSP](#csp-і-nonce)) |
| «Extension "…" was not loaded (the module is named …)» | `name` у `defineExtension()` не збігається з `Larsadmin::extension()` |
| «… requires ^1.7, the panel API is 1.6» | `requires` вищий за API панелі: оновіть панель або понизьте `requires` |
| Стилі розширення не застосовуються | немає `dist/extension.css` (не імпортовано CSS у `extension.js`) або класи не згенеровано (`@source "../js"`) |
| Ключ відхилено з «use override: true» | ключ без `/` збігся з вбудованим; використайте `vendor/name` або `override: true` для дозволеного ключа |
| `composer require acme/…:^1.6` не знаходить сумісну панель | панель з гілки чи path-репозиторію: див. [швидкий старт](#швидкий-старт) |
| Розширення зникло в тестах | тест викликав `Larsadmin::flushState()`: зареєструйте його знову |
| `check-public-api.mjs`: «… overrides …, which is not a stable override hook» | клас перевизначає нестабільний член базового класу; перенесіть логіку в стабільний хук |

## HTTP-ендпоінти

| Метод | URI (під `{path}`) | Ім'я (`larsadmin.`…) | Відповіді |
| --- | --- | --- | --- |
| GET | `extensions/{vendor}/{package}/{version}/{file}` | `extensions.asset` | 200 файл, 304, 404 (не зареєстровано, traversal, dotfile, тип) |
| GET | `api/dashboards/{dashboard}/metrics/{card}` | `api.dashboard-metric` | `DataCard`: `200 {"data": …}`; 404 невидимий дашборд чи картка, статична картка; 422 `{"message", "errors"}` |
| GET | `api/entities/{entity}/metrics/{card}` | `api.entity-metric` | як вище; 403 без `viewAny` |
| GET | `tools/{tool}` | `tools.show` | 200 Inertia-сторінка `Tool`; 404 невідомий чи невидимий |
| будь-який | `ext/…` | `ext.…` | визначає розширення |

Віддача файлів розширень: лише користувачам, що пройшли гейт панелі (лімітер `larsadmin-files`); лише
файли всередині `realpath` зареєстрованого каталогу; dotfiles (зокрема `.vite/manifest.json`) закриті;
дозволені типи `js`, `mjs`, `css`, `json`, `woff2`, `woff`, `ttf`, `png`, `jpg`, `jpeg`, `gif`, `webp`,
`avif`, `ico`, `svg` (з `Content-Security-Policy: sandbox`) і `map` (лише з
`LARSADMIN_EXTENSION_SOURCE_MAPS=true`). Заголовки: `X-Content-Type-Options: nosniff`,
`Cross-Origin-Resource-Policy: same-origin`, ETag і `Last-Modified`. Поточна версія кешується як
`private, max-age={extensions.max_age}, immutable`, стара віддається з `no-cache`.

Конфіг (`config/larsadmin.php`):

| Ключ | Змінна середовища | За замовчуванням | Опис |
| --- | --- | --- | --- |
| `extensions.source_maps` | `LARSADMIN_EXTENSION_SOURCE_MAPS` | `false` | віддавати `*.map` розширень (лише для налагодження) |
| `extensions.max_age` | | `31536000` | строк кешу браузера для версійованих файлів, секунд |

Shared props (лише для користувачів, що пройшли гейт): `larsadmin.extensions` (`[{ name, script, style }]`),
`larsadmin.extensionTranslations`, `larsadmin.debug`, `tools`. Без зареєстрованих розширень і tools цих
props немає, а Inertia-версія така сама, як у 1.4.

## Обмеження

- **Перше завантаження важче приблизно на 27 КБ gzip**, ніж у 1.4, навіть без розширень: Vue та Inertia
  лежать у спільних чанках, які розширення імпортують через import map, і не проходять tree-shaking.
  Далі файли кешуються браузером. Зайвих послідовних запитів немає: кореневий шаблон додає
  `<link rel="modulepreload">` (з `nonce`) для статичних імпортів `app.js` за `dist/.vite/manifest.json`
  (без manifest тегів немає), тож після `app.js` браузер паралельно вантажить чанки vue, inertia і shared.
- Поля, що надсилають файли (`File`, `Image`, `multipart: true`), у модальних вікнах дій не підтримуються.
- `Larsadmin::flushState()` знімає розширення, зареєстровані провайдерами під час boot (див.
  [Тестування](#pest-php)).
- `FileJournal` видаляє файли, але не каталоги: після відкинутого завантаження можуть лишатися порожні
  каталоги (наприклад, каталоги медіа spatie/laravel-medialibrary).
- `larsadmin:extension` завжди пише пакет у `PATH/VENDOR/PACKAGE` (`--path=sandbox/extensions` дає
  `sandbox/extensions/acme/larsadmin-rating`).
- Маршрути розширень керують файлами самі: журналу файлів у них немає.
- Розширення без збірки пишуться лише render-функціями (`h()`), без шаблонів-рядків.
- Немає віддалених скриптів (CDN), npm-пакета kit і TypeScript-типів.
- Немає власних модальних вікон дій, layout-слотів, сторінок Entity, карток на detail-сторінці й
  залежних полів. Картки лише читають дані; зміни робляться діями чи маршрутами розширення.
- Посилання в toast-ах розширень не підтримуються.

Далі: [AI-асистенти](20-ai-assistants.md).
