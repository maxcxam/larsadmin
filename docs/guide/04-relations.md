# 04. Зв'язки
🇬🇧 [English](en/04-relations.md) · 🌐 [larsadmin.dev](https://larsadmin.dev/uk/docs/relations)

Три поля працюють зі зв'язками Eloquent:

| Поле | Зв'язок у моделі | Де показується |
| --- | --- | --- |
| `BelongsTo` | `belongsTo()` | у списку, на перегляді й у формах (вибір запису) |
| `HasMany` | `hasMany()` | панель на сторінці перегляду |
| `BelongsToMany` | `belongsToMany()` | панель на сторінці перегляду з приєднанням і від'єднанням |

Усі три приймають `make(string $name, ?string $attribute = null, ?string $entity = null)`.
Атрибут це **назва методу зв'язку** в моделі (`author`, `posts`, `tags`). Третій аргумент клас
пов'язаної Entity. Якщо його не вказати, Entity шукається серед зареєстрованих за моделлю зв'язку;
не знайшлася, виникає `LogicException`. Метод моделі має повертати відповідний тип зв'язку,
інакше теж `LogicException`.

```php
BelongsTo::make('Автор', 'author', User::class)->searchable()->sortable()->nullable(),
HasMany::make('Дописи', 'posts', Post::class)->perPage(10),
BelongsToMany::make('Теги', 'tags', Tag::class)->searchable()->perPage(10),
```

## BelongsTo

Вибір пов'язаного запису у формі. У базу пишеться зовнішній ключ зв'язку
(`$relation->getForeignKeyName()`).

| Метод | Опис |
| --- | --- |
| `searchable(bool = true)` | пошук серед варіантів на сервері |
| `relatableQueryUsing(callable)` | звужує допустимі варіанти |
| `relatableLimit(int)` | кількість варіантів на сторінку вибору, 1–100, за замовчуванням 25 |
| `nullable()` | зв'язок необов'язковий |
| `sortable()` | сортування за `$title` пов'язаної Entity |

Без `nullable()` поле обов'язкове (правило `required`).

### Які записи можна вибрати

Варіанти і валідація йдуть через `relatableQuery` пов'язаної Entity (за замовчуванням її
`indexQuery`) і через її ability `viewAny`. Додаткове звуження на рівні поля:

```php
BelongsTo::make('Автор', 'author', User::class)
    ->relatableQueryUsing(function (Request $request, Builder $query, ?Model $record) {
        return $query->where('is_active', true); // повернення Builder необов'язкове
    });
```

Якщо запис, який уже збережено, вийшов за межі допустимих варіантів, незмінене значення
залишається валідним: форму можна зберегти.

### Прихований пов'язаний запис

Якщо пов'язаний запис існує, але потрапляє поза `detailQuery` пов'язаної Entity для цього запиту,
панель не показує його назву й посилання, лише позначку, що запис прихований. Перевірка виконується
одним запитом на сторінку, а не на кожен рядок.

Посилання на пов'язаний запис з'являється, лише якщо користувач може його переглядати (`view`).

### Параметри `via*`

Кнопка «Створити» в панелі `HasMany` веде на форму створення пов'язаної Entity з параметрами
`viaEntity`, `viaId`, `viaField`. Тоді відповідне `BelongsTo` попередньо заповнюється батьківським
записом і блокується, а після збереження користувача повертають на сторінку батька. Параметри
перевіряються: `viaField` має збігатися з атрибутом поля, `viaEntity` має бути пов'язаною Entity, а
запис `viaId` має бути допустимим варіантом (`relatableQuery` і `viewAny`). Недійсні параметри ігноруються й нічого не дозволяють самі по собі.

## HasMany

Панель зі списком дочірніх записів на сторінці перегляду. Список використовує колонки,
сортування, пошук і фільтри пов'язаної Entity та її `indexQuery`.

| Метод | Опис |
| --- | --- |
| `perPage(int)` | розмір сторінки панелі, 1–100, за замовчуванням 10 |

