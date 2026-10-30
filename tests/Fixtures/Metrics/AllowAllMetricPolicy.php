<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Metrics;

/**
 * Grants everything (to restore access after DenyAllPolicy in a test).
 */
class AllowAllMetricPolicy
{
    public function viewAny($user): bool
    {
        return true;
    }
}
