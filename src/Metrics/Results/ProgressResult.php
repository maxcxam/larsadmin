<?php

namespace Maxcxam\Larsadmin\Metrics\Results;

use Maxcxam\Larsadmin\Metrics\Results\Concerns\HasAffixes;

final class ProgressResult extends MetricResult
{
    use HasAffixes;

    protected bool $avoid = false;

    public function __construct(protected int|float|null $value, protected int|float $target) {}

    /**
     * Lower is better: the colours invert.
     */
    public function avoid(bool $avoid = true): static
    {
        $this->avoid = $avoid;

        return $this;
    }

    public function toArray(): array
    {
        $value = $this->value ?? 0;

        return [
            'type' => 'progress',
            'value' => $value,
            'target' => $this->target,
            'percentage' => $this->target > 0 ? round($value / $this->target * 100, 1) : 0.0,
            'avoid' => $this->avoid,
            'format' => $this->formatObject(),
            'prefix' => $this->prefix,
            'suffix' => $this->suffix,
        ];
    }
}
