<?php

namespace Maxcxam\Larsadmin\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Inertia\Ssr\DisablesSsr;
use Inertia\Ssr\Gateway;
use Maxcxam\Larsadmin\Extensions\ExtensionAssets;
use Maxcxam\Larsadmin\Http\Controllers\AuditController;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Notifications\Inbox;
use Maxcxam\Larsadmin\Realtime\Realtime;
use Maxcxam\Larsadmin\Search\GlobalSearch;

/**
 * @internal Not part of the public API.
 */
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

        $shared = [
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
                // Null hides the bell: notifications are disabled (no query then), or the user cannot receive them.
                'notifications' => fn () => $user && Larsadmin::notificationsEnabled() && Larsadmin::check($request)
                    ? $this->notifications($request, $user)
                    : null,
                // The run just queued (flashed by ActionController): the client starts tracking it.
                'run' => fn () => Larsadmin::notificationsEnabled() ? $request->session()->get('larsadmin.run') : null,
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

        // Extensions and tools (1.5) add their props only when registered, so a panel without them is exactly as in 1.4.
        if (Larsadmin::registeredExtensions() !== []) {
            $shared['larsadmin'] += $this->extensionProps($request, $user, $locale);
        }

        if (Larsadmin::registeredTools() !== []) {
            $shared['tools'] = fn () => $user && Larsadmin::check($request) ? $this->toolNavigation($request) : [];
        }

        return $shared;
    }

    /**
     * The extension list, their translations and the debug flag: empty for guests and users without the panel
     * gate, so the login page never loads extension code (ADR-031).
     *
     * @return array<string, mixed>
     */
    protected function extensionProps(Request $request, ?Authenticatable $user, string $locale): array
    {
        $allowed = fn (): bool => $user !== null && Larsadmin::check($request);

        return [
            'extensions' => fn () => $allowed() ? ExtensionAssets::clientList() : [],
            'extensionTranslations' => $allowed()
                ? Inertia::once(fn () => ExtensionAssets::translations())->as('larsadmin.ext-translations.'.$locale.'.'.ExtensionAssets::fingerprint())
                : null,
            'debug' => fn () => $allowed() && (bool) config('app.debug'),
        ];
    }

    /**
     * The sidebar entries of the tools the request may see.
     *
     * @return list<array{name: string, uriKey: string, icon: string|null, url: string}>
     */
    protected function toolNavigation(Request $request): array
    {
        $entries = [];

        foreach (Larsadmin::registeredTools() as $tool) {
            if ($tool->authorizedToSee($request)) {
                $entries[] = [
                    'name' => $tool->name(),
                    'uriKey' => $tool->uriKey(),
                    'icon' => $tool->navigationIcon(),
                    'url' => route('larsadmin.tools.show', $tool->uriKey(), false),
                ];
            }
        }

        return $entries;
    }

    /**
     * The bell's configuration; the unread count is null when the notifications table is missing (reported),
     * so pages keep working until the migrations are run.
     *
     * @return array<string, mixed>|null
     */
    protected function notifications(Request $request, Authenticatable $user): ?array
    {
        if (! Inbox::supports($user)) {
            return null;
        }

        /** @var Model $user */
        try {
            $unread = Inbox::unreadCount($user);
        } catch (QueryException $e) {
            if (! Inbox::isMissingTable($e)) {
                throw $e;
            }

            report($e);
            $unread = null;
        }

        return [
            'unread' => $unread,
            'poll' => Inbox::pollInterval(),
            'urls' => [
                'list' => route('larsadmin.api.notifications', [], false),
                'status' => route('larsadmin.api.notifications.status', [], false),
                'runs' => route('larsadmin.api.runs', [], false),
            ],
            'realtime' => Realtime::clientConfig($request, $user),
        ];
    }
}
