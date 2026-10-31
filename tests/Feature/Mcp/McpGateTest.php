<?php

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Maxcxam\Larsadmin\Mcp\McpGate;

afterEach(function () {
    McpGate::$mcpInstalled = null;
});

/**
 * Switch the application environment and the "larsadmin.mcp.enabled" flag.
 */
function mcpEnvironment(string $environment, mixed $flag): void
{
    app()['env'] = $environment;
    config(['larsadmin.mcp.enabled' => $flag]);
}

/**
 * Register a stand-in "mcp:start" that records the handle instead of serving stdio.
 */
function fakeMcpStart(): object
{
    $command = new class extends Command
    {
        protected $signature = 'mcp:start {handle}';

        public ?string $started = null;

        public function handle(): int
        {
            $this->started = (string) $this->argument('handle');

            return self::SUCCESS;
        }
    };

    app(Kernel::class)->registerCommand($command);

    return $command;
}

it('opens the gate only where the environment and the flag allow it', function (string $environment, mixed $flag, bool $enabled) {
    mcpEnvironment($environment, $flag);

    expect(McpGate::enabled())->toBe($enabled)
        ->and(McpGate::reason() === null)->toBe($enabled);
})->with([
    'production, auto' => ['production', null, false],
    'production, on' => ['production', true, false],
    'production, "true"' => ['production', 'true', false],
    'production, off' => ['production', false, false],
    'prod, on' => ['prod', true, false],
    'live, on' => ['live', true, false],
    'Production, on' => ['Production', true, false],
    'PROD, auto' => ['PROD', null, false],
    'preprod, on' => ['preprod', true, true],
    'local, auto' => ['local', null, true],
    'local, on' => ['local', true, true],
    'local, off' => ['local', false, false],
    'staging, auto' => ['staging', null, false],
    'staging, on' => ['staging', true, true],
    'staging, "1"' => ['staging', '1', true],
    'staging, off' => ['staging', false, false],
    'staging, unrecognised' => ['staging', 'maybe', false],
    'testing, auto' => ['testing', null, false],
    'testing, on' => ['testing', true, true],
]);

it('explains why the gate is closed', function () {
    mcpEnvironment('production', true);
    expect(McpGate::reason())->toContain('never runs in production');

    mcpEnvironment('local', false);
    expect(McpGate::reason())->toContain('turned off');

    mcpEnvironment('staging', null);
    expect(McpGate::reason())->toContain('APP_ENV=local')->toContain('LARSADMIN_MCP=true')->toContain('[staging]');
});

it('reads the flag as true, false or auto', function () {
    expect(McpGate::flag(null))->toBeNull()
        ->and(McpGate::flag(''))->toBeNull()
        ->and(McpGate::flag('null'))->toBeNull()
        ->and(McpGate::flag(true))->toBeTrue()
        ->and(McpGate::flag('on'))->toBeTrue()
        ->and(McpGate::flag(1))->toBeTrue()
        ->and(McpGate::flag(false))->toBeFalse()
        ->and(McpGate::flag('false'))->toBeFalse()
        ->and(McpGate::flag('0'))->toBeFalse()
        ->and(McpGate::flag('yes please'))->toBeFalse();
});

it('defaults to auto in the published config', function () {
    $config = require __DIR__.'/../../../config/larsadmin.php';

    expect($config['mcp'])->toHaveKey('enabled')
        ->and(McpGate::flag($config['mcp']['enabled']))->toBeNull();
});

it('detects laravel/mcp and can pretend it is missing', function () {
    expect(McpGate::mcpInstalled())->toBe(class_exists('Laravel\\Mcp\\Server'));

    McpGate::$mcpInstalled = false;

    expect(McpGate::mcpInstalled())->toBeFalse();
});

it('reports a package version', function () {
    expect(McpGate::packageVersion())->toBeString()->not->toBe('');
});

it('refuses to start in production with the reason', function () {
    McpGate::$mcpInstalled = true;
    mcpEnvironment('production', true);
    $start = fakeMcpStart();

    $this->artisan('larsadmin:mcp')
        ->expectsOutputToContain('never runs in production')
        ->assertExitCode(1);

    expect($start->started)->toBeNull();
});

it('refuses to start when the gate is off', function () {
    McpGate::$mcpInstalled = true;
    mcpEnvironment('staging', null);
    $start = fakeMcpStart();

    $this->artisan('larsadmin:mcp')
        ->expectsOutputToContain('runs only when APP_ENV=local')
        ->assertExitCode(1);

    expect($start->started)->toBeNull();
});

it('asks for laravel/mcp when it is not installed', function () {
    McpGate::$mcpInstalled = false;
    mcpEnvironment('local', null);
    $start = fakeMcpStart();

    $this->artisan('larsadmin:mcp')
        ->expectsOutputToContain('composer require laravel/mcp --dev')
        ->assertExitCode(1);

    expect($start->started)->toBeNull();
});

it('starts the larsadmin server through mcp:start when the gate is open', function () {
    McpGate::$mcpInstalled = true;
    mcpEnvironment('local', null);
    $start = fakeMcpStart();

    $this->artisan('larsadmin:mcp')->assertExitCode(0);

    expect($start->started)->toBe('larsadmin');
});
