# 10. Розширення
🇬🇧 [English](en/10-extending.md)

> **Публічного API для розширення фронтенду в 1.0 немає.** Він запланований у наступних версіях.
> Усе, що описано нижче, внутрішнє й може змінитися без попередження та без запису в CHANGELOG.

## Що можна розширювати в PHP

Бекенд побудований на звичайних PHP-класах, які ви вже наслідуєте:

| Що | Базовий клас | Розділ |
| --- | --- | --- |
| Entity | `Maxcxam\Larsadmin\Entity` | [Entities](02-entities.md) |
| Дія | `Maxcxam\Larsadmin\Actions\Action` | [Дії](07-actions.md) |
| Фільтр | `Maxcxam\Larsadmin\Filters\Filter` | [Фільтри](06-filters.md) |
| Метрика | `Maxcxam\Larsadmin\Metrics\Value`, `Trend`, `Partition`, `Progress` | [Метрики](13-metrics.md) |
| Дашборд | `Maxcxam\Larsadmin\Dashboards\Dashboard` | [Дашборди](14-dashboards.md) |
| Видалення запису з файлами | `Entity::deleteRecord(Request)` у власних діях | [Файли й зображення](15-files.md#видалення-записів) |
| Глобальний пошук через Scout | `Entity::usesScout()`, `Entity::scoutQuery()` | [Глобальний пошук](16-search.md#laravel-scout) |
| Реакція на записи журналу аудиту | подія `Maxcxam\Larsadmin\Events\AuditRecorded` | [Журнал аудиту](17-audit-log.md#подія-auditrecorded) |
| Поле | `Maxcxam\Larsadmin\Fields\Field` | нижче |

Власний клас поля працює на сервері (правила, заповнення, серіалізація), але має вказати
`$component` одного з **наявних** компонентів фронтенду, бо пакет постачається зі зібраним бандлом.
Наприклад, спадкоємець `Text` використовує `text-field`.

```php
use Maxcxam\Larsadmin\Fields\Text;

class Slug extends Text
{
    protected function formatForDisplay(mixed $value): mixed
    {
        return $value === null ? null : '/'.$value;
    }
}
```

Захищені методи `Field` (`resolveValue`, `formatForDisplay`, `fillValue`, `typeRules`) призначені для
спадкоємців, але формально не належать до стабільного API.

### Власні поля з масивом і форми з файлами

Форма, у якій є поле `File` чи `Image`, надсилається як `multipart/form-data` (див.
[Файли й зображення](15-files.md)). У такому запиті порожній масив не передається зовсім: якщо
користувач очистив власне поле, значення якого масив, ключ поля відсутній, і `fill()` лишає старе
значення. У JSON-формі без полів файлів те саме поле отримує `[]`. Вбудовані поля це не зачіпає.
Якщо ваше поле приймає масив, або надсилайте разом з ним прапорець присутності (як `present` у полів
файлів), або вважайте відсутній ключ порожнім масивом.

## Внутрішній реєстр компонентів

У вихідному коді фронтенду (`resources/js/fields/index.js`) є функція `registerField`, яка зіставляє
ключ `component` серверного поля з Vue-компонентами:

```js
registerField(component, { display, form, panel })
```

Аналогічно `registerFilter(component, vueComponent)` у `resources/js/filters/index.js` і
`registerCard(component, vueComponent)` у `resources/js/components/cards/index.js`.

Що це означає на практиці:

- Реєстри **внутрішні**: вони живуть усередині бандла пакета й не експортуються в `window`.
- З застосунку викликати їх не можна. Власні Vue-компоненти потрапляють у панель лише через
  зміну коду пакета й нову збірку (`npm run build` у репозиторії пакета).
- Невідомий ключ `component` ніде не помиляється: поле відображається як звичайний текст
  (display) і текстовий input (форма), фільтр як select. Картка з невідомим ключем показує видиму помилку «Невідомий компонент картки: …».

Ключі вбудованих компонентів:

| Тип | Ключі |
| --- | --- |
| Поля | `id-field`, `text-field`, `textarea-field`, `number-field`, `boolean-field`, `select-field`, `date-field`, `datetime-field`, `password-field`, `file-field`, `image-field`, `belongs-to-field`, `has-many-field`, `belongs-to-many-field` |
| Фільтри | `select-filter`, `boolean-filter`, `date-range-filter` |
| Картки | `value-metric`, `trend-metric`, `partition-metric`, `progress-metric` |

## Що заплановано

Публічний API розширення (підключення власних Vue-компонентів полів, фільтрів і карток зі
застосунку, без перезбирання пакета) планується в наступних версіях. До цього орієнтуйтеся лише на задокументовані розділи
цього посібника.

Як зібрати й протестувати сам пакет: розділ «Розробка пакета» в [Встановлення](01-installation.md#розробка-пакета).
