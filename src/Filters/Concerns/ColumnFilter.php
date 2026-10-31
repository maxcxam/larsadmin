<?php

namespace Maxcxam\Larsadmin\Filters\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Shared constructor / naming for the column-bound built-in filters:
 * Filter::make('Status', 'status', 'optional-key').
 *
 * @internal Not part of the public API.
 */
trait ColumnFilter
{
    public function __construct(protected ?string $label = null, protected ?string $column = null, protected ?string $filterKey = null) {}

    public function name(): string
    {
        return $this->label !== null ? __($this->label) : parent::name();
    }

    public function key(): string
    {
        return $this->filterKey ?? ($this->column !== null ? str_replace('.', '_', $this->column) : parent::key());
    }

    protected function qualifiedColumn(Builder $query): string
    {
        return $query->getModel()->qualifyColumn($this->column ?? $this->key());
    }
}
