<?php

namespace Maxcxam\Larsadmin;

use Closure;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Mcp\Facades\Mcp;
use Maxcxam\Larsadmin\Audit\AuditRecorder;
use Maxcxam\Larsadmin\Console\ActionCommand;
use Maxcxam\Larsadmin\Console\AiCommand;
use Maxcxam\Larsadmin\Console\CardCommand;
use Maxcxam\Larsadmin\Console\DashboardCommand;
use Maxcxam\Larsadmin\Console\EntityCommand;
use Maxcxam\Larsadmin\Console\ExtensionCommand;
use Maxcxam\Larsadmin\Console\FieldCommand;
use Maxcxam\Larsadmin\Console\FilterCommand;
use Maxcxam\Larsadmin\Console\InstallCommand;
use Maxcxam\Larsadmin\Console\McpCommand;
use Maxcxam\Larsadmin\Console\PartitionCommand;
use Maxcxam\Larsadmin\Console\ProgressCommand;
use Maxcxam\Larsadmin\Console\PruneAuditCommand;
use Maxcxam\Larsadmin\Console\PruneNotificationsCommand;
use Maxcxam\Larsadmin\Console\PublishCommand;
use Maxcxam\Larsadmin\Console\ToolCommand;
use Maxcxam\Larsadmin\Console\TrendCommand;
use Maxcxam\Larsadmin\Console\ValueCommand;
use Maxcxam\Larsadmin\Mcp\LarsadminServer;
use Maxcxam\Larsadmin\Mcp\McpGate;
use Maxcxam\Larsadmin\Realtime\Realtime;
use Maxcxam\Larsadmin\Realtime\SignalNotificationSent;
use WeakMap;

class LarsadminServiceProvider extends ServiceProvider
{
    /**
     * The extension route callbacks already registered, per router.
     *
     * @var WeakMap<Router, WeakMap<Closure, true>>|null
     */
    protected static ?WeakMap $registeredRoutes = null;

    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/larsadmin.php', 'larsadmin');

        // One recorder per request (Octane-safe): it holds the batch of a running action.
        $this->app->scoped(AuditRecorder::class);
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
        $this->registerNotifications();

        // Extension providers call Larsadmin::routes() in their boot(): register them once every provider booted.
        $this->app->booted(fn () => static::registerExtensionRoutes());

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
                PruneAuditCommand::class,
                PruneNotificationsCommand::class,
                ExtensionCommand::class,
                FieldCommand::class,
                CardCommand::class,
                ToolCommand::class,
                McpCommand::class,
                AiCommand::class,
            ]);

            static::registerMcpServer($this->app);
        }
    }

    /**
     * Register the local MCP server "larsadmin" (ADR-037) when laravel/mcp is installed and the gate is open.
     * LarsadminServer extends a laravel/mcp class: it is only referenced after the class_exists() check.
     *
     * @internal
     */
    public static function registerMcpServer(Application $app): void
    {
        if (! McpGate::mcpInstalled() || ! class_exists(Mcp::class) || ! McpGate::enabled($app)) {
            return;
        }

        Mcp::local(McpGate::HANDLE, LarsadminServer::class);
    }

    /**
     * Register the rate limiters: login 5/min per email + IP and 30/min per IP; the metric endpoints
     * "larsadmin.metrics.throttle", the file downloads "larsadmin.files.throttle", the global search
     * "larsadmin.search.throttle" and the audit log "larsadmin.audit.throttle" per minute per user (per IP for guests), unlimited when null.
     */
    protected function registerRateLimiting(): void
    {
        // A config published before the keys existed keeps the defaults.
        RateLimiter::for('larsadmin-metrics', fn (Request $request) => $this->perUserLimit($request, config('larsadmin.metrics.throttle', 120)));
        RateLimiter::for('larsadmin-files', fn (Request $request) => $this->perUserLimit($request, config('larsadmin.files.throttle', 600)));
        RateLimiter::for('larsadmin-search', fn (Request $request) => $this->perUserLimit($request, config('larsadmin.search.throttle', 60)));
        RateLimiter::for('larsadmin-audit', fn (Request $request) => $this->perUserLimit($request, config('larsadmin.audit.throttle', 60)));
        RateLimiter::for('larsadmin-notifications', fn (Request $request) => $this->perUserLimit($request, config('larsadmin.notifications.throttle', 120)));

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
     * Notifications (ADR-026, ADR-027): realtime signals for panel notifications (the listener does nothing
     * while realtime is off) and the inbox channel, registered on the broadcaster when it is resolved with
     * realtime on, so an application without broadcasting never resolves it.
     */
    protected function registerNotifications(): void
    {
        Event::listen(NotificationSent::class, SignalNotificationSent::class);

        $this->callAfterResolving(BroadcastManager::class, function (BroadcastManager $manager) {
            if (Realtime::enabled()) {
                Realtime::registerChannel($manager);
            }
        });
    }

    /**
     * A per-minute limit per user (per IP for guests); none when null.
     */
    protected function perUserLimit(Request $request, mixed $perMinute): Limit
    {
        if ($perMinute === null) {
            return Limit::none();
        }

        $user = $request->user(Larsadmin::guard());

        return Limit::perMinute(max(1, (int) $perMinute))
            ->by($user === null ? 'ip:'.$request->ip() : 'user:'.$user::class.':'.$user->getAuthIdentifier());
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
     * Register the routes of extensions (Larsadmin::routes()) under {path}/ext, named "larsadmin.ext.", behind the
     * panel middleware and the panel gate ("larsadmin.middleware" + "larsadmin.auth_middleware"). Nothing is
     * registered without callbacks or when the routes are cached (route:cache ran the callbacks already). Each
     * callback is registered once per router, however often this runs.
     *
     * @internal
     */
    public static function registerExtensionRoutes(): void
    {
        if (app()->routesAreCached()) {
            return;
        }

        static::$registeredRoutes ??= new WeakMap;
        $router = app('router');

        if (! isset(static::$registeredRoutes[$router])) {
            static::$registeredRoutes[$router] = new WeakMap;
        }

        $registered = static::$registeredRoutes[$router];
        $callbacks = array_values(array_filter(Larsadmin::extensionRoutes(), fn (Closure $callback) => ! isset($registered[$callback])));

        if ($callbacks === []) {
            return;
        }

        foreach ($callbacks as $callback) {
            $registered[$callback] = true;
        }

        Route::group([
            'domain' => config('larsadmin.domain'),
            'prefix' => trim(config('larsadmin.path'), '/').'/ext',
            'middleware' => [...(array) config('larsadmin.middleware', []), ...(array) config('larsadmin.auth_middleware', [])],
            'as' => 'larsadmin.ext.',
        ], function () use ($callbacks) {
            foreach ($callbacks as $callback) {
                $callback();
            }
        });

        // Routes added after the application booted: make them findable by name and action.
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
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

        // Not loaded implicitly: the host publishes and runs it when enabling the audit log (ADR-023).
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'larsadmin-migrations');

        $this->publishes([
            __DIR__.'/../stubs/LarsadminServiceProvider.stub' => app_path('Providers/LarsadminServiceProvider.php'),
        ], 'larsadmin-provider');
    }
}
