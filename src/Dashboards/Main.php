<?php

namespace Maxcxam\Larsadmin\Dashboards;

use Illuminate\Http\Request;

/**
 * The default dashboard: no cards, the page falls back to the entity list.
 */
final class Main extends Dashboard
{
    public function name(): string
    {
        return __('larsadmin::ui.dashboard');
    }

    public function cards(Request $request): array
    {
        return [];
    }
}
