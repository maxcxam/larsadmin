# 13. Метрики

Метрика це картка з числом або графіком, яку панель показує на [дашборді](14-dashboards.md) або над
списком Entity. Кожна картка завантажує дані окремим JSON-запитом, тож повільна чи зламана метрика
не блокує сторінку та інші картки.

| Тип | Базовий клас | Що показує | Результат `calculate()` |
| --- | --- | --- | --- |
| Value | `Maxcxam\Larsadmin\Metrics\Value` | одне число за період і зміну відносно попереднього | `ValueResult` |
| Trend | `Maxcxam\Larsadmin\Metrics\Trend` | лінійний графік по днях, тижнях або місяцях | `TrendResult` |
| Partition | `Maxcxam\Larsadmin\Metrics\Partition` | кільцеву діаграму за значеннями колонки | `PartitionResult` |
| Progress | `Maxcxam\Larsadmin\Metrics\Progress` | прогрес до цілі | `ProgressResult` |

Результати лежать у просторі імен `Maxcxam\Larsadmin\Metrics\Results`.

## Генератори

```bash
php artisan larsadmin:value NewPosts
php artisan larsadmin:trend PostsPerDay
php artisan larsadmin:partition PostsByStatus
php artisan larsadmin:progress MonthlyPublishingGoal
```

Класи створюються в `app/Larsadmin/Metrics` (`App\Larsadmin\Metrics\NewPosts`). Вкладені назви
працюють: `Reports/Revenue` дає `App\Larsadmin\Metrics\Reports\Revenue`. `--force` перезаписує
існуючий клас. Класи метрик у `app/Larsadmin` не реєструються як Entity.

> Згенерований клас рахує `App\Models\User` (клас моделі, **без обмежень доступу**), щоб картка
> працювала одразу; рядок позначено коментарем `// SECURITY: unscoped`. Замініть джерело на свою
> Entity (див. «Джерело даних і безпека»). Шаблон Value вже містить `->allowZeroResult()`, шаблон
> Trend властивість `$unit`.

## Перша метрика

```php
namespace App\Larsadmin\Metrics;

use App\Larsadmin\Post;
use Maxcxam\Larsadmin\Metrics\MetricRequest;
use Maxcxam\Larsadmin\Metrics\Results\ValueResult;
use Maxcxam\Larsadmin\Metrics\Value;

class NewPosts extends Value
{
    public function name(): string
    {
        return 'Нові пости';
    }

    public function ranges(): array
    {
        return $this->labelled([7, 30, 90, 'MTD']);
    }

    public function calculate(MetricRequest $request): ValueResult
    {
        // Entity: рахуються лише рядки, які бачить користувач; 0 показується як число, а не «Немає даних»
        return $this->count($request, Post::class)->allowZeroResult();
    }
}
```

Підключіть картку в `cards()` дашборда або Entity:

```php
public function cards(Request $request): array
{
    return [
        NewPosts::make()->help('Дописи, створені за період.'),
    ];
}
```

## Картка

Спільні методи всіх карток (клас `Maxcxam\Larsadmin\Metrics\Card`):

| Метод | За замовчуванням | Опис |
| --- | --- | --- |
| `make(...$args)` | | створює екземпляр (аргументи йдуть у конструктор) |
| `name()` | назва класу в Title Case через `__()` | заголовок картки; перевизначте метод |
| `uriKey()` | kebab-case назви класу | ключ картки в URL. Має бути унікальним у межах дашборда чи Entity: дві картки з однаковим ключем (видимі чи ні) кидають `InvalidArgumentException` |
| `width(string)` | `'1/3'` | `'1/3'`, `'1/2'`, `'2/3'` або `'full'`. Інше значення кидає `InvalidArgumentException` |
| `help(string)` | | підказка: іконка «?» біля заголовка |
| `canSee(Closure)` | | `fn (Request $request): bool`. Прихована картка не показується, а її ендпоінт відповідає 404 |

