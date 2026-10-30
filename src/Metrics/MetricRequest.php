<?php

namespace Maxcxam\Larsadmin\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class MetricRequest
{
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
}
