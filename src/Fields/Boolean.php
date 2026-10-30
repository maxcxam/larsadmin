<?php

namespace Maxcxam\Larsadmin\Fields;

use Illuminate\Http\Request;

class Boolean extends Field
{
    public string $component = 'boolean-field';

    protected mixed $trueValue = true;

    protected mixed $falseValue = false;

    /**
     * Set the value stored on the record when the field is checked.
     */
    public function trueValue(mixed $value): static
    {
        $this->trueValue = $value;

        return $this;
    }

    /**
     * Set the value stored on the record when the field is unchecked.
     */
    public function falseValue(mixed $value): static
    {
        $this->falseValue = $value;

        return $this;
    }

    protected function defaultAlign(): string
    {
        return 'center';
    }

    protected function resolveValue(mixed $value): mixed
    {
        return $value == $this->trueValue;
    }

    protected function formatForDisplay(mixed $value): mixed
    {
        return $this->resolveValue($value);
    }

    protected function typeRules(): array
    {
        return ['boolean'];
    }

    protected function fillValue(Request $request): mixed
    {
        return $request->boolean($this->attribute) ? $this->trueValue : $this->falseValue;
    }

    public function resolveDefault(Request $request): static
    {
        parent::resolveDefault($request);

        $this->value = (bool) $this->value;

        return $this;
    }
}
