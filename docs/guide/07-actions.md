# 07. Дії
🇬🇧 [English](en/07-actions.md)

Дія виконує операцію над вибраними записами (або над усіма, що відповідають поточному фільтру й
пошуку). Дії доступні з меню списку (index), з меню «⋯» окремого рядка списку та зі сторінки
перегляду запису (detail).

Дії повертає метод `Entity::actions(Request $request): array`:

```php
public function actions(Request $request): array
{
    return [
        new \App\Larsadmin\Actions\MarkLive,
        \App\Larsadmin\Actions\Archive::make()->destructive(),
    ];
}
```

## Генератор

```bash
php artisan larsadmin:action MarkLive
php artisan larsadmin:action Archive --destructive
```

Клас створюється як `App\Larsadmin\Actions\MarkLive`. Опції: `--destructive` (проставляє
`$destructive = true`) і `--force` (перезаписати).

## Приклад

```php
namespace App\Larsadmin\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Fields\Text;

class MarkLive extends Action
{
    public function name(): string
    {
        return __('Mark live');
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('Коментар', 'note')->nullable()->rules('max:255'),
        ];
    }

    public function handle(ActionFields $fields, Collection $models): ?ActionResponse
    {
        foreach ($models as $model) {
            $model->update(['status' => 'live']);
        }

        return ActionResponse::message(__('Done.'));
    }
}
```

`handle()` єдиний обов'язковий метод. Значення полів модального вікна доступні як
`$fields->get('note')` або `$fields->note` (`ActionFields` це `Illuminate\Support\Fluent`).

## API дії

Налаштовується властивостями або однойменними методами в ланцюжку:

| Властивість / метод | За замовчуванням | Опис |
| --- | --- | --- |
| `name()` | назва класу в Title Case | підпис у інтерфейсі (через `__()`) |
| `uriKey()` | kebab-case назви класу | сегмент URL дії; у межах Entity береться перша дія з таким ключем |
| `fields(Request)` | `[]` | поля модального вікна (використовуються як поля форми створення, з правилами валідації) |
| `destructive` / `destructive()` | `false` | небезпечна дія: червона кнопка підтвердження |
| `standalone` / `standalone()` | `false` | дія без вибраних записів |
| `confirmText()`, `confirmButtonText()`, `cancelButtonText()` | з `larsadmin::actions.*` | тексти підтвердження |
| `withoutConfirmation()` | `null` | пропустити модальне вікно. За `null` пропускається, лише якщо дія не destructive, без `confirmText` і без полів |
| `showOnIndex` / `showOnIndex(bool $show = true)` | `true` | показувати в меню дій списку (масові дії) |
| `showOnDetail` / `showOnDetail(bool $show = true)` | `true` | показувати в меню дій сторінки перегляду |
| `showInline` / `showInline(bool $inline = true)` | `false` | показувати в меню «⋯» кожного рядка списку |
| `onlyOnIndex()`, `onlyOnDetail()` | | лише в меню списку / лише на сторінці перегляду; `showInline` не змінюють |
| `onlyInline()` | | лише в меню «⋯» рядка: `showOnIndex = false`, `showOnDetail = false`, `showInline = true` |
| `chunkSize` | `200` | розмір пакета записів |
| `canSee(Closure)` | | `fn (Request $request): bool`, чи показувати дію |
| `canRun(Closure)` | | `fn (Request $request, ?Model $model): bool`; `null` передається для standalone-дії |

## Дії для рядка й сторінки запису

Дію можна запускати для одного запису без виділення рядків:

- **Меню «⋯» рядка.** Дії з `showInline` з'являються в меню «⋯» праворуч у кожному рядку списку, поруч
  із кнопками перегляду, редагування й видалення. Небезпечні (`destructive`) дії стоять в кінці меню.
- **Сторінка перегляду.** Дії з `showOnDetail` (за замовчуванням усі) доступні в меню дій запису.

```php
public function actions(Request $request): array
{
    return [
        PublishPost::make()->showInline(),             // меню списку, сторінка запису й «⋯» рядка
        ArchivePost::make()->onlyInline(),            // лише «⋯» рядка
        ExportPostsCsv::make()->standalone()->onlyOnIndex(), // лише меню списку
    ];
}
```

`standalone`-дії не мають запису, тому ніколи не з'являються в меню рядка й не перевіряються для запису.

Кнопка «⋯» має доступну назву з назвою запису («Дії для «Перший допис»»), а меню біля нижнього краю
екрана відкривається вгору.

### Дії лише для одного запису

Дія, якої немає в меню списку (`showOnIndex` дорівнює `false`, тобто `onlyInline()`, `onlyOnDetail()`
або `showOnIndex(false)`) і яка не є `standalone`, виконується **рівно для одного запису**
(`Action::runsOnSingleRecord()`). Запит з `resources: 'all'` або з кількома ідентифікаторами
відхиляється з 422 («Цю дію можна виконати лише для одного запису.»). Для інших дій `handle()` має
бути готовий до будь-якої кількості записів.

### Доступність для кожного запису

Сервер заздалегідь визначає, які дії можна запустити на кожному рядку й на відкритому записі:

| Де | Що показується |
| --- | --- |
| меню «⋯» рядка | лише дії, доступні для цього запису. Якщо жодної, кнопки «⋯» немає |
| меню сторінки перегляду | лише дії, доступні для цього запису; недоступні приховані |

