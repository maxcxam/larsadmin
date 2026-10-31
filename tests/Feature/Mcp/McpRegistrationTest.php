<?php

use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Registrar;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Maxcxam\Larsadmin\LarsadminServiceProvider;
use Maxcxam\Larsadmin\Mcp\LarsadminServer;
use Maxcxam\Larsadmin\Mcp\McpGate;

beforeEach(function () {
    // laravel/mcp is a require-dev package: Testbench does not discover it, register it like a host would.
    $this->app->register(McpServiceProvider::class);
});

afterEach(function () {
    McpGate::$mcpInstalled = null;
});

/**
 * Run the larsadmin MCP registration for an environment and flag; the registered local server or null.
 */
function registeredLarsadminServer(string $environment, mixed $flag): ?callable
{
    app()['env'] = $environment;
    config(['larsadmin.mcp.enabled' => $flag]);

    LarsadminServiceProvider::registerMcpServer(app());

    return app(Registrar::class)->getLocalServer('larsadmin');
}

it('does not register the server in the test environment by default', function () {
    expect(app(Registrar::class)->getLocalServer('larsadmin'))->toBeNull();
});

it('registers the server only when the gate is open', function (string $environment, mixed $flag, bool $registered) {
    expect(registeredLarsadminServer($environment, $flag) !== null)->toBe($registered);
})->with([
    'local, auto' => ['local', null, true],
    'staging, on' => ['staging', true, true],
    'staging, auto' => ['staging', null, false],
    'local, off' => ['local', false, false],
    'production, on' => ['production', true, false],
]);

it('does not touch laravel/mcp when it is missing', function () {
    McpGate::$mcpInstalled = false;

    expect(registeredLarsadminServer('local', true))->toBeNull();
});

it('registers the larsadmin commands', function () {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('larsadmin:mcp')->toContain('larsadmin:ai');
});

it('is a laravel/mcp server', function () {
    expect(is_subclass_of(LarsadminServer::class, 'Laravel\\Mcp\\Server'))->toBeTrue();
});

it('lists the eight tools of the design in order, all under Mcp\\Tools', function () {
    expect(LarsadminServer::TOOLS)->toBe([
        'Maxcxam\\Larsadmin\\Mcp\\Tools\\SearchDocs',
        'Maxcxam\\Larsadmin\\Mcp\\Tools\\ReadDocs',
        'Maxcxam\\Larsadmin\\Mcp\\Tools\\ApplicationInfo',
        'Maxcxam\\Larsadmin\\Mcp\\Tools\\ListEntities',
        'Maxcxam\\Larsadmin\\Mcp\\Tools\\DescribeEntity',
        'Maxcxam\\Larsadmin\\Mcp\\Tools\\ListDashboards',
        'Maxcxam\\Larsadmin\\Mcp\\Tools\\ListExtensions',
        'Maxcxam\\Larsadmin\\Mcp\\Tools\\Generate',
    ]);
});

it('offers the existing tool classes while the gate is open and none while it is closed', function () {
    app()['env'] = 'local';
    config(['larsadmin.mcp.enabled' => null]);

    expect(LarsadminServer::toolClasses())
        ->toBe(array_values(array_filter(LarsadminServer::TOOLS, 'class_exists')));

    app()['env'] = 'production';
    config(['larsadmin.mcp.enabled' => true]);

    expect(LarsadminServer::toolClasses())->toBe([]);
});

it('boots through laravel/mcp and lists the available tools', function () {
    if (! method_exists(PendingTestResponse::class, 'tools')) {
        $this->markTestSkipped('This laravel/mcp version has no tools() test helper.');
    }

    app()['env'] = 'local';
    config(['larsadmin.mcp.enabled' => null]);

    $available = LarsadminServer::toolClasses();
    $listed = LarsadminServer::tools();

    if ($available !== []) {
        $listed->assertRegistered($available);
    }

    // With the gate closed the server boots without any tool, even when started directly.
    app()['env'] = 'production';
    $listed = LarsadminServer::tools();

    if ($available !== []) {
        $listed->assertNotRegistered($available);
    }

    expect(LarsadminServer::toolClasses())->toBe([]);
});
