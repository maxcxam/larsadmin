<?php

namespace Maxcxam\Larsadmin\Metrics\Results\Concerns;

/**
 * @internal Not part of the public API.
 */
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
