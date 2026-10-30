<?php

namespace Maxcxam\Larsadmin\Filters;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Filters\Concerns\ColumnFilter;

/**
 * An inclusive date range: ?filters[key][from]=Y-m-d&filters[key][to]=Y-m-d.
 * Either bound may be omitted. apply() receives ['from' => ?string, 'to' => ?string].
 */
class DateRangeFilter extends Filter
{
    use ColumnFilter;

    public string $component = 'date-range-filter';

    protected function hasOptions(): bool
    {
        return false;
    }

    /**
     * @return array{from: ?string, to: ?string}|null
     */
    public function normalize(Request $request, mixed $raw): mixed
    {
        if (! is_array($raw)) {
            return null;
        }

        $from = $this->date($raw['from'] ?? null);
        $to = $this->date($raw['to'] ?? null);

        return $from === null && $to === null ? null : ['from' => $from, 'to' => $to];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        $column = $this->qualifiedColumn($query);

        if ($value['from'] !== null) {
            $query->where($column, '>=', $value['from']);
        }

        if ($value['to'] !== null) {
            $query->where($column, '<', (new DateTimeImmutable($value['to']))->modify('+1 day')->format('Y-m-d'));
        }

        return $query;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) !== 10) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
