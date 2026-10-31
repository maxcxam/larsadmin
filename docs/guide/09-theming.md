# 09. Теми
🇬🇧 [English](en/09-theming.md)

Інтерфейс панелі оформлений у стилі GitHub (Primer) і має три режими теми: **світла**, **темна**
та **системна**.

## Перемикач теми

Перемикач є в шапці панелі та на сторінці входу. Режими:

| Режим | Поведінка |
| --- | --- |
| Системна (за замовчуванням) | тема слідує за ОС (`prefers-color-scheme`) |
| Світла | примусово світла |
| Темна | примусово темна |

Вибір зберігається в `localStorage` браузера під ключем `larsadmin.theme` (значення `light` або
`dark`; для системної теми ключ видаляється). Якщо сховище недоступне (приватний режим), вибір
діє до перезавантаження сторінки.

Щоб уникнути «блимання», кореневий шаблон панелі виставляє збережену тему до завантаження стилів.

### Як це працює в розмітці

Тема це атрибут на `<html>`:

| Стан | Атрибут |
| --- | --- |
| світла | `data-theme="light"` |
| темна | `data-theme="dark"` |
| системна | атрибута немає, діє `@media (prefers-color-scheme: dark)` |

## Токени дизайну

Кольори задані CSS-змінними `--la-*` на `:root` (світла тема). Темні значення у вихідному
`resources/css/app.css` записані **один раз**, у блоці `@variant theme-dark` (власний варіант
Tailwind `@custom-variant theme-dark`). Під час збірки він розгортається у два селектори:
`:root[data-theme="dark"]` (примусово темна тема) та `:root:not([data-theme="light"])` усередині
`@media (prefers-color-scheme: dark)` (системна тема). Компоненти використовують токени через
Tailwind-псевдоніми `--color-*`.

| Група | Змінні |
| --- | --- |
| Текст | `--la-fg-default`, `--la-fg-muted`, `--la-fg-subtle`, `--la-fg-on-emphasis` |
| Поверхні | `--la-canvas-default`, `--la-canvas-subtle`, `--la-canvas-inset` |
| Межі | `--la-border-default`, `--la-border-muted` |
| Акцент | `--la-accent-fg`, `--la-accent-emphasis`, `--la-accent-subtle` |
| Успіх | `--la-success-fg`, `--la-success-emphasis`, `--la-success-emphasis-hover`, `--la-success-subtle` |
| Небезпека | `--la-danger-fg`, `--la-danger-emphasis`, `--la-danger-subtle` |
| Інше | `--la-neutral-muted`, `--la-header-bg`, `--la-header-fg`, `--la-overlay`, `--la-shadow` |
| Графіки | `--la-chart-1` … `--la-chart-8`: палітра секторів і ліній метрик |

Кольори графіків підібрані так, щоб мати контраст щонайменше 3:1 з фоном і світлої, і темної теми
(WCAG 1.4.11).

Кілька значень для орієнтира (світла / темна):

| Токен | Світла | Темна |
| --- | --- | --- |
| `--la-canvas-default` | `#ffffff` | `#0d1117` |
| `--la-fg-default` | `#1f2328` | `#f0f6fc` |
| `--la-accent-emphasis` | `#0969da` | `#1f6feb` |
| `--la-header-bg` | `#25292e` | `#010409` |

Шрифти: `--font-sans` (системний стек: `-apple-system`, `Segoe UI`, `Noto Sans`, ...) та `--font-mono`.
Базовий розмір шрифту 14px.

## CSS-контракт для розширень

З 1.5 токени `--la-*` вище і класи `larsadmin-input`, `larsadmin-input-invalid`, `larsadmin-checkbox`,
`larsadmin-btn`, `larsadmin-btn-primary`, `larsadmin-btn-secondary`, `larsadmin-btn-danger`,
`larsadmin-btn-sm`, `larsadmin-box`, `larsadmin-box-header`, `larsadmin-label`, `larsadmin-label-success`,
`larsadmin-label-accent`, `larsadmin-counter` є **стабільним контрактом 1.x**: розширення можуть на них
спиратися. Utility-класи Tailwind з `dist/app.css` контрактом не є.

Розширення збирає власні utility-класи з темою `vendor/maxcxam/larsadmin/kit/theme.css`: вона відображає
кольори Tailwind (`text-fg`, `bg-canvas`, `border-default`, `text-danger-fg`, …) на токени `--la-*`, тож
стилі розширення самі змінюються зі світлою й темною темою. Див.
[Розширення: стилі й теми](19-extensions.md#стилі-й-теми).

## Кастомізація

Публічного API для зміни самої теми панелі немає: пакет постачається зі зібраним CSS (`dist/app.css`,
публікується в `public/vendor/larsadmin`), а `larsadmin:publish` перезаписує асети.

Користувацькі стилі можна додати власним шаблоном. Кореневий шаблон панелі є звичайним
представленням `larsadmin::app`, тож його можна перевизначити стандартним механізмом Laravel
(`resources/views/vendor/larsadmin/app.blade.php`) і підключити свій CSS після `app.css`.
Це не підтримуваний API: структура CSS може змінюватися між версіями. Перевизначений шаблон має
зберегти import map і атрибути `nonce` шаблону пакета, інакше розширення не завантажаться: після
оновлення панелі порівняйте його з `resources/views/app.blade.php` пакета.

Далі: [Розширення](10-extending.md).
