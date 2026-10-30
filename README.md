# Larsadmin

Адмін-панель для Laravel у стилі Nova: Entity описує, як Eloquent-модель показується в панелі.
Inertia v3 + Vue 3, світла/темна/системна тема, інтерфейс українською (за замовчуванням) та англійською.

Вимоги: PHP 8.3+, Laravel 12 або 13.

## Встановлення

```bash
composer require maxcxam/larsadmin
php artisan larsadmin:install
```

## Мінімальний приклад

```bash
php artisan larsadmin:entity Post --model=Post
```

```php
// app/Larsadmin/Post.php: реєструється автоматично
namespace App\Larsadmin;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;

class Post extends Entity
{
    public static string $model = \App\Models\Post::class;
    public static string $title = 'title';
    public static array $search = ['id', 'title'];

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Title', 'title')->sortable()->rules('required', 'max:255'),
        ];
    }
}
```

Панель доступна на `/admin`. Поза `APP_ENV=local` доступ відкриває gate `viewLarsadmin`
(за замовчуванням закритий для всіх), див. `app/Providers/LarsadminServiceProvider.php`.

## Документація

Повні посібники (українською): [docs/guide](TODO(repo-url)/docs/guide/01-installation.md) у репозиторії.
Історія змін: [CHANGELOG.md](CHANGELOG.md).

## Ліцензія

MIT
