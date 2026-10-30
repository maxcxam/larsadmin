# 02. Entities
🇬🇧 [English](en/02-entities.md)

Entity описує, як модель показується в панелі: які поля, за чим шукати, які записи бачить користувач.
Клас розширює `Maxcxam\Larsadmin\Entity`.

Якщо ви знайомі з Nova, Entity відповідає Resource.

## Генератор

```bash
php artisan larsadmin:entity Post --model=Post
```

- Клас створюється як `App\Larsadmin\Post`.
- Без `--model` модель береться за назвою класу (`Post` дає `App\Models\Post`).
- `--force` перезаписує існуючий клас.

Класи з `app/Larsadmin` (включно з вкладеними каталогами) реєструються автоматично в `boot()`
провайдера `App\Providers\LarsadminServiceProvider`. Вручну:

```php
use Maxcxam\Larsadmin\Larsadmin;

Larsadmin::entities([\App\Larsadmin\Post::class]);
Larsadmin::entitiesIn(app_path('Domain/Admin')); // каталог усередині app/
```

## Мінімальна Entity

```php
namespace App\Larsadmin;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;

class Category extends Entity
{
    public static string $model = \App\Models\Category::class;
    public static string $title = 'name';
    public static array $search = ['id', 'name', 'slug'];
    public static ?string $group = 'Контент';
    public static ?string $label = 'Категорії';
    public static ?string $singularLabel = 'Категорія';

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Назва', 'name')->sortable()->rules('required', 'max:255'),
        ];
    }
}
```

`fields()` єдиний обов'язковий метод.

## Статичні властивості

| Властивість | Тип, значення за замовчуванням | Опис |
| --- | --- | --- |
| `$model` | `class-string<Model>`, обов'язкова | Модель Eloquent |
| `$title` | `string`, `'id'` | Атрибут, що є назвою запису (читається через `data_get`) |
| `$search` | `array`, `['id']` | Що шукати (див. нижче). Порожній масив вимикає пошук |
| `$with` | `array`, `[]` | Зв'язки, які завжди завантажуються на index і detail |
| `$label` | `?string`, `null` | Множина для навігації та заголовків; перекладається через `__()`. Якщо `null`, береться множина від назви класу |
| `$singularLabel` | `?string`, `null` | Однина; перекладається через `__()`. Якщо `null`, виводиться з назви класу |
| `$group` | `?string`, `null` | Група в навігації |
| `$sort` | `array`, `[]` | Сортування за замовчуванням, коли в запиті немає коректного `sort`: `['published_at' => 'desc']` |
| `$perPage` | `int`, `25` | Розмір сторінки за замовчуванням |
| `$perPageOptions` | `array`, `[25, 50, 100]` | Дозволені розміри сторінки (`?perPage=`). Інше значення замінюється на `$perPage` |
| `$displayInNavigation` | `bool`, `true` | Показувати в бічній навігації |

Зв'язки, які потрібні полям (наприклад, `BelongsTo`), завантажуються автоматично. `$with` потрібен
лише для додаткових зв'язків, скажімо, якщо `$title` вказує на `author.name`.

### Сортування за замовчуванням

```php
public static array $sort = ['published_at' => 'desc'];
```

Ключі це атрибути полів, значення `asc` або `desc`. Кожен атрибут має належати полю з `->sortable()`,
доступному користувачеві (`canSee`), інакше кидається `InvalidArgumentException`. Сортування з
`?sort=` має пріоритет; останнім тайбрейкером завжди йде ключ запису. Інтерфейс отримує значення як
`query.defaultSort` і показує його як поточне сортування.

### URI key

`Entity::uriKey()` формує сегмент URL: множина в kebab-case від назви класу (`BlogPost` дає
`blog-posts`, адреса `/admin/entities/blog-posts`). Перевизначте статичний метод, щоб змінити ключ.

### Пошук

Елементи `$search`:

| Запис | Поведінка |
| --- | --- |
| `'title'` | `LIKE` по колонці |
| `'id'` (ім'я ключа моделі) | рівність; для цілого ключа береться лише цифровий термін |
| `'author.name'` | `relation.column`: один рівень, через `orWhereHas` |

Термін обрізається по пробілах і до 100 символів.

Для `relation.column` пошук виконується лише серед записів, які пропускає `indexQuery` пов'язаної
Entity (якщо зв'язок відповідає зареєстрованій Entity), тож приховані пов'язані рядки не
розкриваються.

### URL записів

Серіалізований запис містить `urls` (`show`, `edit`, `destroy`), зібрані на сервері. Значення
`null`, коли дія недозволена або ключ запису небезпечний для шляху. Метод `$entity->url('show')`
повертає відносний URL або `null`.

## Обмеження рядків: `indexQuery`, `detailQuery`, `relatableQuery`

Три статичні хуки визначають, які рядки бачить користувач.

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

public static function indexQuery(Request $request, Builder $query): Builder
{
    return $query->where('status', 'live');
}
```

| Метод | Для чого | За замовчуванням |
| --- | --- | --- |
| `indexQuery($request, $query)` | список (і панелі `HasMany`/`BelongsToMany`) | повертає `$query` без змін |
| `detailQuery($request, $query)` | один запис: перегляд, редагування, оновлення, видалення, дії над вибраними | викликає `indexQuery` |
| `relatableQuery($request, $query)` | записи, які можна вибрати в `BelongsTo`/`BelongsToMany` і які приймає валідація | викликає `indexQuery` |

`indexQuery` має відповідати тому, які рядки користувач може бачити, а `detailQuery` і
`relatableQuery` успадковують це. Перевизначайте їх, лише щоб послабити чи посилити обмеження.
Запис поза `detailQuery` дає 404.

## Дії та фільтри

Необов'язкові методи екземпляра (за замовчуванням повертають порожній масив):

```php
public function actions(Request $request): array { return [new MarkLive]; }
public function filters(Request $request): array { return [SelectFilter::make('Статус', 'status')->withOptions([...])]; }
```

Див. [Фільтри](06-filters.md) і [Дії](07-actions.md).

## Картки над списком

`cards()` повертає метрики, які показуються над таблицею списку Entity (за замовчуванням `[]`):

```php
public function cards(Request $request): array
{
    return [NewPosts::make(), PostsByStatus::make()];
}
```

Див. [Дашборди](14-dashboards.md#картки-над-списком-entity).

## Блоки полів

`fields()` може повертати не лише поля, а й блоки `Panel` і `Tabs`. Див. [Блоки полів](12-layout.md).

## Назва запису

`$entity->title()` повертає `(string) data_get($record, static::$title)`. Це підпис запису в
заголовках, хлібних крихтах і списках вибору зв'язків.

## Авторизація

Entity перевіряє політику моделі: `viewAny`, `create`, `view`, `update`, `delete`. Деталі в
[Авторизація](05-authorization.md).

Далі: [Поля](03-fields.md).
