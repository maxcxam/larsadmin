<?php

namespace Maxcxam\Larsadmin\Metrics\Results;

use Maxcxam\Larsadmin\Metrics\Results\Concerns\HasAffixes;

final class ValueResult extends MetricResult
{
    use HasAffixes;

    protected int|float|null $previous = null;

    protected bool $allowZero = false;

    public function __construct(protected int|float|null $value = null) {}

    /**
     * The value of the previous period, for the percentage change.
     */
    public function previous(int|float|null $previous): static
    {
        $this->previous = $previous;

        return $this;
    }

    /**
     * Treat 0 as a real result instead of "no data".
     */
    public function allowZeroResult(bool $allow = true): static
    {
        $this->allowZero = $allow;

        return $this;
    }

    /**
     * The change in percent against the previous period; null without a (non-zero) previous value.
     */
    public function change(): ?float
    {
        if ($this->value === null || $this->previous === null || $this->previous == 0) {
            return null;
        }

        return round(($this->value - $this->previous) / abs($this->previous) * 100, 1);
    }

    public function toArray(): array
    {
        return [
            'type' => 'value',
            'value' => $this->value,
            'previous' => $this->previous,
            'change' => $this->change(),
            'zeroResult' => $this->allowZero,
            'format' => $this->formatObject(),
            'prefix' => $this->prefix,
            'suffix' => $this->suffix,
        ];
    }
}
