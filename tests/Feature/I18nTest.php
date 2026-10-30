<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Translation\Translator;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Console\InstallCommand;
use Maxcxam\Larsadmin\Fields\Date;
use Maxcxam\Larsadmin\Fields\DateTime;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([Entities\Post::class, Entities\LabelledPost::class]);

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
});

function inertiaHeaders(array $extra = []): array
{
    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) Larsadmin::assetsVersion()] + $extra;
}

it('defaults to uk on panel routes only', function () {
    app()->setLocale('en');
    app('router')->middleware('web')->get('/host-page', fn () => app()->getLocale());

    $this->get('/host-page')->assertSee('en');

    $this->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('larsadmin.locale', 'uk')
        ->where('larsadmin.locales', [['code' => 'uk', 'label' => 'Українська'], ['code' => 'en', 'label' => 'English']])
        ->where('larsadmin.translations.save', 'Зберегти'));

    app()->setLocale('en');
    $this->get('/host-page')->assertSee('en');
});

it('keeps the application locale when the config locale is null', function () {
    config(['larsadmin.locale' => null]);
    app()->setLocale('en');

    $this->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('larsadmin.locale', 'en')
        ->where('larsadmin.translations.save', 'Save'));
});

it('resolves the locale: callback, session, config, app', function () {
    $request = request();
    $request->setLaravelSession(app('session.store'));

    expect(Larsadmin::locale($request))->toBe('uk');

    config(['larsadmin.locale' => null]);
    app()->setLocale('en');
    expect(Larsadmin::locale($request))->toBe('en');

    config(['larsadmin.locale' => 'uk']);
    $request->session()->put('larsadmin.locale', 'en');
    expect(Larsadmin::locale($request))->toBe('en');

    Larsadmin::resolveLocaleUsing(fn () => 'uk');
    expect(Larsadmin::locale($request))->toBe('uk');
});

it('ignores a session locale that is not configured', function () {
    $this->withSession(['larsadmin.locale' => 'xx'])->get('/admin')
        ->assertInertia(fn (Assert $page) => $page->where('larsadmin.locale', 'uk'));
});

it('lets host translations override the package ones', function () {
    app('translator')->addLines(['ui.save' => 'Записати'], 'uk', 'larsadmin');

    $this->get('/admin')->assertInertia(fn (Assert $page) => $page->where('larsadmin.translations.save', 'Записати'));
});

it('switches the locale and serves the new translations right after', function () {
    $this->from('/admin/entities/posts')
        ->post('/admin/locale', ['locale' => 'en'])
        ->assertRedirect('/admin/entities/posts')
        ->assertSessionHas('larsadmin.locale', 'en');

    $this->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('larsadmin.locale', 'en')
        ->where('larsadmin.translations.save', 'Save'));
});

it('refreshes once-translations after a switch even if the client holds the old copy', function () {
    // The client reports it already holds the uk copy.
    $held = inertiaHeaders(['X-Inertia-Except-Once-Props' => 'larsadmin.translations.uk']);

    $this->get('/admin', $held)->assertOk()->assertJsonMissingPath('props.larsadmin.translations');

    $this->post('/admin/locale', ['locale' => 'en'])->assertRedirect();

    $response = $this->get('/admin', $held)->assertOk();

    expect($response->json('props.larsadmin.translations.save'))->toBe('Save')
        ->and($response->json('props.larsadmin.locale'))->toBe('en')
        ->and($response->json('onceProps'))->toHaveKey('larsadmin.translations.en');
});

it('validates the locale update', function () {
    $this->post('/admin/locale', ['locale' => 'fr'])->assertSessionHasErrors('locale');
    $this->post('/admin/locale', [])->assertSessionHasErrors('locale');
    $this->post('/admin/locale', ['locale' => ['en']])->assertSessionHasErrors('locale');

    expect(session('larsadmin.locale'))->toBeNull();
});

it('falls back to the dashboard when there is no referrer', function () {
    $this->post('/admin/locale', ['locale' => 'en'])->assertRedirect('/admin');
});

it('allows switching the locale as a guest', function () {
    auth()->logout();
    Larsadmin::auth(fn () => false);

    $this->from('/admin/login')->post('/admin/locale', ['locale' => 'en'])
        ->assertRedirect('/admin/login')
        ->assertSessionHas('larsadmin.locale', 'en');

    $this->get('/admin/login')->assertInertia(fn (Assert $page) => $page->where('larsadmin.locale', 'en'));
});

