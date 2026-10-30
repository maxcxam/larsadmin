<?php

namespace Maxcxam\Larsadmin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class LarsadminApplicationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the panel for the application.
     */
    public function boot(): void
    {
        $this->gate();
        $this->authorization();
        $this->entities();
    }

    /**
     * Register the application's entities.
     */
    protected function entities(): void
    {
        if (is_dir($directory = app_path('Larsadmin'))) {
            Larsadmin::entitiesIn($directory);
        }
    }

    /**
     * Register the gate that determines who can access the panel in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewLarsadmin', fn ($user) => false);
    }

    /**
     * Configure the panel authorization callback.
     */
    protected function authorization(): void
    {
        Larsadmin::auth(function (Request $request) {
            return (config('larsadmin.allow_local', true) && app()->environment('local'))
                || Gate::forUser($request->user(Larsadmin::guard()))->check('viewLarsadmin');
        });
    }
}
