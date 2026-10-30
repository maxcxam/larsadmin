<?php

namespace Maxcxam\Larsadmin\Metrics\Results\Concerns;

trait HasAffixes
{
    protected ?string $prefix = null;

    protected ?string $suffix = null;

    public function prefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function suffix(string $suffix): static
    {
        $this->suffix = $suffix;

        return $this;
    }
}
