<?php

use Laravel\Mcp\Server;
use Maxcxam\Larsadmin\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

// laravel/mcp is optional (suggest + require-dev). Without it, the MCP tests that drive the server
// skip here; McpGateTest stays on because it covers the package-absent path itself.
pest()->beforeEach(function () {
    if (! class_exists(Server::class) && basename(static::$__filename) !== 'McpGateTest.php') {
        $this->markTestSkipped('laravel/mcp is not installed');
    }
})->in('Feature/Mcp');
