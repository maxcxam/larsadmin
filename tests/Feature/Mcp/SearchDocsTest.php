<?php

use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Testing\TestResponse;
use Maxcxam\Larsadmin\Mcp\Docs\DocsIndex;
use Maxcxam\Larsadmin\Mcp\LarsadminServer;
use Maxcxam\Larsadmin\Mcp\McpGate;
use Maxcxam\Larsadmin\Mcp\Tools\SearchDocs;

/*
 * Exact ranking is asserted against the fixture guides in tests/Fixtures/Docs/en, which change only with these tests.
 * Against the shipped guides (which grow with every release) only stable facts are asserted: the expected page is
 * among the top results, the results are well formed and the limits hold.
 */

beforeEach(function () {
    // laravel/mcp is a require-dev package: Testbench does not discover it, register it like a host would.
    $this->app->register(McpServiceProvider::class);
    app()['env'] = 'local';
    config(['larsadmin.mcp.enabled' => null]);
});

afterEach(function () {
    DocsIndex::flush();
});

/**
 * Make the tools use the fixture guides instead of the shipped ones.
 */
function searchDocsUseFixtureGuides(): DocsIndex
{
    $index = new DocsIndex(dirname(__DIR__, 2).'/Fixtures/Docs/en');
    app()->instance(DocsIndex::class, $index);

    return $index;
}

/**
 * The JSON-RPC result of a tool call made through the laravel/mcp test client.
 *
 * @return array<string, mixed>
 */
function searchDocsMcpResult(TestResponse $response): array
{
    return (fn () => $this->response->toArray()['result'] ?? [])->call($response);
}

/**
 * Call search-docs through the server and return its structured content.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function searchDocs(array $arguments): array
{
    $response = LarsadminServer::tool(SearchDocs::class, $arguments);
    $response->assertOk()->assertHasNoErrors();

    return searchDocsMcpResult($response)['structuredContent'];
}

/**
 * Assert the documented shape of the results, sorted by score, with bounded excerpts.
 *
 * @param  list<array<string, mixed>>  $results
 */
function searchDocsAssertWellFormed(array $results): void
{
    foreach ($results as $result) {
        expect(array_keys($result))->toBe(['page', 'title', 'section', 'anchor', 'score', 'excerpt'])
            ->and($result['page'])->toMatch('/^\d{2}-[a-z0-9-]+$/')
            ->and($result['anchor'])->toMatch('/^[\p{L}\p{N}_-]+$/u')
            ->and($result['score'])->toBeGreaterThan(0)
            ->and(mb_strlen($result['excerpt']))->toBeLessThanOrEqual(DocsIndex::EXCERPT_LENGTH);
    }

    $scores = array_column($results, 'score');
    $sorted = $scores;
    rsort($sorted);

    expect($scores)->toBe($sorted);
}

// Ranking, on the fixture guides.

it('ranks the masking section first for "sensitive field audit"', function () {
    searchDocsUseFixtureGuides();

    $content = searchDocs(['query' => 'sensitive field audit']);

    expect($content['version'])->toBe(McpGate::packageVersion())
        ->and($content['results'][0])->toMatchArray([
            'page' => '03-audit-log',
            'title' => '03. Audit log',
            'section' => 'Masking sensitive fields',
            'anchor' => 'masking-sensitive-fields',
        ]);
});

it('ranks the section that explains an identifier above the one that lists it', function () {
    searchDocsUseFixtureGuides();

    $results = searchDocs(['query' => 'larsadmin:filter'])['results'];

    expect(array_map(fn (array $result) => $result['page'].'#'.$result['anchor'], array_slice($results, 0, 2)))
        ->toBe(['02-filters#custom-filter', '01-setup#artisan-commands']);
});

it('matches singular queries to plural words and searches every heading level', function () {
    searchDocsUseFixtureGuides();

    $anchors = array_column(searchDocs(['query' => 'hidden filters', 'limit' => 10])['results'], 'anchor');

    expect($anchors[0])->toBe('hidden-filters')
        ->and(array_column(searchDocs(['query' => 'hidden filter'])['results'], 'anchor')[0])->toBe('hidden-filters');
});

it('does not index files that are not guide pages', function () {
    searchDocsUseFixtureGuides();

    expect(searchDocsUseFixtureGuides()->pages())->toBe(['01-setup', '02-filters', '03-audit-log', '04-long'])
        ->and(array_column(searchDocs(['query' => 'readme'])['results'], 'page'))->not->toContain('README');
});

it('cuts the excerpt around the first match of a long section', function () {
    searchDocsUseFixtureGuides();

    $result = searchDocs(['query' => 'needleword'])['results'][0];

    expect($result['page'])->toBe('04-long')
        ->and($result['excerpt'])->toStartWith('…')->toEndWith('…')->toContain('needleword')
        ->and(mb_strlen($result['excerpt']))->toBe(DocsIndex::EXCERPT_LENGTH);
});

it('filters by page on the fixture guides', function () {
    searchDocsUseFixtureGuides();

    $results = searchDocs(['query' => 'larsadmin:filter', 'page' => '01-setup'])['results'];

    expect(array_column($results, 'anchor'))->toBe(['artisan-commands']);
});

// Stable facts, on the shipped guides.

it('finds the expected shipped guide among the top results', function (string $query, string $page) {
    $results = searchDocs(['query' => $query])['results'];

    searchDocsAssertWellFormed($results);

    expect(array_column($results, 'page'))->toContain($page);
})->with([
    ['sensitive field audit', '17-audit-log'],
    ['larsadmin:filter', '06-filters'],
]);

