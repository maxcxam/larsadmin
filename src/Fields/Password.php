<?php

namespace Maxcxam\Larsadmin\Fields;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class Password extends Field
{
    public string $component = 'password-field';

    public function __construct(string $name, ?string $attribute = null, ?callable $resolveCallback = null)
    {
        parent::__construct($name, $attribute, $resolveCallback);

        $this->onlyOnForms();
    }

    protected function typeRules(): array
    {
        return ['string'];
    }

    protected function acceptsNullInput(): bool
    {
        return true;
    }

    /**
     * Never send the stored hash to the browser.
     */
    public function resolve(Model $record): static
    {
        $this->value = null;

        return $this;
    }

    /**
     * Only update the password when a new one was entered.
     */
    public function fill(Request $request, Model $record): ?Closure
    {
        if ($this->fillCallback) {
            return parent::fill($request, $record);
        }

        if ($request->filled($this->attribute)) {
            $record->{$this->fillAttribute()} = Hash::make($request->input($this->attribute));
        }

        return null;
    }
}
