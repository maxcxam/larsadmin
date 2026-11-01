# 10. Розширення: огляд
🇬🇧 [English](en/10-extending.md)

Панель розширюється на двох рівнях:

- **PHP-класи**, які ви вже наслідуєте в застосунку (Entity, дії, фільтри, метрики, події). Для них
  не потрібен JavaScript, а сторінки нижче описують кожен.
- **Публічний API розширень** (з 1.5): власні Vue-компоненти полів, карток, фільтрів і сторінок
  меню, власні маршрути й переклади, перевизначення інтерфейсу вбудованих компонентів. Розширення є
  Composer-пакетом (або каталогом у застосунку) і підключається без перезбирання панелі. Посібник:
  [Розширення: публічний API](19-extensions.md).

## Що можна розширювати

| Що | Базовий клас чи API | Розділ |
| --- | --- | --- |
| Entity | `Maxcxam\Larsadmin\Entity` | [Сутності](02-entities.md) |
| Дія | `Maxcxam\Larsadmin\Actions\Action` | [Дії](07-actions.md) |
| Фільтр | `Maxcxam\Larsadmin\Filters\Filter` | [Фільтри](06-filters.md) |
| Метрика | `Maxcxam\Larsadmin\Metrics\Value`, `Trend`, `Partition`, `Progress` | [Метрики](13-metrics.md) |
| Дашборд | `Maxcxam\Larsadmin\Dashboards\Dashboard` | [Дашборди](14-dashboards.md) |
| Видалення запису з файлами | `Entity::deleteRecord(Request)` у власних діях | [Файли й зображення](15-files.md#видалення-записів) |
| Глобальний пошук через Scout | `Entity::usesScout()`, `Entity::scoutQuery()` | [Глобальний пошук](16-search.md#laravel-scout) |
| Реакція на записи журналу аудиту | подія `Maxcxam\Larsadmin\Events\AuditRecorded` | [Журнал аудиту](17-audit-log.md#подія-auditrecorded) |
| Сповіщення в дзвіночку панелі | `Maxcxam\Larsadmin\Notifications\LarsadminNotification` (можна наслідувати) | [Сповіщення](18-notifications.md#надсилання-сповіщень) |
| Фонова дія | `Action::$queued`, `queued()`, `onQueue()`, `onQueueConnection()`, `$timeout` | [Сповіщення: фонові дії](18-notifications.md#фонові-дії) |
| Реакція на завершення фонової дії | подія `Maxcxam\Larsadmin\Events\ActionRunFinished`, модель `Maxcxam\Larsadmin\Actions\Queued\ActionRun` (лише читання) | [Сповіщення](18-notifications.md#подія-actionrunfinished) |
| Поле з вбудованим компонентом | `Maxcxam\Larsadmin\Fields\Field` | нижче |
| Поле з власним Vue-компонентом | `Field` + `defineExtension({ fields })` | [Власні поля](19-extensions.md#власні-поля) |
| Картка (статична чи з даними) | `Maxcxam\Larsadmin\Metrics\Card`, `DataCard` | [Картки](19-extensions.md#картки) |
| Фільтр з власним компонентом | `Filter` + `defineExtension({ filters })` | [Фільтри](19-extensions.md#фільтри) |
| Сторінка в меню | `Maxcxam\Larsadmin\Tools\Tool` | [Tools](19-extensions.md#tools-сторінки-в-меню) |
| Власні HTTP-маршрути | `Larsadmin::routes()`, `findRecord()`, `findField()`, `audited()` | [Маршрути розширення](19-extensions.md#маршрути-розширення) |
| Інтерфейс вбудованого поля, фільтра чи метрики | `override: true` | [Перевизначення](19-extensions.md#перевизначення-вбудованих-компонентів) |

## Поле з вбудованим компонентом

Власний клас поля, який лише змінює серверну поведінку (правила, заповнення, форматування), може
використовувати компонент одного з вбудованих полів. Наприклад, спадкоємець `Text` використовує
`text-field`:

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

З 1.5 захищені хуки `Field` (`resolveValue()`, `formatForDisplay()`, `fillValue()`, `fillString()`,
`typeRules()`, `implicitRules()`, `acceptsNullInput()`, `defaultAlign()`), публічні хуки й
`jsonSerialize()` є **стабільним API** для спадкоємців (повний список у
[Власних полях](19-extensions.md#php-клас)). Поле з власним Vue-компонентом описано там само.

### Власні поля з масивом і форми з файлами

Форма, у якій є поле `File` чи `Image` або поле з `multipart: true` (див.
[Поля, що надсилають файли](19-extensions.md#поля-що-надсилають-файли)), надсилається як
`multipart/form-data` (див. [Файли й зображення](15-files.md)). У такому запиті порожній масив не
передається зовсім: якщо користувач очистив власне поле, значення якого масив, ключ поля відсутній, і
`fill()` лишає старе значення. У JSON-формі без таких полів те саме поле отримує `[]`. Вбудовані поля
це не зачіпає. Якщо ваше поле приймає масив, або надсилайте разом з ним прапорець присутності (як
`present` у полів файлів), або вважайте відсутній ключ порожнім масивом.

## Реєстр компонентів

Серверний ключ `component` поля, фільтра чи картки зіставляється з Vue-компонентом через реєстр панелі.
З 1.5 реєстр відкритий для розширень через `defineExtension()` модуля `larsadmin`, а вбудовані
компоненти реєструються в ньому тим самим механізмом. Внутрішні функції `registerField`,
`registerFilter` і `registerCard` лишилися обгортками й API не є.

Як панель обробляє ключ:

| Ключ | Поведінка |
| --- | --- |
| вбудований (`text-field`, `select-filter`, …) | вбудований компонент або його [перевизначення](19-extensions.md#перевизначення-вбудованих-компонентів) |
| `vendor/name`, зареєстрований розширенням | компонент розширення |
| `vendor/name`, не зареєстрований (розширення не завантажилося, несумісне чи впало) | placeholder «Недоступно»; у формі значення **не надсилається** ([fail closed](19-extensions.md#недоступні-й-зламані-розширення)) |
| без `/`, невідомий панелі | поле як звичайний текст (display) і текстовий input (форма), фільтр як select, як у 1.4; картка показує помилку «Невідомий компонент картки: …» |

Ключі вбудованих компонентів:

| Тип | Ключі |
| --- | --- |
| Поля | `id-field`, `text-field`, `textarea-field`, `number-field`, `boolean-field`, `select-field`, `date-field`, `datetime-field`, `password-field`, `file-field`, `image-field`, `belongs-to-field`, `has-many-field`, `belongs-to-many-field` |
| Фільтри | `select-filter`, `boolean-filter`, `date-range-filter` |
| Картки | `value-metric`, `trend-metric`, `partition-metric`, `progress-metric` |

Як зібрати й протестувати сам пакет: розділ «Розробка пакета» в [Встановлення](01-installation.md#розробка-пакета).

Далі: [Інтерфейс списків](11-ui.md).
