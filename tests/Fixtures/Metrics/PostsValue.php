<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Metrics;

use Illuminate\Auth\Access\AuthorizationException;
use Maxcxam\Larsadmin\Metrics\MetricRequest;
use Maxcxam\Larsadmin\Metrics\Results\ValueResult;
use Maxcxam\Larsadmin\Metrics\Value;

class PostsValue extends Value
{
    public function calculate(MetricRequest $request): ValueResult
    {
        TestMetrics::$calls++;

        if (TestMetrics::$denied) {
            throw new AuthorizationException;
        }

        $source = TestMetrics::$constraint === null ? TestMetrics::$source : $this->scoped(TestMetrics::$source, TestMetrics::$constraint);

        return match (TestMetrics::$function) {
            'sum' => $this->sum($request, $source, TestMetrics::$column, TestMetrics::$dateColumn),
            'average' => $this->average($request, $source, TestMetrics::$column, TestMetrics::$dateColumn),
            'min' => $this->min($request, $source, TestMetrics::$column, TestMetrics::$dateColumn),
            'max' => $this->max($request, $source, TestMetrics::$column, TestMetrics::$dateColumn),
            default => $this->count($request, $source, TestMetrics::$dateColumn),
        };
    }

    public function cacheFor(): ?int
    {
        return TestMetrics::$ttl;
    }

    public function cacheKeyParts(MetricRequest $request): array
    {
        return TestMetrics::$cacheKeyParts === null ? [] : (TestMetrics::$cacheKeyParts)($request);
    }
}
