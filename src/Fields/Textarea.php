<?php

namespace Maxcxam\Larsadmin\Fields;

use Illuminate\Http\Request;

class Textarea extends Field
{
    public string $component = 'textarea-field';

    public function __construct(string $name, ?string $attribute = null, ?callable $resolveCallback = null)
    {
        parent::__construct($name, $attribute, $resolveCallback);

        $this->hideFromIndex();
        $this->withMeta(['rows' => 5]);
    }

    public function rows(int $rows): static
    {
        return $this->withMeta(['rows' => $rows]);
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
