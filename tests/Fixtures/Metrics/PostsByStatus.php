<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Metrics;

use Maxcxam\Larsadmin\Metrics\MetricRequest;
use Maxcxam\Larsadmin\Metrics\Partition;
use Maxcxam\Larsadmin\Metrics\Results\PartitionResult;

class PostsByStatus extends Partition
{
    public function calculate(MetricRequest $request): PartitionResult
    {
        TestMetrics::$calls++;

        return match (TestMetrics::$function) {
            'sum' => $this->sum($request, TestMetrics::$source, TestMetrics::$column, TestMetrics::$groupBy),
            'average' => $this->average($request, TestMetrics::$source, TestMetrics::$column, TestMetrics::$groupBy),
            default => $this->count($request, TestMetrics::$source, TestMetrics::$groupBy),
        };
    }

    public function cacheFor(): ?int
    {
        return TestMetrics::$ttl;
    }
}
