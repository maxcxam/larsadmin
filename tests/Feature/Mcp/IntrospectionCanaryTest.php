<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Testing\TestResponse;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Mcp\Introspection\EntityMetadata;
use Maxcxam\Larsadmin\Mcp\LarsadminServer;
use Maxcxam\Larsadmin\Mcp\Tools\ApplicationInfo;
use Maxcxam\Larsadmin\Mcp\Tools\DescribeEntity;
use Maxcxam\Larsadmin\Mcp\Tools\ListDashboards;
use Maxcxam\Larsadmin\Mcp\Tools\ListEntities;
use Maxcxam\Larsadmin\Mcp\Tools\ListExtensions;
use Maxcxam\Larsadmin\Tests\Fixtures\Extensions\FixtureExtensions;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\BrokenDashboard;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\BrokenEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\CanaryDashboard;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\CanaryTool;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\IntrospectedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\IntrospectedTag;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\IntrospectedUser;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\McpResult;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\McpSpy;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\UserBoundEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\PostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Tag;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

/*
 * The main security test of MCP introspection (design §10, ADR-038): records, option values, defaults, help texts,
 * meta, rule parameters, config secrets, environment values and exception messages all hold a "CANARY" marker,
 * every read tool is called with every valid input, and no response may contain the marker. The spied host
 * callbacks (closures, options(), calculate(), data(), props(), indexQuery()) must not run, nor any query.
 */

beforeEach(function () {
    $this->app->register(McpServiceProvider::class);

    app()['env'] = 'local';
    config(['larsadmin.mcp.enabled' => null]);

    McpSpy::reset();
    Gate::policy(Post::class, PostPolicy::class);

    // Record data.
    $author = User::create(['name' => McpSpy::ROW, 'email' => McpSpy::ROW.'@example.test', 'password' => McpSpy::ROW]);
    Post::create(['title' => McpSpy::ROW, 'status' => McpSpy::ROW, 'subtitle' => McpSpy::ROW, 'author_id' => $author->id]);
    Tag::create(['name' => McpSpy::ROW]);

    // Secrets in the configuration and the environment.
    config([
        'app.key' => 'base64:CANARY-KEY',
        'database.connections.x' => ['driver' => 'mysql', 'host' => 'CANARY-HOST', 'password' => 'CANARY-PASS', 'url' => 'mysql://u:CANARY-DSN@h/db'],
        'mail.mailers.smtp.username' => 'CANARY-MAIL-USER',
        'mail.mailers.smtp.password' => 'CANARY-MAIL-PASS',
        'services.postmark.token' => 'CANARY-TOKEN',
        'broadcasting.connections.reverb.key' => 'CANARY-RT-KEY',
        'broadcasting.connections.reverb.secret' => 'CANARY-RT-SECRET',
        'larsadmin.domain' => 'CANARY-DOMAIN.example.test',
        'larsadmin.notifications.realtime_client.host' => 'CANARY-RT-HOST',
        'larsadmin.notifications.realtime_client.port' => 'CANARY-RT-PORT',
        'larsadmin.actions.queue_connection' => 'CANARY-QUEUE-CONNECTION',
        'larsadmin.audit.connection' => 'CANARY-AUDIT-CONNECTION',
        'larsadmin.audit.enabled' => true,
        'larsadmin.notifications.enabled' => true,
    ]);
    putenv('LARSADMIN_REALTIME_SECRET=CANARY-ENV');
    putenv('DB_PASSWORD=CANARY-ENV-DB');

    Larsadmin::entities([IntrospectedPost::class, IntrospectedUser::class, IntrospectedTag::class, BrokenEntity::class, UserBoundEntity::class]);
    Larsadmin::dashboards([CanaryDashboard::class, BrokenDashboard::class]);
    Larsadmin::tools([CanaryTool::class]);
    Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist')->translations('acme-demo', 'ui');
    // Its composer.json holds canaries outside the name and the PSR-4 namespaces.
    Larsadmin::extension('acme/rating', __DIR__.'/../../Fixtures/Mcp/rating-extension/dist');
});

afterEach(function () {
    putenv('LARSADMIN_REALTIME_SECRET');
    putenv('DB_PASSWORD');
});

/**
 * The whole JSON-RPC response of a tool call, as the client receives it.
 */
function mcpRawResponse(TestResponse $response): string
{
    return (string) json_encode(McpResult::raw($response), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Every read tool with every valid input, plus an invalid entity.
 *
 * @return array<string, TestResponse>
 */
function callEveryReadTool(): array
{
    $responses = [
        'application-info' => LarsadminServer::tool(ApplicationInfo::class),
        'list-entities' => LarsadminServer::tool(ListEntities::class),
        'list-dashboards' => LarsadminServer::tool(ListDashboards::class),
        'list-extensions' => LarsadminServer::tool(ListExtensions::class),
        'describe-entity unknown' => LarsadminServer::tool(DescribeEntity::class, ['entity' => 'CANARY-INPUT']),
    ];

    foreach (Larsadmin::registeredEntities() as $entity) {
        $responses['describe-entity '.$entity] = LarsadminServer::tool(DescribeEntity::class, ['entity' => EntityMetadata::uriKey($entity)]);
        $responses['describe-entity class '.$entity] = LarsadminServer::tool(DescribeEntity::class, ['entity' => $entity]);
    }

    return $responses;
}

it('never returns a canary from any read tool', function () {
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $responses = callEveryReadTool();

    expect($responses)->toHaveCount(15);

    foreach ($responses as $name => $response) {
        $raw = mcpRawResponse($response);

        expect($raw)->not->toContain('CANARY', "The {$name} response leaks a canary: {$raw}")
            ->and($raw)->not->toContain(McpSpy::NUMERIC, "The {$name} response leaks rule ids: {$raw}")
            ->and($raw)->not->toContain('7350942')
            ->and(strlen($raw))->toBeGreaterThan(20);
    }

    // The tools answered with data (not only errors), so the assertion above means something.
    expect(mcpRawResponse($responses['describe-entity '.IntrospectedPost::class]))->toContain('"attribute":"title"')
        ->and(mcpRawResponse($responses['list-dashboards']))->toContain('"key":"canary"')
        ->and(mcpRawResponse($responses['list-extensions']))->toContain('"namespaces":["Acme\\\\Rating\\\\"]')
        ->and(mcpRawResponse($responses['describe-entity '.BrokenEntity::class]))->toContain('RuntimeException without an authenticated user');

    expect(McpSpy::$calls)->toBe([])
        ->and($queries)->toBe(0);
});

it('never echoes the client input of an unknown entity', function () {
    $raw = mcpRawResponse(LarsadminServer::tool(DescribeEntity::class, ['entity' => 'CANARY-INPUT']));

    expect($raw)->toContain('Unknown entity')->not->toContain('CANARY');
});

it('reports a domain only as set or not', function () {
    $info = mcpRawResponse(LarsadminServer::tool(ApplicationInfo::class));

    expect($info)->toContain('"domainSet":true')
        ->and($info)->not->toContain('example.test');
});

it('does not run host callbacks while describing actions of a failing entity', function () {
    // fields() and actions() throw with a canary message: only the exception class is reported.
    $raw = mcpRawResponse(LarsadminServer::tool(DescribeEntity::class, ['entity' => 'broken-entities']));

    expect($raw)->toContain('"unavailable":"fields"')
        ->and($raw)->toContain('"unavailable":"actions"')
        ->and($raw)->not->toContain(McpSpy::MESSAGE);
});
