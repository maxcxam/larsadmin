<?php

namespace Maxcxam\Larsadmin\Fields\Concerns;

use Closure;

/**
 * Request-scoped record of which related keys are visible through the related entity's
 * detail scope. Keys are registered while rows are resolved and checked together on the
 * first lookup, so a whole page costs one query per field instead of one per row.
 *
 * @internal Not part of the public API.
 */
final class RelatedVisibility
{
    /** @var array<string, bool> */
    private array $known = [];

    /** @var array<string, true> */
    private array $pending = [];

    /**
     * @param  Closure(array<int, int|string>): array<int, int|string>  $visibleKeys  returns the visible subset of the given keys
     */
    public function __construct(private readonly Closure $visibleKeys) {}

    public function register(int|string $key): void
    {
        $key = (string) $key;

        if (! isset($this->known[$key])) {
            $this->pending[$key] = true;
        }
    }

    public function isVisible(int|string $key): bool
    {
        $this->register($key);

        if ($this->pending !== []) {
            $keys = array_map('strval', array_keys($this->pending));
            $visible = array_flip(array_map('strval', ($this->visibleKeys)($keys)));

            foreach ($keys as $pending) {
                $this->known[$pending] = isset($visible[$pending]);
            }

            $this->pending = [];
        }

        return $this->known[(string) $key];
    }
}