Ширина діє на великих екранах (сітка з шести частин). На планшеті картки `1/3` і `1/2` займають
половину рядка, `2/3` і `full` весь рядок; на телефоні кожна картка на всю ширину.

## Періоди (ranges)

`ranges()` повертає доступні періоди у вигляді `ключ => підпис`. Користувач перемикає період у
списку на картці (список показується, коли періодів більше одного). `defaultRange()` повертає
період за замовчуванням: перший ключ `ranges()`. Невідомий або некоректний `range` у запиті
замінюється періодом за замовчуванням.

| Ключ | Поточний період | З чим порівнюється (Value) |
| --- | --- | --- |
| `N` (ціле число) | від початку дня `N − 1` днів тому до цього моменту | ті самі `N` днів перед ним |
| `'TODAY'` | від початку сьогоднішнього дня | вчора, той самий проміжок часу від півночі |
| `'MTD'` | від початку місяця | попередній місяць, той самий проміжок від його початку |
| `'QTD'` | від початку кварталу | попередній квартал, так само |
| `'YTD'` | від початку року | попередній рік, так само |
| `'ALL'` | без обмеження дати | не порівнюється |

Періоди напіввідкриті, `[початок, кінець)`: рядок рівно на межі потрапляє лише в один період, а
попередній період ніколи не заходить у поточний.

`$this->labelled([...])` будує `ranges()` з перекладеними підписами (файл `larsadmin::metrics`):
`TODAY`, `MTD`, `QTD`, `YTD`, `ALL` мають власні підписи, ціле число підписується кількістю днів
у правильній формі множини («14 днів», «21 день»). Підписи можна задати й самому:

```php
public function ranges(): array
{
    return [7 => 'Тиждень', 30 => 'Місяць', 'MTD' => 'Цей місяць'];
}

public function defaultRange(): int
{
    return 30;
}
```

Значення за замовчуванням:

| Тип | `ranges()` |
| --- | --- |
| Value, Progress | `30, 60, 365, TODAY, MTD, QTD, YTD` |
| Trend | **кількість точок** в одиницях `$unit`: днів `7, 30, 90`, тижнів `4, 12, 26`, місяців `3, 6, 12` (див. «Trend») |
| Partition | порожньо: діаграма завжди рахує всі рядки |

### Часовий пояс

Межі періодів («сьогодні», «з початку місяця», точки графіка) рахуються в часовому поясі панелі:

1. `Larsadmin::timezoneUsing(fn (Request $request) => ...)`, наприклад пояс із профілю користувача;
2. `config('larsadmin.timezone')` (`LARSADMIN_TIMEZONE`);
3. `config('app.timezone')`.

Невідомі ідентифікатори поясу пропускаються. Дати в базі порівнюються в `app.timezone`.

```php
// app/Providers/LarsadminServiceProvider.php, boot()
Larsadmin::timezoneUsing(fn (Request $request) => $request->user()?->timezone);
```

## Джерело даних і безпека

Хелпери (`count`, `sum`, `countByDays` тощо) приймають джерело `$model`. **Від нього залежить, які
рядки потраплять у число.**

| Джерело | Обмеження |
| --- | --- |
| клас Entity: `Post::class` з `App\Larsadmin` | рядки з `indexQuery()` цієї Entity; без права `viewAny` картка показує «Немає доступу до цих даних.» (відповідь 403) |
| `$this->scoped(Post::class, fn (Builder $q) => ...)` або `EntitySource::make(Post::class, fn ...)` | те саме, що Entity, плюс ваша умова |
| клас моделі: `\App\Models\Post::class` | **жодних обмежень** |
| Eloquent builder (`Post::query()->where(...)`) або `DB::table(...)` | **жодних обмежень** |

> **Увага.** Клас моделі та builder рахують **усі рядки**, до яких дотягується запит, без
> `indexQuery()` і без політик. Користувач, якому `indexQuery()` показує лише власні записи, побачить
> у такій метриці число по всій таблиці. Використовуйте їх лише для загальних показників і ховайте
> картку через `canSee()`, якщо число чутливе.

