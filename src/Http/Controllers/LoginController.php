<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maxcxam\Larsadmin\Larsadmin;

class LoginController
{
    /**
     * Show the login form.
     */
    public function show(): Response|RedirectResponse
    {
        if (Auth::guard(Larsadmin::guard())->check()) {
            return redirect()->route('larsadmin.dashboard');
        }

        return Inertia::render('Auth/Login');
    }

    /**
     * Attempt to authenticate the user.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $guard = Auth::guard(Larsadmin::guard());

        // Check the credentials WITHOUT logging in, so a user the panel rejects
        // never ends up authenticated. Both failures share the same message.
        if (! $guard->validate($credentials)) {
            throw $this->failed();
        }

        $user = $guard->getLastAttempted();

        $request->setUserResolver(fn () => $user);

        if (! Larsadmin::check($request)) {
            throw $this->failed();
        }

        $guard->login($user, $request->boolean('remember'));

        $request->session()->regenerate();

        return redirect()->intended(route('larsadmin.dashboard'));
    }

    protected function failed(): ValidationException
    {
        return ValidationException::withMessages([
            'email' => __('auth.failed'),
        ]);
    }

    /**
     * Log the user out of the panel.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard(Larsadmin::guard())->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('larsadmin.login');
    }
}
