<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maxcxam\Larsadmin\Dashboards\Dashboard;
use Maxcxam\Larsadmin\Larsadmin;

/**
 * @internal Not part of the public API.
 */
class DashboardController
{
    /**
     * Show the home dashboard: the first registered one the user may see (none: an empty page).
     */
    public function home(Request $request): Response
    {
        return $this->render($request, Larsadmin::home($request));
    }

    /**
     * Show a dashboard; one the user may not see does not exist.
     */
    public function show(Request $request, string $dashboard): Response
    {
        $dashboard = Larsadmin::dashboardForKey($dashboard);

        abort_unless($dashboard !== null && $dashboard->authorizedToSee($request), 404);

        return $this->render($request, $dashboard);
    }

    protected function render(Request $request, ?Dashboard $dashboard): Response
    {
        return Inertia::render('Dashboard', [
            'dashboard' => $dashboard === null ? null : ['uriKey' => $dashboard->uriKey(), 'name' => $dashboard->name()],
            'cards' => $dashboard?->serializeCards($request) ?? [],
        ]);
    }
}
