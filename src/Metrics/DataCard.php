<?php

namespace Maxcxam\Larsadmin\Metrics;

use Carbon\CarbonImmutable;
use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Query\EntityQuery;

/**
 * A card that loads its data from its own JSON endpoint (ADR-033): the same routes, owner authorization and
 * canSee() as metrics; the query parameters are validated by rules() and anything else is dropped.
 * The endpoint answers 200 {"data": ...}, 422 for invalid parameters.
 *
 * @api stable since 1.5
 */
abstract class DataCard extends Card
{
    /**
     * Validation rules for the query parameters the card accepts; anything else is ignored.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * The card's data for the validated request; must be JSON encodable.
     */
    abstract public function data(CardRequest $request): mixed;

    /**
     * How long the data is cached; null (the default) disables caching.
     *
     * The key holds the owner, the card class and uriKey, the validated parameters, timezone and locale, the
     * guard and the user (class + id), Larsadmin::metricCacheScopeUsing() and cacheKeyParts(): data is cached
     * per user and those scope parts only (a metricCacheScopeUsing() callback whose second parameter is typed
     * for Metric only cannot receive the card, so the card is then not cached at all). When data() depends on anything else (current team or tenant,
     * role, session...), return it from cacheKeyParts() or the global callback, or a user is served another
     * scope's data. Unlike a metric, a data card has no known sources: on a cache hit only the owner's
     * authorization and canSee() run again (they run on every request).
     */
    public function cacheFor(): DateInterval|DateTimeInterface|int|null
    {
        return null;
    }

    /**
     * Extra request context the data depends on (tenant, team, role...), added to the cache key.
     * Must be JSON encodable.
     *
     * @return array<mixed>
     */
    public function cacheKeyParts(CardRequest $request): array
    {
        return [];
    }

    /**
     * The entity's index query (its indexQuery() scope), after checking viewAny, narrowed by $constraint
     * (since 1.5, the data card equivalent of Metric::scoped()). The endpoint answers 403 when viewAny fails.
     *
     * The entity's viewAny is checked again on every cache hit (fail closed).
     *
     * @param  class-string<Entity>|string  $entity  a registered Entity class or a registered entity's uriKey
     * @param  (Closure(Builder): mixed)|null  $constraint
     *
     * @throws AuthorizationException when the user may not view the entity
     * @throws InvalidArgumentException for anything but a registered entity
     */
    protected function entityQuery(CardRequest $request, string $entity, ?Closure $constraint = null): Builder
    {
        // Registered entities only, compared as strings (no autoloading of arbitrary class names).
        $class = in_array($entity, Larsadmin::registeredEntities(), true) ? $entity : Larsadmin::entityForKey($entity);

        if ($class === null) {
            throw new InvalidArgumentException("[{$entity}] is not a registered entity (class or uriKey).");
        }

        $this->queriedEntities[$class] = true;

        if (! $class::authorizedToViewAny($request->request)) {
            throw new AuthorizationException;
        }

        $query = EntityQuery::for($class, $request->request)->scope('index')->builder();

        if ($constraint !== null) {
            // A where-group, so an "or" in the constraint cannot escape the entity's scope.
            $query->where(fn (Builder $scoped) => $constraint($scoped));
        }

        return $query;
    }

    /**
     * The entities entityQuery() authorized during the last data() call, stored with cached data.
     *
     * @var array<class-string<Entity>, true>
     */
    private array $queriedEntities = [];

    /**
     * Validate the query parameters against rules() and build the card request.
     *
     * @throws ValidationException for invalid parameters
     *
     * @internal used by the card endpoint and InteractsWithLarsadmin::cardData()
     */
    public function cardRequest(Request $request, string $owner = '', ?CarbonImmutable $now = null): CardRequest
    {
        $rules = $this->rules();

        $parameters = $rules === [] ? [] : Validator::make($request->query(), $rules)->validate();

        $timezone = Larsadmin::timezone($request);

        return new CardRequest(
            $request,
            $parameters,
            $timezone,
            $now?->setTimezone($timezone) ?? CarbonImmutable::now($timezone),
            $owner,
        );
    }

    /**
     * Serve the cached data when caching is on, otherwise call data(). Cached data is stored as its JSON
     * form, so a hit returns exactly what a miss returns. On a hit, viewAny of every entity the cached data was
     * queried from (entityQuery()) is checked again.
     *
     * @throws AuthorizationException when one of those entities is no longer viewable
     *
     * @internal used by the card endpoint
     */
    final public function resolve(CardRequest $request): mixed
    {
        $ttl = $this->cacheFor();

        // A global cache scope callback typed for Metric only cannot scope this card: never cache it then.
        if ($ttl === null || (is_int($ttl) && $ttl <= 0) || ! Larsadmin::cacheScopeAccepts($this)) {
            return $this->data($request);
        }

        $store = Cache::store(config('larsadmin.metrics.cache_store'));
        $key = $this->cacheKey($request);
        $cached = $store->get($key);

        // Entries without the list of authorized entities (older format) are recomputed.
        if (is_array($cached) && is_string($cached['json'] ?? null) && is_array($cached['entities'] ?? null)) {
            $this->authorizeEntities($request, $cached['entities']);

            return json_decode($cached['json'], false, 512, JSON_THROW_ON_ERROR);
        }

        $this->queriedEntities = [];
        $json = json_encode($this->data($request), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $store->put($key, ['json' => $json, 'entities' => array_keys($this->queriedEntities)], $ttl);

        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Check viewAny again for the entities cached data was queried from.
     *
     * @param  array<mixed>  $entities
     *
     * @throws AuthorizationException when one is no longer viewable, or no longer a registered entity
     */
    private function authorizeEntities(CardRequest $request, array $entities): void
    {
        foreach ($entities as $entity) {
            if (! is_string($entity) || ! in_array($entity, Larsadmin::registeredEntities(), true) || ! $entity::authorizedToViewAny($request->request)) {
                throw new AuthorizationException;
            }
        }
    }

    /**
     * The cache key (sha256): see cacheFor() for what it holds. Final: it must always hold the user.
     *
     * @internal
     */
    final protected function cacheKey(CardRequest $request): string
    {
        $user = $request->user();

        return 'larsadmin:card:'.hash('sha256', json_encode([
            $request->owner,
            static::class,
            $this->uriKey(),
            $request->parameters,
            $request->timezone,
            app()->getLocale(),
            Larsadmin::guard() ?? config('auth.defaults.guard'),
            $user === null ? null : [$user::class, $user->getAuthIdentifier()],
            Larsadmin::cardCacheScope($request->request, $this),
            $this->cacheKeyParts($request),
        ], JSON_THROW_ON_ERROR));
    }
}
