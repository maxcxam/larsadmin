<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Metrics;

use Maxcxam\Larsadmin\Metrics\MetricRequest;
use Maxcxam\Larsadmin\Metrics\Results\ValueResult;
use Maxcxam\Larsadmin\Metrics\Value;

/**
 * A metric with its own key, name and ranges that reads no table: it shows formatting.
 */
class CustomMetric extends Value
{
    public function uriKey(): string
    {
        return 'custom';
    }

    public function name(): string
    {
        return 'Custom';
    }

    public function ranges(): array
    {
        return [5 => 'Five days', 'ALL' => 'Everything'];
    }

    public function calculate(MetricRequest $request): ValueResult
    {
        return $this->result(42)->prefix('~')->suffix(' pcs')->currency('uah')->format(['maximumFractionDigits' => 1])->allowZeroResult();
    }
}
