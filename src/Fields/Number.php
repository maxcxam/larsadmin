<?php

namespace Maxcxam\Larsadmin\Fields;

use Illuminate\Http\Request;

class Number extends Field
{
    public string $component = 'number-field';

    public function min(int|float $min): static
    {
        return $this->withMeta(['min' => $min]);
    }

    public function max(int|float $max): static
    {
        return $this->withMeta(['max' => $max]);
    }

    public function step(int|float|string $step): static
    {
        return $this->withMeta(['step' => $step]);
    }

    protected function defaultAlign(): string
    {
        return 'right';
    }

    protected function typeRules(): array
    {
        $rules = ['numeric'];

        foreach (['min', 'max'] as $bound) {
            if (isset($this->meta[$bound])) {
                $rules[] = $bound.':'.$this->meta[$bound];
            }
        }

        return $rules;
    }

    protected function fillValue(Request $request): mixed
    {
        $value = $request->input($this->attribute);

        return is_numeric($value) ? $value + 0 : null;
    }
}