it('throttles the locale endpoint', function () {
    foreach (range(1, 30) as $ignored) {
        $this->post('/admin/locale', ['locale' => 'en'])->assertRedirect();
    }

    $this->post('/admin/locale', ['locale' => 'en'])->assertStatus(429);
});

it('translates toasts', function () {
    $this->post('/admin/entities/posts', ['title' => 'A', 'status' => 'live'])
        ->assertSessionHas('larsadmin.toast', ['message' => 'Запис створено.', 'type' => 'success']);

    $this->withSession(['larsadmin.locale' => 'en'])
        ->put('/admin/entities/posts/1', ['title' => 'B', 'status' => 'live'])
        ->assertSessionHas('larsadmin.toast', ['message' => 'Record updated.', 'type' => 'success']);
});

it('translates validation messages', function () {
    app('translator')->addLines(['validation.required' => 'Поле :attribute є обов\'язковим.'], 'uk');

    $this->post('/admin/entities/posts', ['title' => '', 'status' => 'live'])
        ->assertSessionHasErrors(['title' => 'Поле Title є обов\'язковим.']);
});

it('uses explicit entity labels', function () {
    expect(Entities\LabelledPost::label())->toBe('Дописи')
        ->and(Entities\LabelledPost::singularLabel())->toBe('Допис');

    $this->get('/admin')->assertInertia(fn (Assert $page) => $page->where('navigation.1.label', 'Дописи'));
});

it('throws for a non-ASCII field name without an attribute', function () {
    expect(fn () => Text::make('Заголовок'))->toThrow(InvalidArgumentException::class)
        ->and(Text::make('Заголовок', 'title')->attribute)->toBe('title');
});

it('formats dates with the locale format and lets displayFormat win', function () {
    Post::create(['title' => 'D', 'status' => 'live', 'published_at' => '2026-10-29 10:05:09']);

    $display = function (string $class, string $locale) {
        app()->setLocale($locale);

        return $class::make('At', 'published_at')->resolve(Post::first())->displayedAs;
    };

    expect($display(Date::class, 'uk'))->toBe('29.10.2026')
        ->and($display(DateTime::class, 'uk'))->toBe('29.10.2026 10:05:09')
        ->and($display(Date::class, 'en'))->toBe('2026-10-29')
        ->and($display(DateTime::class, 'en'))->toBe('2026-10-29 10:05:09');

    app()->setLocale('uk');

    expect(Date::make('At', 'published_at')->displayFormat('Y/m/d')->resolve(Post::first())->displayedAs)->toBe('2026/10/29');
});

it('copies the uk validation file only when the host has none', function () {
    $lang = sys_get_temp_dir().'/larsadmin-lang-'.uniqid();
    app()->useLangPath($lang);

    app(Kernel::class)->registerCommand(new class extends InstallCommand
    {
        protected $signature = 'larsadmin:install-validation';

        public function handle(): void
        {
            $this->installUkrainianValidation();
        }
    });

    try {
        $this->artisan('larsadmin:install-validation')->assertSuccessful();

        expect(file_exists("{$lang}/uk/validation.php"))->toBeTrue()
            ->and((require "{$lang}/uk/validation.php")['required'])->toBe('Поле :attribute є обов\'язковим.');

        file_put_contents("{$lang}/uk/validation.php", '<?php return ["required" => "custom"];');
        $this->artisan('larsadmin:install-validation')->assertSuccessful();

        expect(file_get_contents("{$lang}/uk/validation.php"))->toContain('custom');
    } finally {
        File::deleteDirectory($lang);
    }
});

it('ships a uk validation file that covers every key of the framework en file', function () {
    $en = require dirname((new ReflectionClass(Translator::class))->getFileName()).'/lang/en/validation.php';
    $uk = require __DIR__.'/../../stubs/lang/uk/validation.php';

    // Every supported Laravel version must be fully translated; keys of a newer version (e.g. Laravel 13's
    // array_keys / base64 on Laravel 12) are allowed, the translator ignores rules it does not have.
    $missing = function (array $framework, array $shipped, string $prefix = '') use (&$missing): array {
        $keys = [];

        foreach ($framework as $key => $value) {
            if (! array_key_exists($key, $shipped)) {
                $keys[] = $prefix.$key;
            } elseif (is_array($value)) {
                $keys = [...$keys, ...(is_array($shipped[$key]) ? $missing($value, $shipped[$key], "{$prefix}{$key}.") : ["{$prefix}{$key}"])];
            }
        }

        return $keys;
    };

    expect($missing($en, $uk))->toBe([]);
});
