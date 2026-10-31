<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maxcxam\Larsadmin\Larsadmin;

/**
 * @internal Not part of the public API.
 */
class ToolController
{
    /**
     * Show a tool's page. An unknown or invisible tool does not exist (404, not 403).
     */
    public function show(Request $request, string $tool): Response
    {
        $tool = Larsadmin::toolForKey($tool);

        abort_unless($tool !== null && $tool->authorizedToSee($request), 404);

        return Inertia::render('Tool', [
            'tool' => ['name' => $tool->name(), 'uriKey' => $tool->uriKey(), 'component' => $tool->component],
            'props' => (object) $tool->props($request),
        ]);
    }
}
