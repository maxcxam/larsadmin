<?php

namespace Maxcxam\Larsadmin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Inertia\Ssr\DisablesSsr;
use Inertia\Ssr\Gateway;
use Maxcxam\Larsadmin\Http\Controllers\AuditController;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Search\GlobalSearch;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'larsadmin::app';

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // The panel ships a client-only bundle, so never hand its pages to the host app's SSR server.
        if (($gateway = app(Gateway::class)) instanceof DisablesSsr) {
            $gateway->disable(true);
        }

        // Scoped to panel routes: this middleware only runs on them.
        app()->setLocale(Larsadmin::locale($request));

        return parent::handle($request, $next);
    }

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return Larsadmin::assetsVersion();
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user(Larsadmin::guard());

        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'larsadmin' => [
                'name' => Larsadmin::name(),
                'path' => Larsadmin::path(),
                'locale' => $locale,
                'locales' => collect(Larsadmin::locales())
                    ->map(fn ($label, $code) => ['code' => (string) $code, 'label' => $label])
                    ->values()
                    ->all(),
                // Keyed by locale so a language switch invalidates the client's remembered copy.
                'download' => fn () => $request->session()->get('larsadmin.download'),
                // Null hides the search trigger and hotkey: no opt-in entity the user may list.
                'search' => fn () => $user && Larsadmin::check($request) ? GlobalSearch::clientConfig($request) : null,
                // Null hides the "Audit log" menu item: the log is disabled or the user lacks "viewLarsadminAudit".
                'audit' => fn () => $user && Larsadmin::auditEnabled() && Larsadmin::check($request) && AuditController::allowed($request)
                    ? ['url' => route('larsadmin.audit.index', [], false)]
                    : null,
                'translations' => Inertia::once(fn () => trans('larsadmin::ui'))->as('larsadmin.translations.'.$locale),
            ],
            'user' => $user ? [
                'id' => $user->getAuthIdentifier(),
                'name' => $user->name ?? null,
                'email' => $user->email ?? null,
            ] : null,
            'navigation' => fn () => $user && Larsadmin::check($request) ? Larsadmin::navigation($request) : [],
            'dashboards' => fn () => $user && Larsadmin::check($request) ? Larsadmin::dashboardNavigation($request) : [],
            'toast' => fn () => $request->session()->get('larsadmin.toast'),
        ];
    }
}
