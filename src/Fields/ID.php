<?php

namespace Maxcxam\Larsadmin\Fields;

class ID extends Field
{
    public string $component = 'id-field';

    public function __construct(?string $name = null, ?string $attribute = null, ?callable $resolveCallback = null)
    {
        parent::__construct($name ?? 'ID', $attribute ?? 'id', $resolveCallback);

        $this->exceptOnForms();
    }
}
