<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Metrics;

use Maxcxam\Larsadmin\Metrics\MetricRequest;
use Maxcxam\Larsadmin\Metrics\Results\TrendResult;
use Maxcxam\Larsadmin\Metrics\Trend;

class PostsTrend extends Trend
{
    public function ranges(): array
    {
        return $this->labelled([7, 30, 90, 2, 3, 366]);
    }

    public function calculate(MetricRequest $request): TrendResult
    {
        TestMetrics::$calls++;

        $fn = TestMetrics::$function ?? 'count';

        return $this->aggregate(
            $request,
            TestMetrics::$source,
            'day',
            $fn,
            TestMetrics::$column,
            TestMetrics::$dateColumn,
        );
    }

    public function cacheFor(): ?int
    {
        return TestMetrics::$ttl;
    }
}
