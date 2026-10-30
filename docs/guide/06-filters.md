# 06. Фільтри
🇬🇧 [English](en/06-filters.md)

Фільтри обмежують список записів Entity. Їхній стан зберігається в рядку запиту:
`?filters[<key>]=<value>`. Фільтри діють на index, на панелі `HasMany`/`BelongsToMany` та на дії
з `resources: 'all'` ([Дії](07-actions.md)).

Фільтри повертає метод `Entity::filters(Request $request): array`:

```php
use Maxcxam\Larsadmin\Filters\BooleanFilter;
use Maxcxam\Larsadmin\Filters\DateRangeFilter;
use Maxcxam\Larsadmin\Filters\SelectFilter;

public function filters(Request $request): array
{
    return [
        SelectFilter::make('Статус', 'status')->withOptions([
            'draft' => 'Чернетка',
            'live' => 'Опубліковано',
        ]),
        BooleanFilter::make('Опубліковано', 'published'),
        DateRangeFilter::make('Дата публікації', 'published_at', 'published-range'),
    ];
}
```

## Вбудовані фільтри

Усі три створюються як `Filter::make(?string $label, ?string $column, ?string $key)`:

- `$label` підпис в інтерфейсі (перекладається через `__()`);
- `$column` колонка моделі (кваліфікується таблицею моделі);
- `$key` ключ у рядку запиту. За замовчуванням це `$column` з `.` на `_`.

| Фільтр | Значення в запиті | Додатково |
| --- | --- | --- |
| `SelectFilter` | один із ключів `options` | `withOptions(array\|Closure)`, `defaultTo(string\|int\|null)` |
| `BooleanFilter` | `1` або `0` (Так/Ні) | `defaultTo(?bool)` |
| `DateRangeFilter` | `filters[key][from]=Y-m-d&filters[key][to]=Y-m-d` | напіввідкритий інтервал `[from, to + 1 день)`: `>= from` і `< to + 1 день`; кожну межу можна пропустити |

`withOptions()` приймає масив `значення => підпис` або замикання `fn (Request $request): array`:

```php
SelectFilter::make('Перегляди', 'views', 'v')->withOptions(fn () => [10 => 'Десять', 20 => 'Двадцять']);
```

### Значення за замовчуванням

Коли ключа фільтра немає в рядку запиту, застосовується його `defaultTo()`. Явне порожнє значення
(`filters[status]=`) скидає фільтр, і значення за замовчуванням тоді не застосовується. Саме так
інтерфейс очищає фільтр, що має значення за замовчуванням.

```php
SelectFilter::make('Статус', 'status')->withOptions([...])->defaultTo('live');
```

### Невидимі фільтри

`canSee(Closure)` ховає фільтр; його значення та значення за замовчуванням не застосовуються:

```php
SelectFilter::make('Внутрішній', 'status', 'internal')
    ->withOptions(['review' => 'На перевірці'])
    ->canSee(fn (Request $request) => $request->user()->can('moderate'));
```

## Фільтри це UX, а не межа безпеки

Фільтри зручні для користувача, але **не обмежують доступ до даних**. Фільтр, прихований через
`canSee`, перестає діяти разом зі своїм значенням за замовчуванням, а користувач може будь-коли
прибрати чи змінити значення в рядку запиту. Обмеження рядків (власні записи, тенант тощо) завжди
задавайте в `indexQuery` / `detailQuery` ([Авторизація](05-authorization.md)).

## Безпека значень

- Невідомі ключі, недозволені фільтри й некоректні значення **мовчки ігноруються** (виняток не
  кидається). Для select значення має бути серед ключів `options` і не довше 255 символів.
- Кожен фільтр застосовується в окремій групі `where (...)`, тому не може вийти за межі
  `indexQuery` Entity, навіть якщо його `apply()` використовує `orWhere`.

## Власний фільтр

```bash
php artisan larsadmin:filter StatusFilter
```

Опція `--force` перезаписує існуючий клас.
Клас створюється як `App\Larsadmin\Filters\StatusFilter`. Генератор записує заглушку з `__('{{ class }}')`
у `name()` і порожнім списком опцій; після редагування клас може виглядати так:

```php
namespace App\Larsadmin\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Filters\Filter;

class StatusFilter extends Filter
{
    public string $component = 'select-filter';

    public function name(): string
    {
        return __('Status');
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('status', $value);
    }

    public function options(Request $request): array
    {
        return [
            'draft' => 'Draft',
            'live' => 'Live',
        ];
    }
}
```

Підключення: `StatusFilter::make()`.

Методи базового класу `Maxcxam\Larsadmin\Filters\Filter`:

| Метод | Призначення |
| --- | --- |
| `apply($request, $query, $value): Builder` | **обов'язковий**; отримує вже нормалізоване значення |
| `name()` | підпис; за замовчуванням з назви класу |
| `key()` | ключ у запиті; за замовчуванням kebab-case назви класу (`StatusFilter` дає `status-filter`) |
| `options($request)` | варіанти `значення => підпис` |
| `default()` | значення, коли ключа немає в запиті |
| `normalize($request, $raw)` | перетворює сире значення; `null` означає «фільтр не активний» |
| `canSee(Closure)` | умова видимості |
| `$component` | компонент фронтенду: `select-filter`, `boolean-filter` або `date-range-filter` |

Заглушка генератора створює select-фільтр. Для іншої логіки значення перевизначте `normalize()` і `apply()`.
Власні компоненти фронтенду для нових типів фільтрів додати без зміни пакета поки не можна
(див. [Розширення](10-extending.md)).

Далі: [Дії](07-actions.md).
