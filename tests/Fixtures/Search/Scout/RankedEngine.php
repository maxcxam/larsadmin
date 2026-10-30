<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search\Scout;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Throwable;

/**
 * A fake engine returning preset keys in a preset ranking (or failing), recording each search.
 */
class RankedEngine extends Engine
{
    /**
     * @var list<Builder>
     */
    public array $searches = [];

    /**
     * @param  list<int|string>  $keys
     */
    public function __construct(public array $keys = [], public ?Throwable $failure = null) {}

    public function search(Builder $builder)
    {
        $this->searches[] = $builder;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return array_slice($this->keys, 0, $builder->limit ?? count($this->keys));
    }

    public function mapIds($results)
    {
        return collect($results);
    }

    public function update($models) {}

    public function delete($models) {}

    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->search($builder);
    }

    public function map(Builder $builder, $results, $model)
    {
        return $model->newCollection();
    }

    public function lazyMap(Builder $builder, $results, $model)
    {
        return LazyCollection::empty();
    }

    public function getTotalCount($results)
    {
        return count($results);
    }

    public function flush($model) {}

    public function createIndex($name, array $options = []) {}

    public function deleteIndex($name) {}

    /**
     * @return Collection<int, Builder>
     */
    public function builders(): Collection
    {
        return collect($this->searches);
    }
}
