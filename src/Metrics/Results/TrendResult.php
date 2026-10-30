<?php

namespace Maxcxam\Larsadmin\Metrics\Results;

use Maxcxam\Larsadmin\Metrics\Results\Concerns\HasAffixes;

final class TrendResult extends MetricResult
{
    use HasAffixes;

    protected ?string $valueKind = null;

    /**
     * @param  'day'|'week'|'month'  $unit
     * @param  list<array{label: string, start: string, value: int|float|null}>  $points
     */
    public function __construct(protected string $unit, protected array $points) {}

    /**
     * Headline the value of the latest (current) bucket.
     */
    public function showLatestValue(): static
    {
        $this->valueKind = 'latest';

        return $this;
    }

    /**
     * Headline the sum of all buckets.
     */
    public function showSumValue(): static
    {
        $this->valueKind = 'sum';

        return $this;
    }

    public function toArray(): array
    {
        $value = match ($this->valueKind) {
            'latest' => $this->points === [] ? null : $this->points[array_key_last($this->points)]['value'],
            'sum' => array_sum(array_column($this->points, 'value')),
            default => null,
        };

        return [
            'type' => 'trend',
            'unit' => $this->unit,
            'points' => $this->points,
            'value' => $value,
            'valueKind' => $this->valueKind,
            'format' => $this->formatObject(),
            'prefix' => $this->prefix,
            'suffix' => $this->suffix,
        ];
    }
}