Кнопка створення показується, якщо пов'язана Entity дозволяє `create`. Щоб панель могла
передзаповнити батька, у пов'язаній Entity має бути поле `BelongsTo` на зворотний зв'язок
(доступне на формі створення й не `readonly`). Наприклад, `Category` має `HasMany::make('Дописи',
'posts', Post::class)`, а `Post` має `BelongsTo::make('Категорія', 'category', Category::class)`.

Панель не показується на index і формах, не заповнюється й не валідується.

## BelongsToMany

Панель приєднаних записів із приєднанням і від'єднанням (pivot). Має ті самі `perPage()`,
`searchable()`, `relatableQueryUsing()`, `relatableLimit()`.

- Приєднання: `POST {path}/entities/{entity}/{id}/attach/{field}` з полем `related`.
  Використовується `syncWithoutDetaching`, повторне приєднання того ж запису дає помилку
  валідації «Цей запис уже прикріплено.».
- Від'єднання: `DELETE {path}/entities/{entity}/{id}/attach/{field}/{relatedId}`.
- Варіанти вибору виключають уже приєднані записи.

### Права на приєднання

Права перевіряються за політикою **батьківської моделі** за назвами, у яких присутня назва
пов'язаної моделі в `StudlyCase` (за `class_basename`):

| Ability | Аргументи | Що перевіряє |
| --- | --- | --- |
| `attachAny{Model}` | `$user, $parent` | чи показувати кнопку й відкривати вибір |
| `attach{Model}` | `$user, $parent, $related` | чи можна приєднати конкретний запис |
| `detach{Model}` | `$user, $parent, $related` | чи можна від'єднати конкретний запис |

Приклад політики `app/Policies/PostPolicy.php` (пов'язана модель `Tag`):

```php
public function attachAnyTag(User $user, Post $post): bool { return true; }

public function attachTag(User $user, Post $post, Tag $tag): bool { return true; }

public function detachTag(User $user, Post $post, Tag $tag): bool { return true; }
```

Правила резервування:

- Якщо метод `attachAnyTag` (або `attachTag` / `detachTag`) у політиці **не визначено**, перевіряється
  ability `update` батьківського запису.
- Якщо у батьківської моделі **немає політики**, рішення за `larsadmin.allow_without_policy`.
- Додатково пов'язана Entity має дозволяти `viewAny`, інакше відповідь 403.

### Події

Після успішного приєднання чи від'єднання (у тій самій транзакції) викликаються події:

| Подія | Властивості |
| --- | --- |
| `Maxcxam\Larsadmin\Events\Attached` | `parent`, `relation`, `related`, `user` |
| `Maxcxam\Larsadmin\Events\Detached` | `parent`, `relation`, `related`, `user` |

```php
Event::listen(Attached::class, fn (Attached $e) => logger()->info("{$e->relation} attached", [
    'parent' => $e->parent->getKey(),
    'related' => $e->related->getKey(),
]));
```

## Пошук по зв'язку й URL записів

- Колонки пошуку виду `'author.email'` (`$search` Entity) шукають лише серед записів, які пропускає
  `indexQuery` **пов'язаної** Entity, якщо зв'язок відповідає зареєстрованій Entity. Приховані
  рядки не можна вгадати пошуком.
- Кожен серіалізований запис має серверні `urls`: `show`, `edit`, `destroy` (у рядках панелі
  `BelongsToMany` ще `detach`). Значення `null` означає, що дії немає: користувач не має права, або
  ключ запису містить символи, небезпечні для шляху (`/`, `\`, `.`, `..`, керуючі символи). Клієнт
  використовує лише ці URL.
- Після збереження за параметрами `via*` користувача повертають на сторінку батька. Якщо батька
  можна вибрати, але не можна переглядати, редірект іде на сторінку самого запису або на список.

## Підсумок: що контролює Entity

| Питання | Де налаштовується |
| --- | --- |
| Які записи видно в панелі | `indexQuery` / `detailQuery` пов'язаної Entity |
| Які записи можна вибрати | `relatableQuery` пов'язаної Entity і `relatableQueryUsing()` поля |
| Хто може приєднати/від'єднати | політика батьківської моделі |

Далі: [Авторизація](05-authorization.md).
