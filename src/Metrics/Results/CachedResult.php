<?php

namespace Maxcxam\Larsadmin\Metrics\Results;

/**
 * A result restored from the cache: the already serialized payload.
 */
final class CachedResult extends MetricResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly array $payload) {}

    public function toArray(): array
    {
        return $this->payload;
    }
}