Звужуйте Entity через `scoped()`, а не через модель:

```php
use Illuminate\Database\Eloquent\Builder;

public function calculate(MetricRequest $request): ValueResult
{
    $live = $this->scoped(Post::class, fn (Builder $query) => $query->where('status', 'live'));

    return $this->sum($request, $live, 'views', 'published_at');
}
```

Умова `scoped()` додається вкладеним `where (...)`, тому `orWhere` у ній не вийде за межі
`indexQuery()`. `scoped()` (метод метрики) і `EntitySource::make()` (клас
`Maxcxam\Larsadmin\Metrics\EntitySource`) рівнозначні; для класу, що не є Entity, вони кидають
`InvalidArgumentException`.

Інше про джерела:

- Для Eloquent-джерел діють глобальні скоупи моделі (наприклад, `SoftDeletes`).
- Колонка дати за замовчуванням це `created_at` моделі (`getCreatedAtColumn()`). Для `DB::table()`
  моделі немає, тому колонку дати потрібно передати явно, інакше `InvalidArgumentException`.
- `AuthorizationException`, кинутий у `calculate()`, дає відповідь 403 і стан «Немає доступу».

## Value

```php
protected function count(MetricRequest $request, $model, ?string $dateColumn = null): ValueResult
protected function sum(MetricRequest $request, $model, string $column, ?string $dateColumn = null): ValueResult
protected function average(MetricRequest $request, $model, string $column, ?string $dateColumn = null): ValueResult
protected function min(MetricRequest $request, $model, string $column, ?string $dateColumn = null): ValueResult
protected function max(MetricRequest $request, $model, string $column, ?string $dateColumn = null): ValueResult
protected function result(int|float|null $value): ValueResult   // власне значення
```

Хелпери рахують поточний період і попередній (див. таблицю періодів) і повертають обидва числа.
Картка показує зміну у відсотках:

| Стан | Що видно |
| --- | --- |
| значення зросло / зменшилося | «Зросло на N%» / «Зменшилося на N%» зі стрілкою |
| зміна округлюється до 0,0% | «Без змін» |
| попереднє значення `0` | «Немає даних за попередній період» |
| попереднього періоду немає (`ALL`, `result()` без `previous()`) | лише число |
| значення `null` (наприклад, `average` без рядків) | «Немає даних» |
| значення `0` без `allowZeroResult()` | «Немає даних», без порівняння |

Методи `ValueResult`:

| Метод | Опис |
| --- | --- |
| `previous(int\|float\|null)` | значення попереднього періоду для власних обчислень |
| `allowZeroResult(bool $allow = true)` | показувати `0` як справжній результат (з порівнянням). Без нього `0` вважається відсутністю даних. Для лічильників на кшталт «Нові пости» зазвичай потрібен |
| форматування | див. «Форматування» |

```php
public function calculate(MetricRequest $request): ValueResult
{
    $value = Order::query()->where('paid', true)->sum('total'); // модель: без обмежень доступу

    return $this->result($value)->currency('UAH');
}
```

## Trend

```php
class NewUsersPerWeek extends Trend
{
    protected string $unit = 'week'; // одиниця періодів за замовчуванням: day, week або month

    public function calculate(MetricRequest $request): TrendResult
    {
        return $this->countByWeeks($request, User::class)->showSumValue();
    }
}
```

Властивість `$unit` (`'day'` за замовчуванням) визначає лише періоди за замовчуванням і їхні підписи:
днів `7, 30, 90`, тижнів `4, 12, 26`, місяців `3, 6, 12` («12 тижнів»). Одиницю точок задає хелпер
(`countByWeeks()` рахує тижнями), тож тримайте `$unit` у відповідності до нього. Власні періоди
Trend задавайте цілими числами; `$this->bucketRanges([4, 8, 13])` підпише їх в одиницях `$unit`.
Рядковий ключ періоду (`'MTD'` тощо) у Trend кидає `InvalidArgumentException`. Невідомий `$unit`
теж кидає `InvalidArgumentException`.

