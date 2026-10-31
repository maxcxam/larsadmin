<?php

namespace Maxcxam\Larsadmin\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Maxcxam\Larsadmin\Larsadmin;

/**
 * The request of a DataCard: only the parameters validated by its rules() (ADR-033).
 *
 * @api stable since 1.5
 */
final readonly class CardRequest
{
    /**
     * @param  array<string, mixed>  $parameters  the validated query parameters (keys of rules() only)
     * @param  string  $timezone  the panel timezone (see Larsadmin::timezone())
     * @param  CarbonImmutable  $now  the current moment, in $timezone
     * @param  string  $owner  the owning dashboard or entity ("dashboard:{key}" / "entity:{key}")
     *
     * @internal built by the panel; tests use InteractsWithLarsadmin::cardData()
     */
    public function __construct(
        public Request $request,
        public array $parameters,
        public string $timezone,
        public CarbonImmutable $now,
        public string $owner = '',
    ) {}

    /**
     * The user signed in on the panel guard.
     */
    public function user(): ?Authenticatable
    {
        return $this->request->user(Larsadmin::guard());
    }

    /**
     * A validated parameter ("dot" notation for nested keys).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->parameters, $key, $default);
    }
}
