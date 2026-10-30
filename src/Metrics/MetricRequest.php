<?php

namespace Maxcxam\Larsadmin\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;

final class MetricRequest
{
    /**
     * The Entity classes the calculation queried (and authorized), stored with a cached result.
     *
     * @var array<class-string<Entity>, true>
     */
    private array $entities = [];

    /**
     * @param  int|string|null  $range  validated against the metric's ranges(); an invalid one falls back to the default
     * @param  string  $timezone  the timezone periods are aligned to (see Larsadmin::timezone())
     * @param  CarbonImmutable  $now  the current moment, in $timezone
     * @param  string  $owner  the owning dashboard/entity ("dashboard:main"), part of the cache key
     */
    public function __construct(
        public readonly Request $request,
        public readonly int|string|null $range,
        public readonly string $timezone,
        public readonly CarbonImmutable $now,
        public readonly string $owner = '',
    ) {}

    /**
     * Record an Entity source, so a cached result checks its viewAny again on every hit.
     *
     * @param  class-string<Entity>  $entity
     */
    public function useEntity(string $entity): void
    {
        $this->entities[$entity] = true;
    }

    /**
     * The Entity sources recorded so far.
     *
     * @return list<class-string<Entity>>
     */
    public function entities(): array
    {
        return array_keys($this->entities);
    }
}
