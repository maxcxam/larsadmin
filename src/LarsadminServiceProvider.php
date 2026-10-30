<?php

namespace Maxcxam\Larsadmin;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Maxcxam\Larsadmin\Console\ActionCommand;
use Maxcxam\Larsadmin\Console\DashboardCommand;
use Maxcxam\Larsadmin\Console\EntityCommand;
use Maxcxam\Larsadmin\Console\FilterCommand;
use Maxcxam\Larsadmin\Console\InstallCommand;
use Maxcxam\Larsadmin\Console\PartitionCommand;
use Maxcxam\Larsadmin\Console\ProgressCommand;
use Maxcxam\Larsadmin\Console\PublishCommand;
use Maxcxam\Larsadmin\Console\TrendCommand;
use Maxcxam\Larsadmin\Console\ValueCommand;

class LarsadminServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/larsadmin.php', 'larsadmin');
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'larsadmin');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'larsadmin');

        $this->registerRateLimiting();
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

            $this->commands([
                EntityCommand::class,
                ActionCommand::class,
                FilterCommand::class,
                DashboardCommand::class,
                ValueCommand::class,
                TrendCommand::class,
                PartitionCommand::class,
                ProgressCommand::class,
                InstallCommand::class,
                PublishCommand::class,
            ]);
        }
    }

    /**
     * Register the login rate limiter: 5/min per email + IP, 30/min per IP.
     */
    protected function registerRateLimiting(): void
    {
        RateLimiter::for('larsadmin-login', function (Request $request) {
            $email = $request->input('email');
            $email = is_string($email) ? Str::lower($email) : '';

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(30)->by((string) $request->ip()),
            ];
        });
    }

    /**
     * Register the panel routes.
     */
    protected function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::group([
            'domain' => config('larsadmin.domain'),
            'prefix' => trim(config('larsadmin.path'), '/'),
            'middleware' => config('larsadmin.middleware', []),
            'as' => 'larsadmin.',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    /**
     * Register the publishable resources.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/larsadmin.php' => config_path('larsadmin.php'),
        ], 'larsadmin-config');

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/larsadmin'),
        ], 'larsadmin-lang');

        $this->publishes([
            __DIR__.'/../dist' => public_path('vendor/larsadmin'),
        ], ['larsadmin-assets', 'laravel-assets']);

        $this->publishes([
            __DIR__.'/../stubs/LarsadminServiceProvider.stub' => app_path('Providers/LarsadminServiceProvider.php'),
        ], 'larsadmin-provider');
    }
}
