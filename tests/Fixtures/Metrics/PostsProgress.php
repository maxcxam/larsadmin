<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Metrics;

use Maxcxam\Larsadmin\Metrics\MetricRequest;
use Maxcxam\Larsadmin\Metrics\Progress;
use Maxcxam\Larsadmin\Metrics\Results\ProgressResult;

class PostsProgress extends Progress
{
    public function calculate(MetricRequest $request): ProgressResult
    {
        TestMetrics::$calls++;

        return TestMetrics::$column === null
            ? $this->count($request, TestMetrics::$source, 10, TestMetrics::$dateColumn)
            : $this->sum($request, TestMetrics::$source, TestMetrics::$column, 100, TestMetrics::$dateColumn);
    }
}