Доступність перевіряє та сама логіка, що й під час виконання (див. «Авторизація» нижче):

- **Без `canRun()`** і без власного `authorizedToRun()` дія доступна, якщо користувач може
  редагувати запис (`update`). Це право панель уже обчислила для кнопки редагування, тож додаткових
  викликів політики немає.
- **З `canRun()`** замикання викликається для кожного рядка сторінки. Воно має працювати на вже
  завантаженій моделі **без запитів до бази**: потрібні зв'язки завантажте через `Entity::$with`.
  Інакше кожен рядок додасть запит (N+1).

```php
PublishPost::make()
    ->onlyInline()
    ->canRun(fn (Request $request, ?Model $post) => $post === null || ! $post->published),
```

Попередня перевірка лише керує інтерфейсом. Під час запуску сервер перевіряє права ще раз
(див. «Авторизація»), тож запит в обхід меню не виконає дію на забороненому записі.

> Дії в панелях зв'язків (`HasMany`, `BelongsToMany`) поки що не мають меню рядка.

## Відповіді

`handle()` повертає `?ActionResponse`. `null` дає повідомлення «Дію виконано.»

| Метод | Результат |
| --- | --- |
| `ActionResponse::message(string)` | повернення назад із повідомленням про успіх |
| `ActionResponse::danger(string)` | повернення назад із повідомленням про помилку |
| `ActionResponse::redirect(string $url)` | перехід на URL |
| `ActionResponse::download(string $url, string $filename)` | браузер завантажує файл |

`redirect()` і `download()` приймають лише **внутрішні** URL: відносний шлях, що починається з одного
`/`, або абсолютний `http(s)` URL на поточному хості. Інакше кидається `InvalidArgumentException`.

```php
return ActionResponse::download('/exports/posts.csv', 'posts.csv');
```

## Як виконується дія

Запит: `POST {path}/entities/{entity}/actions/{action}`.

| Поле запиту | Значення |
| --- | --- |
| `resources` | `all` або непорожній список ідентифікаторів. Максимум списку дорівнює найбільшому з `Entity::$perPageOptions` (за замовчуванням 100). Не потрібне для `standalone` |
| `filters`, `search` | враховуються, лише коли `resources` дорівнює `all` |
| поля дії | значення з `fields()` |

- **`resources: 'all'`** вибирає всі записи в області `indexQuery` з активними фільтрами й пошуком.
  Ліміт: `config('larsadmin.actions.max_all')` (за замовчуванням `1000`). Якщо знайдено більше,
  відповідь 422 (помилка валідації `resources`), і користувач має звузити пошук чи фільтри.
- **Список ідентифікаторів** розв'язується через `detailQuery`.
- Записи обробляються пакетами по `chunkSize` (`chunkById`), **кожен пакет у власній транзакції**.
  `handle()` викликається окремо для кожного пакета.
- `standalone`-дія викликається один раз з порожньою колекцією.

### Часткове виконання й помилки

Транзакція діє на пакет, а не на всю дію. Якщо пакет кидає виняток:

- на першому пакеті нічого не збережено, виняток обробляється як звичайно;
- на наступних уже збережені пакети лишаються. Виняток логується через `report()`, а користувач бачить
  повідомлення про помилку: «Виконано для :done з :total, далі помилка.»

Тому `handle()` має бути ідемпотентним, щоб дію можна було повторити.

### Яку відповідь побачить користувач

Якщо різні пакети повернули різні відповіді, береться **найсуворіша**: `danger`, потім
`redirect`/`download`, потім `message`. За однакової суворості перемагає раніша.

| Ситуація | Результат |
| --- | --- |
| Жоден запис не знайдено за вибором | повідомлення про помилку «Жодного запису не знайдено.» |
| Записи знайдено, але жоден не пройшов `authorizedToRun()` | 403 |
| Частину записів пропущено | успішне повідомлення з доповненням «N записів пропущено» |

## Авторизація

1. Користувач має мати `viewAny` для Entity.
2. `canSee()` ховає дію; прихована дія дає 404.
3. Для кожного запису викликається `authorizedToRun()`. За замовчуванням це ability `update` для запису
   (через політику, див. [Авторизація](05-authorization.md)); `canRun()` замінює цю перевірку.
4. Записи, що не пройшли перевірку, **пропускаються** (див. таблицю вище).
5. **`standalone`-дії** немає запису для перевірки, тому викликається `authorizedToRunStandalone()`:
   якщо задано `canRun()`, воно викликається з `null` замість моделі; інакше потрібне право
   `create` для Entity. Без права відповідь 403 (fail closed). Тому `canRun` для standalone-дії має
   допускати `null`.

```php
MarkLive::make()
    ->canSee(fn (Request $request) => $request->user()->can('moderate'))
    ->canRun(fn (Request $request, ?Model $post) => $post === null || $post->status !== 'live');
```

## Поля `BelongsTo` у модальному вікні

Поле `BelongsTo` можна використовувати в `fields()` дії. Варіанти беруться з ендпоінта
`GET {path}/api/entities/{entity}/fields/{field}/options?context=action&action=<uriKey дії>`;
діють `relatableQuery` пов'язаної Entity та її `viewAny`, а видимість дії визначає `canSee()`.
Значення валідується `RelatableExists`, як і у формах. У `ActionFields` потрапляє зовнішній ключ.

Далі: [Локалізація](08-i18n.md).
