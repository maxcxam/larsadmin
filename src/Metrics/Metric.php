<?php

namespace Maxcxam\Larsadmin\Metrics;

use Carbon\CarbonImmutable;
use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Metrics\Results\CachedResult;
use Maxcxam\Larsadmin\Metrics\Results\MetricResult;

/**
 * A card that loads a computed number from its own JSON endpoint.
 *
 * SECURITY: the query helpers of the concrete metrics (count, sum, average, ...) take a source
 * ($model). An Entity class is scoped by its indexQuery() and needs viewAny, and so is scoped(Entity::class, fn),
 * which adds a constraint on top; a plain model class, an Eloquent builder or a query builder is NOT restricted
 * in any way and aggregates every row the query can reach. Use those for global KPIs only, and hide them with
 * canSee() if needed.
 */
abstract class Metric extends Card
{
    /**
     * The aggregate functions metrics may use (never interpolated from input).
     *
     * @var list<string>
     */
    protected const array FUNCTIONS = ['count', 'sum', 'avg', 'min', 'max'];

    /**
     * The selectable ranges: int days, or 'TODAY' | 'MTD' | 'QTD' | 'YTD' | 'ALL', => label.
     *
     * @return array<int|string, string>
     */
    public function ranges(): array
    {
        return [];
    }

    /**
     * The range used when none (or an unknown one) is requested: the first of ranges().
     */
    public function defaultRange(): int|string|null
    {
        return array_key_first($this->ranges());
    }

    /**
     * How long the result is cached per owner, uriKey, range, timezone, locale and user; null disables caching.
     * Cached numbers go stale until they expire, so keep it short for anything users act on.
     */
    public function cacheFor(): DateInterval|DateTimeInterface|int|null
    {
        return null;
    }

    /**
     * Compute the metric for the (validated) request.
     */
    abstract public function calculate(MetricRequest $request): MetricResult;

    /**
     * Build the request for a raw `range` input: anything that is not one of ranges() becomes the default.
     */
    public function metricRequest(Request $request, mixed $range = null, string $owner = '', ?CarbonImmutable $now = null): MetricRequest
    {
        $timezone = Larsadmin::timezone($request);

        return new MetricRequest(
            $request,
            $this->normalizeRange($range),
            $timezone,
            $now?->setTimezone($timezone) ?? CarbonImmutable::now($timezone),
            $owner,
        );
    }

    /**
     * Validate the range, serve the cached result when caching is on, otherwise calculate.
     */
    public function resolve(MetricRequest $request): MetricResult
    {
        $range = $this->normalizeRange($request->range);

        if ($range !== $request->range) {
            $request = new MetricRequest($request->request, $range, $request->timezone, $request->now, $request->owner);
        }

        $ttl = $this->cacheFor();

        if ($ttl === null || (is_int($ttl) && $ttl <= 0)) {
            return $this->calculate($request);
        }

        $payload = Cache::store(config('larsadmin.metrics.cache_store'))
            ->remember($this->cacheKey($request), $ttl, fn () => $this->calculate($request)->toArray());

        return new CachedResult($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $ranges = [];

        foreach ($this->ranges() as $value => $label) {
            $ranges[] = ['value' => $value, 'label' => $label];
        }

        return [
            ...parent::jsonSerialize(),
            'ranges' => $ranges,
            'defaultRange' => $this->defaultRange(),
        ];
    }

    protected function normalizeRange(mixed $range): int|string|null
    {
        if (is_int($range) || is_string($range)) {
            foreach (array_keys($this->ranges()) as $key) {
                if ((string) $key === (string) $range) {
                    return $key;
                }
            }
        }

        return $this->defaultRange();
    }

    /**
     * Label the given range keys: translated for the known ones, "N days" otherwise.
     *
     * @param  list<int|string>  $keys
     * @return array<int|string, string>
     */
    protected function labelled(array $keys): array
    {
        $labels = [];

        foreach ($keys as $key) {
            $translation = "larsadmin::metrics.ranges.{$key}";

            $labels[$key] = trans()->has($translation)
                ? __($translation)
                : (is_int($key) ? __(':count days', ['count' => $key]) : (string) $key);
        }

        return $labels;
    }

    /**
     * An Entity source narrowed by $constraint, still scoped by the entity's indexQuery() and viewAny:
     * $this->count($request, $this->scoped(Post::class, fn ($query) => $query->where('status', 'live'))).
     *
     * @param  class-string<Entity>  $entity
     * @param  Closure(Builder): mixed  $constraint
     *
     * @throws InvalidArgumentException when $entity is not an Entity class
     */
    protected function scoped(string $entity, Closure $constraint): EntitySource
    {
        return EntitySource::make($entity, $constraint);
    }

    protected function cacheKey(MetricRequest $request): string
    {
        $user = $request->request->user(Larsadmin::guard());

        return 'larsadmin:metric:'.md5(implode('|', [
            $request->owner,
            $this->uriKey(),
            (string) $request->range,
            $request->timezone,
            app()->getLocale(),
            $user === null ? 'guest' : (string) $user->getAuthIdentifier(),
        ]));
    }

    /**
     * Run one whitelisted aggregate on a constrained query.
     *
     * @param  string|null  $column  the qualified column (not needed for count)
     */
    protected function aggregateQuery(BaseBuilder $query, string $function, ?string $column): int|float|null
    {
        if (! in_array($function, self::FUNCTIONS, true)) {
            throw new InvalidArgumentException("Unsupported aggregate [{$function}].");
        }

        if ($function !== 'count' && $column === null) {
            throw new InvalidArgumentException("The [{$function}] aggregate needs a column.");
        }

        return MetricResult::number($function === 'count' ? $query->count() : $query->{$function}($column));
    }
}
