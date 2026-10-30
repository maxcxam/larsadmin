<?php

namespace Maxcxam\Larsadmin\Fields;

use Illuminate\Http\Request;

class Text extends Field
{
    public string $component = 'text-field';

    /**
     * Set the HTML input type used on forms, e.g. "email", "url" or "tel".
     */
    public function inputType(string $type): static
    {
        return $this->withMeta(['inputType' => $type]);
    }

    protected function typeRules(): array
    {
        return ['string'];
    }

    protected function acceptsNullInput(): bool
    {
        return true;
    }

    protected function fillValue(Request $request): mixed
    {
        return $this->fillString($request);
    }
}
