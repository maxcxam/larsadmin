<?php

namespace Maxcxam\Larsadmin\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Filters\Concerns\ColumnFilter;

/**
 * Pick one of a set of options: SelectFilter::make('Status', 'status')->withOptions([...]).
 */
class SelectFilter extends Filter
{
    use ColumnFilter;

    public string $component = 'select-filter';

    /** @var array<string|int, string>|Closure(Request): array<string|int, string> */
    protected array|Closure $optionSource = [];

    protected mixed $defaultValue = null;

    /**
     * @param  array<string|int, string>|Closure(Request): array<string|int, string>  $options
     */
    public function withOptions(array|Closure $options): static
    {
        $this->optionSource = $options;

        return $this;
    }

    public function options(Request $request): array
    {
        return is_array($this->optionSource) ? $this->optionSource : (array) ($this->optionSource)($request);
    }

    public function defaultTo(string|int|null $value): static
    {
        $this->defaultValue = $value;

        return $this;
    }

    public function default(): mixed
    {
        return $this->defaultValue;
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where($this->qualifiedColumn($query), $value);
    }
}