it('returns well-formed results from the shipped guides', function () {
    $content = searchDocs(['query' => 'action fields']);

    expect($content['version'])->toBe(McpGate::packageVersion())
        ->and($content['results'])->toHaveCount(SearchDocs::DEFAULT_LIMIT);

    searchDocsAssertWellFormed($content['results']);
});

it('respects the limit and the page filter', function () {
    expect(searchDocs(['query' => 'field', 'limit' => 2])['results'])->toHaveCount(2)
        ->and(searchDocs(['query' => 'field', 'limit' => 10])['results'])->toHaveCount(10);

    $results = searchDocs(['query' => 'metric', 'limit' => 10, 'page' => '13-metrics'])['results'];

    expect($results)->not->toBeEmpty()
        ->and(array_unique(array_column($results, 'page')))->toBe(['13-metrics']);
});

it('returns no results for words the guides do not contain', function () {
    expect(searchDocs(['query' => 'zzqxv qqwvyk'])['results'])->toBe([]);
});

// Input validation and safety.

it('rejects a page outside the allowlist and path traversal in page', function (string $page) {
    LarsadminServer::tool(SearchDocs::class, ['query' => 'fields', 'page' => $page])
        ->assertHasErrors(['Unknown "page"', 'Available pages: 01-'])
        ->assertDontSee('"require"');
})->with([
    'unknown page' => ['99-nothing'],
    'parent path' => ['../../composer'],
    'file name' => ['03-fields.md'],
    'windows path' => ['..\\..\\README'],
    'absolute path' => [__FILE__],
    'uk guide' => ['../03-fields'],
]);

it('rejects an invalid query or limit', function (array $arguments, string $message) {
    LarsadminServer::tool(SearchDocs::class, $arguments)->assertHasErrors([$message]);
})->with([
    'missing query' => [[], '"query"'],
    'too short' => [['query' => ' a '], '"query"'],
    'too long' => [['query' => str_repeat('a', 201)], '"query"'],
    'not a string' => [['query' => ['fields']], '"query"'],
    'limit zero' => [['query' => 'fields', 'limit' => 0], '"limit"'],
    'limit above max' => [['query' => 'fields', 'limit' => 11], '"limit"'],
    'limit not a number' => [['query' => 'fields', 'limit' => 'all'], '"limit"'],
]);

it('is read-only and idempotent', function () {
    $tool = (new SearchDocs)->toArray();

    expect($tool['name'])->toBe('search-docs')
        ->and($tool['annotations']['readOnlyHint'] ?? null)->toBeTrue()
        ->and($tool['annotations']['idempotentHint'] ?? null)->toBeTrue()
        ->and($tool['inputSchema']['required'] ?? [])->toBe(['query']);
});

it('is not offered and refuses to run while the gate is closed', function () {
    app()['env'] = 'production';
    config(['larsadmin.mcp.enabled' => true]);

    $tool = new SearchDocs;
    $response = $tool->handle(new Request(['query' => 'fields']));

    expect($tool->shouldRegister())->toBeFalse()
        ->and($response->isError())->toBeTrue();

    LarsadminServer::tool(SearchDocs::class, ['query' => 'fields'])->assertHasErrors();
});

it('indexes new guide pages without code changes and ignores other files', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-docs-'.uniqid();
    File::ensureDirectoryExists($directory);
    File::put($directory.'/01-installation.md', "# 01. Installation\n\nInstall the package.\n\n## Requirements\n\nPHP 8.3.\n");
    File::put($directory.'/README.md', "# Readme\n\nquasarwidget\n");
    File::put($directory.'/notes.txt', 'quasarwidget');

    try {
        expect((new DocsIndex($directory))->pages())->toBe(['01-installation']);

        File::put($directory.'/20-ai-assistants.md', "# 20. AI assistants\n\n## The quasarwidget server\n\nStart it.\n");

        $index = new DocsIndex($directory);
        $this->app->instance(DocsIndex::class, $index);

        expect($index->pages())->toBe(['01-installation', '20-ai-assistants']);

        $results = searchDocs(['query' => 'quasarwidget'])['results'];

        expect($results)->toHaveCount(1)
            ->and($results[0])->toMatchArray([
                'page' => '20-ai-assistants',
                'title' => '20. AI assistants',
                'section' => 'The quasarwidget server',
                'anchor' => 'the-quasarwidget-server',
            ]);
    } finally {
        File::deleteDirectory($directory);
    }
});

// Tokens and anchors.

it('keeps identifiers whole and splits them into words', function () {
    expect(DocsIndex::tokenize('Run larsadmin:entity with LARSADMIN_AUDIT, indexQuery and ->sensitive() in the BelongsToMany fields.'))
        ->toBe([
            'run', 'larsadmin:entity', 'larsadmin', 'entity', 'larsadmin_audit', 'larsadmin', 'audit',
            'indexquery', 'index', 'query', 'sensitive', 'belongstomany', 'belong', 'many', 'field',
        ]);
});

it('builds GitHub anchors from headings', function (string $heading, string $anchor) {
    expect(DocsIndex::slug($heading))->toBe($anchor);
})->with([
    ['Masking sensitive fields', 'masking-sensitive-fields'],
    ['Scoping rows: `indexQuery`, `detailQuery`, `relatableQuery`', 'scoping-rows-indexquery-detailquery-relatablequery'],
    ['Audit: `hasAuditValue()` and `auditValue()`', 'audit-hasauditvalue-and-auditvalue'],
    ['06. Filters', '06-filters'],
    ['See [the guide](02-entities.md)', 'see-the-guide'],
]);
