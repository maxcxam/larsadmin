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
     * Show 0 as a real result. Without it a value of exactly 0 is sent as null, which the card shows
     * as "no data" (Nova semantics); turn it on for counts where 0 is meaningful.
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
        // A 0 without allowZeroResult() is "no data", and so has no change either.
        $value = $this->value !== null && $this->value == 0 && ! $this->allowZero ? null : $this->value;

        return [
            'type' => 'value',
            'value' => $value,
            'previous' => $this->previous,
            'change' => $value === null ? null : $this->change(),
            'zeroResult' => $this->allowZero,
            'format' => $this->formatObject(),
            'prefix' => $this->prefix,
            'suffix' => $this->suffix,
        ];
    }
}
