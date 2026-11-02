# 03. Поля
🇬🇧 [English](en/03-fields.md) · 🌐 [larsadmin.dev](https://larsadmin.dev/uk/docs/fields)

Поля повертає метод `Entity::fields(Request $request): array`. Усі поля створюються через
`Field::make(...)` і налаштовуються ланцюжком методів.

```php
Text::make('Назва', 'name')->sortable()->rules('required', 'max:255'),
```

Перший аргумент назва поля в інтерфейсі, другий атрибут моделі. Якщо атрибут не вказано, він
виводиться з назви: `Str::snake(Str::lower($name))` (`'Created At'` дає `created_at`).
**Назва з не-ASCII символами без явного атрибута кидає `InvalidArgumentException`**, тому для
українських підписів завжди передавайте атрибут другим аргументом.

## Типи полів

Усі класи з простору імен `Maxcxam\Larsadmin\Fields`.

| Поле | Компонент | Особливості |
| --- | --- | --- |
| `ID` | `id-field` | `ID::make()` дає назву `ID` і атрибут `id`; показується лише на index і detail |
| `Text` | `text-field` | `inputType('email')` задає HTML-тип поля (`email`, `url`, `tel`) |
| `Textarea` | `textarea-field` | приховане на index; `rows(int)`, за замовчуванням 5 |
| `Number` | `number-field` | `min()`, `max()`, `step()` |
| `Boolean` | `boolean-field` | `trueValue()`, `falseValue()` задають, що зберігається; за замовчуванням `true`/`false` |
| `Date` | `date-field` | форма віддає `Y-m-d`; `displayFormat('d.m.Y')` для index і detail |
| `DateTime` | `datetime-field` | форма віддає `Y-m-d\TH:i:s`; успадковує `displayFormat()` |
| `Password` | `password-field` | лише на формах; хеш ніколи не надсилається в браузер; змінюється, тільки якщо введено новий (`Hash::make`) |
| `Select` | `select-field` | `options(array\|Closure)`, мапа `значення => підпис` |
| `File` | `file-field` | завантаження файлу на диск; `multiple()`, приватні файли з підписаними посиланнями, див. [Файли й зображення](15-files.md) |
| `Image` | `image-field` | як `File`, лише растрові зображення, з мініатюрами; див. [Файли й зображення](15-files.md) |
| `BelongsTo`, `HasMany`, `BelongsToMany` | | див. [Зв'язки](04-relations.md) |

Формат відображення дат за замовчуванням береться з перекладу `larsadmin::ui.formats.date` та
`larsadmin::ui.formats.datetime` (для `en`: `Y-m-d` і `Y-m-d H:i:s`), тобто залежить від мови панелі.

### Приклад: усі типи разом

Приклад (`app/Larsadmin/Post.php`):

```php
public function fields(Request $request): array
{
    return [
        ID::make()->sortable(),
        Text::make('Заголовок', 'title')->sortable()->rules('required', 'max:255'),
        Textarea::make('Текст', 'body')->nullable(),
        Select::make('Статус', 'status')->sortable()->rules('required')->options([
            'draft' => 'Чернетка',
            'review' => 'На перевірці',
            'live' => 'Опубліковано',
        ]),
        Boolean::make('Опубліковано', 'published'),
        Number::make('Перегляди', 'views')->min(0)->default(0)->sortable(),
        Date::make('Перевірити до', 'review_due')->nullable(),
        DateTime::make('Дата публікації', 'published_at')->nullable()->sortable(),
    ];
}
```

`Select` розуміє enum: для `BackedEnum` береться `value`, для `UnitEnum` `name`. Опції можна
віддати замиканням, яке виконується один раз на екземпляр поля:

```php
Select::make('Статус', 'status')->options(fn () => Status::pluck('label', 'code')->all());
```

## Спільні методи

| Метод | Дія |
| --- | --- |
| `sortable(bool $sortable = true)` | дозволяє сортування за колонкою на index (потрібне й для `Entity::$sort`) |
| `nullable(bool $nullable = true)` | порожній рядок зберігається як `null`; додає правило `nullable` |
| `readonly(bool\|Closure $readonly = true)` | поле не заповнюється й не валідується (див. «Видимість») |
| `help(string)` | підказка під полем |
| `placeholder(string)` | плейсхолдер |
| `default(mixed\|Closure)` | значення на формі створення; замикання отримує `Request` |
| `withMeta(array)` | додаткові дані в серіалізованому полі |
| `resolveUsing(callable)` | змінює значення, яке бачить форма: `fn ($raw, $record, $attribute)` |
| `displayUsing(callable)` | змінює відображення на index/detail: `fn ($raw, $record, $attribute)` |
| `fillUsing(callable)` | повністю замінює запис значення: `fn ($request, $record, $attribute)` |
| `sensitive(bool $sensitive = true)` | журнал аудиту записує лише факт зміни поля, без значень; на форми й перегляд не впливає. `Password` маскується завжди; позначайте й поля з accessor-ом, що розшифровує значення, і JSON-колонки із секретами ([Журнал аудиту](17-audit-log.md#маскування-чутливих-полів)) |

`fillUsing` може повернути замикання `fn (Model $record)`. Воно виконається **після збереження**
запису в тій самій транзакції (зручно для залежних даних).

```php
Text::make('Slug', 'slug')->fillUsing(function ($request, $record, $attribute) {
    $record->slug = Str::slug($request->input('title'));
});
```

Якщо задано `fillUsing`, неявні правила типу (див. нижче) не додаються.

## Видимість

За замовчуванням поле видно на index, detail, створенні та редагуванні.

| Метод | Ефект |
| --- | --- |
| `hideFromIndex()` | ховає зі списку |
| `hideFromDetail()` | ховає з перегляду |
| `hideWhenCreating()` | ховає з форми створення |
| `hideWhenUpdating()` | ховає з форми редагування |
| `showOnIndex()`, `showOnDetail()`, `showWhenCreating()`, `showWhenUpdating()` | показує у відповідному контексті |
| `onlyOnIndex()` | лише у списку |
| `onlyOnDetail()` | лише на перегляді |
| `onlyOnForms()` | лише на формах створення й редагування |
| `exceptOnForms()` | на index і detail, але не на формах |
| `canSee(Closure)` | `fn (Request $request): bool`; поле існує лише для тих, кому дозволено |

Методи `hide*` і `show*` приймають `bool|Closure` (за замовчуванням `true`). Замикання отримує
`fn (Request $request, ?Model $record): bool`. `hide*` приховує, коли замикання повертає `true`,
`show*` показує. **На index `$record` дорівнює `null`**: видимість там визначається для запиту, а не
для рядка.

```php
Text::make('Нотатка', 'note')
    ->hideWhenUpdating(fn (Request $request, ?Model $post) => $post?->status === 'live');
```

### Тільки для читання

`readonly(bool|Closure $readonly = true)`: замикання має вигляд `fn (Request $request, Model $record): bool`
(під час створення `$record` це нова модель).

```php
Select::make('Статус', 'status')->options(['draft' => 'Чернетка', 'live' => 'Опубліковано'])
    ->readonly(fn (Request $request, Model $post) => $post->status === 'live');
```

### Безпека

Приховане, `readonly` і заборонене через `canSee` поле **пропускається на сервері** під час
заповнення й валідації: значення такого поля, надіслане вручну, ігнорується. Поле, для якого
`canSee` повернув `false`, відсутнє в усіх контекстах (колонки, перегляд, форми, заповнення,
валідація, сортування).

### Яке поле де показується

| Поле / налаштування | index | detail | create | update |
| --- | --- | --- | --- | --- |
| звичайне поле | так | так | так | так |
| `ID` | так | так | ні | ні |
| `Textarea` | ні | так | так | так |
| `Password` | ні | ні | так | так |
| `exceptOnForms()` | так | так | ні | ні |
| `onlyOnForms()` | ні | ні | так | так |
| `HasMany`, `BelongsToMany` | ні | так (панель) | ні | ні |
| `canSee()` повернув `false` | ні | ні | ні | ні |

Приклад (`app/Larsadmin/User.php`):

```php
DateTime::make('Створено', 'created_at')->exceptOnForms()->sortable(),
```

## Колонки списку

| Метод | Опис |
| --- | --- |
| `width(string\|int)` | ширина: `int` це пікселі; рядок виду `120px`, `20%`, `12rem`, `10em`, `8ch`. Інші одиниці й формати кидають `InvalidArgumentException` |
| `align('left'\|'center'\|'right')` | вирівнювання; за замовчуванням `left`, для `Number` `right`, для `Boolean` `center` |
| `wrap()` | дозволити перенесення тексту в комірці |
| `truncate(?int $chars = null)` | обрізати довгий текст з трикрапкою: після `$chars` символів (мінімум 1) або за шириною колонки, якщо `null` |
| `hideByDefault()` | колонка прихована, доки користувач не ввімкне її в меню «Колонки» ([Інтерфейс](11-ui.md)) |

`wrap()` і `truncate()` виключають одне одного: діє той, що викликано останнім.

## Групування полів

Поля на формах і сторінці перегляду можна згрупувати в панелі (`Layout\Panel`) і вкладки
(`Layout\Tabs`). `fullWidth()` розтягує поле на обидві колонки двоколонкової панелі. Див.
[Блоки полів](12-layout.md).

## Комбінований приклад

```php
public function fields(Request $request): array
{
    return [
        ID::make()->sortable()->width(70),
        Text::make('Заголовок', 'title')->sortable()->truncate(60)->rules('required', 'max:255'),
        Textarea::make('Текст', 'body')->nullable()->wrap(),
        Select::make('Статус', 'status')->sortable()->width('9rem')
            ->options(['draft' => 'Чернетка', 'live' => 'Опубліковано'])
            ->readonly(fn (Request $request, Model $post) => $post->status === 'live'),
        Number::make('Перегляди', 'views')->min(0)->default(0)->sortable()->align('center'),
        Text::make('Внутрішній код', 'code')->nullable()->hideByDefault()->hideWhenCreating(),
        Text::make('Нотатка модератора', 'moderator_note')->nullable()
            ->canSee(fn (Request $request) => $request->user()->can('moderate')),
        DateTime::make('Створено', 'created_at')->exceptOnForms()->sortable(),
    ];
}
```

## Валідація

```php
Text::make('Email', 'email')->inputType('email')->sortable()
    ->rules('required', 'email', 'max:255', 'unique:users,email,{{id}}'),
```

| Метод | Коли діє |
| --- | --- |
| `rules(...)` | створення й оновлення |
| `creationRules(...)` | лише створення |
| `updateRules(...)` | лише оновлення |

Аргументи: рядки, об'єкти правил, замикання або один масив. Кожен виклик **замінює** набір, заданий
цим самим методом раніше.

### Конвеєр правил (`|`)

Рядки з `|` розбиваються на окремі правила: `rules('required|max:255')` еквівалентно
`rules('required', 'max:255')`. Виняток: рядок, що містить `regex:` або `not_regex:`, не
розбивається, бо шаблон може містити `|`. Такі правила передавайте окремими аргументами:

```php
Text::make('Код', 'code')->rules('required', 'regex:/^(a|b)\d+$/');
```

### Плейсхолдер `{{id}}`

У рядкових правилах `{{id}}` замінюється ключем поточного запису, а під час створення значенням
`NULL`. Це дає `unique` без власних правил для редагування:

```php
Text::make('Слаг', 'slug')->rules('required', 'max:255', 'unique:categories,slug,{{id}}'),
```

### Неявні правила типу

Перед вашими правилами автоматично додаються правила, що випливають з типу поля:

| Поле | Неявні правила |
| --- | --- |
| `Text`, `Textarea`, `Password` | `nullable`, `string` |
| `Number` | `numeric`, а також `min:N` / `max:N` з `min()` / `max()` |
| `Boolean` | `boolean` |
| `Date`, `DateTime` | `date` |
| `Select` | `Rule::in(...)` по ключах опцій |
| `BelongsTo` | `required` (або `nullable`) і перевірка `RelatableExists` |
| `File`, `Image` | правила для кожного завантаженого файлу, див. [Файли й зображення](15-files.md#валідація-й-обмеження) |

Для `Number`, `Boolean`, `Date`, `Select` правило `nullable` додається, лише якщо викликано
`nullable()`. Тож `Date::make(...)` без `nullable()` вимагає значення навіть без явного `required`.

Порядок: неявні правила, `rules()`, потім `creationRules()` або `updateRules()`.

### Пароль: різні правила для створення й оновлення

Приклад (`app/Larsadmin/User.php`):

```php
Password::make('Пароль', 'password')
    ->creationRules('required', 'min:8')
    ->updateRules('nullable', 'min:8')
    ->help('Залиште порожнім, щоб не змінювати пароль.'),
```

### Повідомлення

Повідомлення валідації беруться з `lang/{locale}/validation.php`, а підписи полів з їх назв.
Українські повідомлення встановлює `larsadmin:install` ([Локалізація](08-i18n.md)).

## Власні поля

Власне поле є спадкоємцем `Field` (або `Text`, `Number`, …). З 1.5 його захищені хуки
(`resolveValue()`, `formatForDisplay()`, `fillValue()`, `fillString()`, `typeRules()`, `implicitRules()`,
`acceptsNullInput()`, `defaultAlign()`), публічні хуки (`fill()`, `fillInto()`, `fillAttribute()`,
`resolve()`, `eagerLoads()`, `applySort()`, `bindTo()`, `hasAuditValue()`, `auditValue()`), `withMeta()`,
`jsonSerialize()` і `$component` є стабільним API. Поле з вбудованим компонентом описано в
[Розширення: огляд](10-extending.md#поле-з-вбудованим-компонентом), поле з власним Vue-компонентом, зокрема
поле, що надсилає файли (`multipart()`), у [Розширення: публічний API](19-extensions.md#власні-поля).

З 1.6 стабільні також `authorizedTo()`, `inAction()` і хук `actionValue()`. Два останні стосуються полів у
модальному вікні дії: [Дії: поля розширень у модальному вікні](07-actions.md#поля-розширень-у-модальному-вікні).

### Права на запис: `authorizedTo()`

`authorizedTo(string $ability): bool` відповідає, чи може поточний користувач виконати `view`, `update` або
`delete` над записом, для якого поле розв'язується (у `resolve()`, `jsonSerialize()` тощо). Це ті самі
відповіді policy, які панель уже обчислила для рядка (кнопки «Переглянути», «Редагувати», «Видалити»), тож
виклик не додає запитів до policy, також на index з багатьма рядками.

```php
use Illuminate\Database\Eloquent\Model;
use Maxcxam\Larsadmin\Fields\Field;

class Contract extends Field
{
    public string $component = 'acme/contract';

    public function resolve(Model $record): static
    {
        parent::resolve($record);

        // посилання лише для того, хто може переглядати запис
        return $this->withMeta([
            'downloadUrl' => $this->authorizedTo('view')
                ? route('acme.contracts.download', $record->getKey())
                : null,
        ]);
    }
}
```

`authorizedTo()` повертає `false` (fail closed), коли відповідь невідома: інша здатність, контекст
створення, поле дії (`inAction()`), поле, створене вручну поза Entity. Права в серіалізоване поле не
додаються: поле саме вирішує, що віддати клієнту. Вбудовані `File` і `Image` віддають URL файлів за
`authorizedTo('view')`. `Larsadmin::findField()` повертає поле з правами для знайденого запису. У тестах права
передає `serializeField(..., abilities: ['view' => true])` ([Розширення: тестування](19-extensions.md#тестування)).

Далі: [Зв'язки](04-relations.md).