Хелпери за одиницею часу й агрегатом:

| | днями | тижнями | місяцями |
| --- | --- | --- | --- |
| кількість | `countByDays($r, $model, ?$dateColumn)` | `countByWeeks(...)` | `countByMonths(...)` |
| сума | `sumByDays($r, $model, $column, ?$dateColumn)` | `sumByWeeks(...)` | `sumByMonths(...)` |
| середнє | `averageByDays(...)` | `averageByWeeks(...)` | `averageByMonths(...)` |
| мінімум | `minByDays(...)` | `minByWeeks(...)` | `minByMonths(...)` |
| максимум | `maxByDays(...)` | `maxByWeeks(...)` | `maxByMonths(...)` |

Також є загальний `aggregate($request, $model, string $unit, string $function, ?string $column = null, ?string $dateColumn = null)`
з `$unit` = `day|week|month` і `$function` = `count|sum|avg|average|min|max`.

- Період означає кількість точок: `30` дає 30 днів (тижнів, місяців), останньою йде поточна,
  ще не завершена. Тижні починаються з понеділка. Максимум 366 точок.
- Межі точок рахуються в часовому поясі панелі й коректні під час переходу на літній/зимовий час.
- Точки без рядків мають `0` для `count`/`sum` і порожнє значення для `average`/`min`/`max`.
- Підписи точок форматує сервер мовою панелі (`D MMM` для днів і тижнів, `MMM YYYY` для місяців).

Головне число над графіком вмикається явно:

| Метод `TrendResult` | Число над графіком |
| --- | --- |
| `showSumValue()` | сума всіх точок; лише для `count`/`sum`, для `average`/`min`/`max` кидає `LogicException` |
| `showLatestValue()` | значення останньої (поточної) точки |
| нічого | лише графік |

```php
public function calculate(MetricRequest $request): TrendResult
{
    $live = $this->scoped(Post::class, fn (Builder $query) => $query->where('status', 'live'));

    return $this->countByDays($request, $live, 'published_at')->showSumValue();
}
```

## Partition

```php
protected function count(MetricRequest $request, $model, string $groupBy): PartitionResult
protected function sum(MetricRequest $request, $model, string $column, string $groupBy): PartitionResult
protected function average(MetricRequest $request, $model, string $column, string $groupBy): PartitionResult
protected function min(MetricRequest $request, $model, string $column, string $groupBy): PartitionResult
protected function max(MetricRequest $request, $model, string $column, string $groupBy): PartitionResult
```

Рядки групуються за колонкою `$groupBy`, сектори йдуть від найбільшого. Період не враховується.

| Метод `PartitionResult` | Опис |
| --- | --- |
| `label(Closure)` | підпис сектора: `fn (string $key): string`. Ключ завжди рядок, `''` для `NULL` |
| `labelsFrom(string\|Builder $model, string $titleColumn = 'name', ?string $missing = null)` | підписи з пов'язаної моделі за первинним ключем (див. нижче) |
| `booleanLabels(?string $yes = null, ?string $no = null)` | підписи «Так»/«Ні» для ключів `'1'`/`'0'` булевої колонки (`''` дає «Без значення») |
| `colors(array)` | CSS-кольори за ключем: `['live' => '#16a34a']`. Приймаються `#hex`, `rgb()`/`rgba()`, `hsl()`/`hsla()`, `oklch()` і `var(--token)`; інші значення й сектори без кольору беруть палітру теми |
| `limit(int $limit = 8)` | максимум секторів. Для `count`/`sum` решта збирається в сектор «Інше», для `average`/`min`/`max` решта відкидається. Менше 1 кидає `InvalidArgumentException` |

