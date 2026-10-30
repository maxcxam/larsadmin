<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maxcxam\Larsadmin\Larsadmin;

class LocaleController
{
    /**
     * Remember the panel language chosen by the current visitor.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_map('strval', array_keys(Larsadmin::locales())))],
        ]);

        $request->session()->put('larsadmin.locale', $validated['locale']);

        return redirect()->back(fallback: route('larsadmin.dashboard'));
    }
}
