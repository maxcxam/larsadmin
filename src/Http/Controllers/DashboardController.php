<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DashboardController
{
    /**
     * Show the panel dashboard.
     */
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard');
    }
}
