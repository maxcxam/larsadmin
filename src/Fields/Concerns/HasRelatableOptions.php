<?php

namespace Maxcxam\Larsadmin\Fields\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Query\EntityQuery;

/**
 * The pickable-records behaviour shared by BelongsTo and BelongsToMany (ADR-004).
 *
 * @internal Not part of the public API.
 */
trait HasRelatableOptions
{
    public bool $searchable = false;

    protected int $relatableLimit = 25;

    protected ?Closure $relatableCallback = null;

    protected ?Request $request = null;

    /**
     * @return class-string<Entity>
     */
    abstract public function relatedEntity(): string;

    /**
     * An empty instance of the related model.
     */
    abstract protected function relatedModel(): Model;

    public function searchable(bool $searchable = true): static
    {
        $this->searchable = $searchable;

        return $this;
    }

    /**
     * Narrow the records that can be picked (and are accepted by validation).
     *
     * @param  callable(Request, Builder, ?Model): mixed  $callback
     */
    public function relatableQueryUsing(callable $callback): static
    {
        $this->relatableCallback = Closure::fromCallable($callback);

        return $this;
    }

    public function relatableLimit(int $limit): static
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('The relatable limit must be between 1 and 100.');
        }

        $this->relatableLimit = $limit;

        return $this;
    }

    public function getRelatableLimit(): int
    {
        return $this->relatableLimit;
    }

    /**
     * The query of records that may be picked. Backs the options endpoint and validation.
     */
    public function relatableQuery(Request $request, ?Model $record = null): Builder
    {
        $query = EntityQuery::for($this->relatedEntity(), $request)->scope('relatable')->builder();

        if ($this->relatableCallback) {
            $result = call_user_func($this->relatableCallback, $request, $query, $record);

            if ($result instanceof Builder) {
                $query = $result;
            }
        }

        return $query;
    }

    /**
     * Normalize a submitted key for the related model, or null when it cannot be a key.
     */
    public function normalizeKey(mixed $value): int|string|null
    {
        $model = $this->relatedModel();

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || $value === '' || strlen($value) > 255) {
            return null;
        }

        if ($model->getIncrementing() && in_array($model->getKeyType(), ['int', 'integer'], true)) {
            return ctype_digit($value) && strlen($value) <= 18 ? (int) $value : null;
        }

        return $value;
    }

    /**
     * Find a pickable related record by key, honouring the related viewAny and the relatable scope.
     */
    public function findRelatable(Request $request, mixed $key, ?Model $record = null): ?Model
    {
        $key = $this->normalizeKey($key);

        if ($key === null || ! $this->relatedEntity()::authorizedToViewAny($request)) {
            return null;
        }

        return $this->relatableQuery($request, $record)->whereKey($key)->first();
    }
}