`label()`, `labelsFrom()` і `booleanLabels()` замінюють одне одного: діє останній виклик. Без них
сектор підписаний ключем, а `NULL`-група підписом «Без значення».

- **Булеві колонки.** Значення `true`/`false` стають ключами `'1'`/`'0'` на будь-якій СУБД. Якщо
  модель має для колонки каст `bool`/`boolean`, `booleanLabels()` застосовується автоматично.
- **`average`/`min`/`max`.** Значення секторів не складаються, тому картка не показує ні загальної
  суми, ні відсотків (у JSON `"additive": false`, `"total": null`).

```php
public function calculate(MetricRequest $request): PartitionResult
{
    return $this->count($request, Post::class, 'status')
        ->label(fn (string $status) => ['draft' => 'Чернетка', 'live' => 'Опубліковано'][$status] ?? $status)
        ->colors(['live' => '#16a34a', 'draft' => '#94a3b8']);
}
```

### Підписи з пов'язаної моделі

Коли групуєте за зовнішнім ключем, `labelsFrom()` підставляє назву пов'язаного запису:

```php
return $this->count($request, Post::class, 'category_id')
    ->labelsFrom(Category::class, 'name', 'Без категорії')
    ->limit(6);
```

- Назви завантажуються одним запитом `whereIn` і лише для показаних секторів (після `limit()`).
- `NULL`-ключ отримує `$missing` (за замовчуванням «Без значення»), ключ без запису отримує `#<ключ>`.
- Можна передати builder і власну колонку назви: `labelsFrom(Category::query()->where('active', true), 'title')`.
- Пов'язана модель читається **без** `indexQuery()` і політик, але лише для ключів, які вже є в
  результаті користувача. Тобто розкриваються назви тих груп, які він і так бачить у числах.

### Власні значення

```php
use Maxcxam\Larsadmin\Metrics\Results\PartitionResult;

$values = ['Підтверджені' => 12, 'Непідтверджені' => 3]; // ключ => значення, від найбільшого
arsort($values);

return new PartitionResult($values);
```

## Progress

```php
protected function count(MetricRequest $request, $model, int|float $target, ?string $dateColumn = null): ProgressResult
protected function sum(MetricRequest $request, $model, string $column, int|float $target, ?string $dateColumn = null): ProgressResult
protected function result(int|float|null $value, int|float $target): ProgressResult
```

Рахує значення за поточний період (без порівняння) і показує відсоток від `$target`.
`avoid()` означає «менше краще» й інвертує кольори (наприклад, для кількості скарг).

```php
class MonthlyPublishingGoal extends Progress
{
    public function ranges(): array
    {
        return $this->labelled(['MTD']);
    }

    public function calculate(MetricRequest $request): ProgressResult
    {
        $live = $this->scoped(Post::class, fn (Builder $query) => $query->where('status', 'live'));

        return $this->count($request, $live, 80, 'published_at');
    }
}
```

## Форматування

Числа форматує браузер через `Intl.NumberFormat` мовою панелі. Порожнє значення виводиться як «—».

| Метод | Value | Trend | Partition | Progress | Опис |
| --- | --- | --- | --- | --- | --- |
| `currency(string $iso)` | так | так | так | так | валюта ISO 4217: `->currency('UAH')` |
| `format(array)` | так | так | так | так | опції `Intl.NumberFormat`: `['maximumFractionDigits' => 1]`, `['notation' => 'compact']`, `['style' => 'percent']` |
| `prefix(string)`, `suffix(string)` | так | так | **ні** | так | текст до й після числа: `->suffix(' шт.')`. У `PartitionResult` цих методів немає; використовуйте `currency()` або `format()` |

```php
return $this->sum($request, $live, 'views', 'published_at')->format(['notation' => 'compact']);
```

## Кешування

За замовчуванням метрика рахується на кожен запит. Щоб кешувати, перевизначте `cacheFor()`:

```php
public function cacheFor(): int
{
    return 300; // секунди; також DateInterval або DateTimeInterface
}
```

