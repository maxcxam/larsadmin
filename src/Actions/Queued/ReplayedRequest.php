<?php

namespace Maxcxam\Larsadmin\Actions\Queued;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;

/**
 * Lets a worker act as the user who queued a run (ADR-028): a request rebuilt from the origin URL with the
 * user resolver and an array session, the container's "request", "session" and "session.store", the default
 * guard, the guard's user and the locale swapped for the callback, and all of them restored afterwards (long-running workers and the
 * "sync" driver run other code in the same process).
 *
 * @internal Not part of the public API.
 */
final class ReplayedRequest
{
    /**
     * The run's user, retrieved through its guard's provider; null when it is gone, the guard has no
     * provider or the user's type no longer matches (fail closed).
     */
    public static function user(ActionRun $run): ?Authenticatable
    {
        $guard = $run->guard ?? config('auth.defaults.guard');
        $provider = is_string($guard) ? config("auth.guards.{$guard}.provider") : null;

        if (! is_string($provider)) {
            return null;
        }

        $user = app(AuthManager::class)->createUserProvider($provider)?->retrieveById($run->user_id);

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $type = $user instanceof Model ? $user->getMorphClass() : $user::class;

        return $type === $run->user_type && (string) $user->getAuthIdentifier() === $run->user_id ? $user : null;
    }

    /**
     * Run the callback as the user, with a request rebuilt from the payload.
     *
     * @template T
     *
     * @param  array<string, mixed>  $payload
     * @param  Closure(Request, Authenticatable): T  $callback
     * @return T
     */
    public static function act(ActionRun $run, Authenticatable $user, array $payload, Closure $callback): mixed
    {
        $app = app();
        $auth = $app->make(AuthManager::class);
        $guardName = (string) ($run->guard ?? config('auth.defaults.guard'));

        $previousRequest = $app->bound('request') ? $app->make('request') : null;
        $previousDefault = $auth->getDefaultDriver();
        $previousLocale = $app->getLocale();
        $guard = $auth->guard($guardName);
        $previousUser = method_exists($guard, 'hasUser') && $guard->hasUser() ? $guard->user() : null;

        $selection = $payload['selection'] ?? [];
        $origin = is_string($payload['origin_url'] ?? null) ? $payload['origin_url'] : (string) config('app.url');

        $request = Request::create($origin, 'POST', [
            'resources' => $selection,
            'filters' => is_array($payload['filters'] ?? null) ? $payload['filters'] : [],
            'search' => is_string($payload['search'] ?? null) ? $payload['search'] : '',
        ]);
        $store = new Store('larsadmin-run', new ArraySessionHandler(1));
        $request->setLaravelSession($store);
        $request->setRouteResolver(fn () => null);

        // The global session too: session()/Session:: in handle(), policies or scopes must not see (or leave
        // behind) the values of another job, or of the HTTP request that runs the "sync" driver.
        $previousSession = $app->bound('session') ? $app->make('session') : null;
        $previousStore = $app->resolved('session.store') ? $app->make('session.store') : null;
        $manager = new class($app, $store) extends SessionManager
        {
            public function __construct(Container $container, private readonly Store $runStore)
            {
                parent::__construct($container);
            }

            public function driver($driver = null)
            {
                return $this->runStore;
            }

            public function getDefaultDriver()
            {
                return 'larsadmin-run';
            }
        };

        try {
            $app->instance('session', $manager);
            $app->instance('session.store', $store);
            Facade::clearResolvedInstance('session');
            $app->setLocale($run->locale);
            $app->instance('request', $request);
            // After the rebinding: the auth provider resets the resolver of a new request.
            $request->setUserResolver(fn () => $user);
            $auth->shouldUse($guardName);
            $guard->setUser($user);

            return $callback($request, $user);
        } finally {
            if ($previousUser !== null) {
                $guard->setUser($previousUser);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }

            $auth->shouldUse($previousDefault);

            if ($previousRequest !== null) {
                $app->instance('request', $previousRequest);
            } else {
                $app->forgetInstance('request');
            }

            $app->setLocale($previousLocale);

            $previousSession !== null ? $app->instance('session', $previousSession) : $app->forgetInstance('session');
            $previousStore !== null ? $app->instance('session.store', $previousStore) : $app->forgetInstance('session.store');
            Facade::clearResolvedInstance('session');
        }
    }
}
