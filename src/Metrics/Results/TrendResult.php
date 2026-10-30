<?php

namespace Maxcxam\Larsadmin\Metrics\Results;

use LogicException;
use Maxcxam\Larsadmin\Metrics\Results\Concerns\HasAffixes;

final class TrendResult extends MetricResult
{
    use HasAffixes;

    protected ?string $valueKind = null;

    /**
     * @param  'day'|'week'|'month'  $unit
     * @param  list<array{label: string, start: string, value: int|float|null}>  $points
     * @param  bool  $additive  whether the points add up (count/sum); a sum of averages, minimums or maximums means nothing
     */
    public function __construct(protected string $unit, protected array $points, protected bool $additive = true) {}

    /**
     * Headline the value of the latest (current) bucket.
     */
    public function showLatestValue(): static
    {
        $this->valueKind = 'latest';

        return $this;
    }

    /**
     * Headline the sum of all buckets (count and sum trends only).
     *
     * @throws LogicException for an average/min/max trend: adding those up gives a meaningless number
     */
    public function showSumValue(): static
    {
        if (! $this->additive) {
            throw new LogicException('showSumValue() needs a count or sum trend; use showLatestValue() for average, min or max.');
        }

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