- Ключ кешу містить власника картки (дашборд чи Entity), клас і `uriKey()` метрики, період,
  часовий пояс, мову, guard і **користувача** (клас моделі + ідентифікатор), а також ваші частини
  (див. нижче). Результат одного користувача не потрапить іншому.
- Сховище: `config('larsadmin.metrics.cache_store')`, `null` означає сховище за замовчуванням.
- `null`, `0` або від'ємне значення вимикають кеш. Відмова в доступі (403) не кешується.
- Закешоване число застаріває до кінця строку. Для показників, за якими користувачі діють,
  тримайте строк коротким.

### Що перевіряється при попаданні в кеш

Авторизація не кешується:

- `canSee()` дашборда й картки виконується на кожен запит;
- для кожного Entity-джерела, яке використала метрика (`Post::class`, `scoped(...)`), `viewAny`
  перевіряється знову; якщо право відкликали, відповідь 403.

**`calculate()` при попаданні в кеш не викликається.** Тому власні перевірки доступу всередині
`calculate()` (наприклад, `abort_unless(...)` чи `AuthorizationException`) на закешованому
результаті не спрацюють. Переносьте їх у `canSee()` картки.

### Від чого ще залежить результат

Якщо `calculate()` залежить від чогось, крім користувача й періоду (поточна команда чи тенант,
роль, піддомен, сесія), додайте це в ключ, інакше користувач отримає число чужої області:

```php
// для однієї метрики
public function cacheKeyParts(MetricRequest $request): array
{
    return [$request->request->user()->current_team_id];
}

// для всіх метрик, у boot() провайдера застосунку
Larsadmin::metricCacheScopeUsing(fn (Request $request, Metric $metric) => tenant()?->getKey());
```

`cacheKeyParts()` повертає масив (за замовчуванням `[]`), callback `metricCacheScopeUsing()`
будь-яке значення. Обидва мають бути придатні для `json_encode`. `null` вимикає callback.

## Обмеження частоти запитів

Ендпоінти метрик обмежені іменованим лімітером `larsadmin-metrics`: `larsadmin.metrics.throttle`
запитів на хвилину (за замовчуванням `120`) на користувача, для гостей на IP. `null` знімає
обмеження. Кожна картка робить окремий запит, а зміна періоду ще один, тож для великих дашбордів
ліміт може знадобитися збільшити.

## `MetricRequest`

`calculate()` отримує `Maxcxam\Larsadmin\Metrics\MetricRequest`:

| Властивість | Опис |
| --- | --- |
| `$request->request` | поточний `Illuminate\Http\Request` |
| `$request->range` | перевірений ключ періоду (або `null`) |
| `$request->timezone` | часовий пояс панелі |
| `$request->now` | поточний момент (`CarbonImmutable`) у цьому поясі |

```php
[$from, $to] = [$request->now->startOfWeek(), $request->now];
```

## Ендпоінти

Картки завантажують дані з `GET {path}/api/dashboards/{dashboard}/metrics/{metric}?range=` і
`GET {path}/api/entities/{entity}/metrics/{metric}?range=`. Обидва потребують доступу до панелі.

| Ситуація | Відповідь |
| --- | --- |
| дашборд або картка невидимі (`canSee`), картка не є метрикою | 404 |
| немає `viewAny` для Entity, чиї картки запитуються | 403 |
| джерело Entity без `viewAny` (і при попаданні в кеш), `AuthorizationException` у `calculate()` | 403 |
| перевищено ліміт `larsadmin-metrics` | 429 |

## Відомі обмеження

- SQL метрик у CI перевіряється лише на SQLite. На SQLite колонки лише з датою (`DATE`) у трендах
  потрапляють у точку попереднього дня; використовуйте `DATETIME`/`TIMESTAMP`.
- Кожна картка завантажується окремим HTTP-запитом.

Далі: [Дашборди](14-dashboards.md).
