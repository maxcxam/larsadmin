<?php

namespace Maxcxam\Larsadmin\Metrics;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Entity;

/**
 * An Entity source with an extra constraint: still scoped by the entity's indexQuery() and viewAny
 * (ADR-013), then narrowed by the closure, e.g.
 * EntitySource::make(Post::class, fn (Builder $query) => $query->where('status', 'live')).
 *
 * The constraint runs inside a nested where, so an orWhere in it cannot widen the entity scoping.
 */
final class EntitySource
{
    /**
     * @param  class-string<Entity>  $entity
     * @param  Closure(Builder): mixed  $constraint
     */
    private function __construct(
        public readonly string $entity,
        public readonly Closure $constraint,
    ) {}

    /**
     * @param  class-string<Entity>  $entity
     * @param  Closure(Builder): mixed  $constraint
     *
     * @throws InvalidArgumentException when $entity is not an Entity class
     */
    public static function make(string $entity, Closure $constraint): self
    {
        if (! is_subclass_of($entity, Entity::class)) {
            throw new InvalidArgumentException("A scoped metric source needs an Entity class; got [{$entity}].");
        }

        return new self($entity, $constraint);
    }
}
