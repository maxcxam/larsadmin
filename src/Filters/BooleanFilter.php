<?php

namespace Maxcxam\Larsadmin\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Filters\Concerns\ColumnFilter;

/**
 * Yes / No on a boolean column. The query value is "1" or "0".
 */
class BooleanFilter extends Filter
{
    use ColumnFilter;

    public string $component = 'boolean-filter';

    protected ?bool $defaultValue = null;

    public function defaultTo(?bool $value): static
    {
        $this->defaultValue = $value;

        return $this;
    }

    public function default(): mixed
    {
        return $this->defaultValue === null ? null : ($this->defaultValue ? '1' : '0');
    }

    public function options(Request $request): array
    {
        return ['1' => __('larsadmin::messages.yes'), '0' => __('larsadmin::messages.no')];
    }

    public function normalize(Request $request, mixed $raw): mixed
    {
        return match (true) {
            $raw === '1', $raw === 1, $raw === true => '1',
            $raw === '0', $raw === 0, $raw === false => '0',
            default => null,
        };
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where($this->qualifiedColumn($query), $value === '1');
    }
}
