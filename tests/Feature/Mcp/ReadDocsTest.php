<?php

use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\McpServiceProvider;
use Maxcxam\Larsadmin\Mcp\Docs\DocsIndex;
use Maxcxam\Larsadmin\Mcp\LarsadminServer;
use Maxcxam\Larsadmin\Mcp\Tools\ReadDocs;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\McpResult;

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
 * Call read-docs through the server and return its structured content.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function readDocs(array $arguments): array
{
    $response = LarsadminServer::tool(ReadDocs::class, $arguments);
    $response->assertOk()->assertHasNoErrors();

    return McpResult::json($response);
}

/**
 * The shipped English guide pages, from the file system.
 *
 * @return list<string>
 */
function readDocsShippedPages(): array
{
    $pages = array_map(fn (string $path) => basename($path, '.md'), glob(DocsIndex::defaultDirectory().'/[0-9][0-9]-*.md'));
    sort($pages);

    return $pages;
}

/**
 * Make the tools use the fixture guides (tests/Fixtures/Docs/en) instead of the shipped ones: exact structure is
 * asserted there, the shipped guides only for stable facts.
 */
function readDocsUseFixtureGuides(): void
{
    app()->instance(DocsIndex::class, new DocsIndex(dirname(__DIR__, 2).'/Fixtures/Docs/en'));
}

// Structure, on the fixture guides.

it('returns the table of contents and the start of a page', function () {
    readDocsUseFixtureGuides();

    $content = readDocs(['page' => '02-filters']);

    expect($content['page'])->toBe('02-filters')
        ->and($content['title'])->toBe('02. Filters')
        ->and($content['truncated'])->toBeFalse()
        ->and($content['sections'])->toBe([
            ['title' => 'Custom filter', 'anchor' => 'custom-filter', 'level' => 2],
            ['title' => 'Default values', 'anchor' => 'default-values', 'level' => 3],
            ['title' => 'Hidden filters', 'anchor' => 'hidden-filters', 'level' => 3],
            ['title' => 'Value safety', 'anchor' => 'value-safety', 'level' => 2],
        ])
        ->and($content['content'])->toStartWith('# 02. Filters')
        ->toContain('## Custom filter', '### Hidden filters')
        ->not->toContain('## Value safety');
});

it('returns one section by anchor or heading', function (string $section) {
    readDocsUseFixtureGuides();

    $content = readDocs(['page' => '03-audit-log', 'section' => $section]);

    expect($content['content'])->toStartWith('## Masking sensitive fields')
        ->toContain('->sensitive()')
        ->not->toContain('## Pruning');
})->with([
    'anchor' => ['masking-sensitive-fields'],
    'anchor with hash' => ['#masking-sensitive-fields'],
    'heading' => ['Masking sensitive fields'],
    'heading in other case' => ['masking SENSITIVE fields'],
]);

it('includes the subsections of a section and stops at the next section of the same level', function () {
    readDocsUseFixtureGuides();

    $content = readDocs(['page' => '02-filters', 'section' => 'custom-filter'])['content'];

    expect($content)->toStartWith('## Custom filter')
        ->toContain('# This line is a comment in a code block', '### Default values', '### Hidden filters')
        ->not->toContain('## Value safety');

    $sub = readDocs(['page' => '02-filters', 'section' => 'default-values'])['content'];

    expect($sub)->toStartWith('### Default values')->not->toContain('### Hidden filters');
});

it('rejects an unknown or oversized section with the list of anchors', function () {
    readDocsUseFixtureGuides();

    LarsadminServer::tool(ReadDocs::class, ['page' => '03-audit-log', 'section' => 'no-such-section'])
        ->assertHasErrors(['Unknown "section"', 'masking-sensitive-fields, pruning']);

    LarsadminServer::tool(ReadDocs::class, ['page' => '03-audit-log', 'section' => str_repeat('a', 201)])
        ->assertHasErrors(['"section"']);
});

// Stable facts, on the shipped guides.

it('offers exactly the shipped English guide pages', function () {
    expect(readDocsShippedPages())->toContain('01-installation', '03-fields')
        ->and(DocsIndex::shared()->pages())->toBe(readDocsShippedPages());
});

it('reads every shipped page and each of its sections within the limits', function () {
    foreach (readDocsShippedPages() as $page) {
        $content = readDocs(['page' => $page]);

        expect($content['page'])->toBe($page)
            ->and($content['title'])->not->toBe('')
            ->and($content['content'])->toStartWith('# ')
            ->and(mb_strlen($content['content']))->toBeLessThanOrEqual(ReadDocs::MAX_CONTENT);

        foreach ($content['sections'] as $section) {
            expect($section['level'])->toBeIn([2, 3])
                ->and($section['anchor'])->toMatch('/^[\p{L}\p{N}_-]+$/u');

            $part = readDocs(['page' => $page, 'section' => $section['anchor']])['content'];

            expect($part)->toStartWith(str_repeat('#', $section['level']).' '.$section['title'])
                ->and(mb_strlen($part))->toBeLessThanOrEqual(ReadDocs::MAX_CONTENT);
        }
    }
});

// Input validation and safety.

it('rejects a page outside the allowlist and path traversal', function (mixed $page) {
    LarsadminServer::tool(ReadDocs::class, ['page' => $page])
        ->assertHasErrors(['Unknown "page"', 'Available pages: 01-'])
        ->assertDontSee(['"require"', 'namespace ', 'APP_KEY']);
})->with([
    'unknown page' => ['99-nothing'],
    'parent path' => ['../../composer'],
    'parent to uk guide' => ['../03-fields'],
    'file name' => ['03-fields.md'],
    'windows path' => ['..\\..\\..\\composer'],
    'absolute path' => [__FILE__],
    'stream wrapper' => ['php://filter/resource=03-fields'],
    'null byte' => ["03-fields\0.md"],
    'empty' => [''],
    'array' => [['03-fields']],
]);

it('requires the page', function () {
    LarsadminServer::tool(ReadDocs::class, [])->assertHasErrors(['Unknown "page"']);
});

it('caps the content and marks it truncated', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-docs-'.uniqid();
    File::ensureDirectoryExists($directory);
    File::put($directory.'/21-testing.md', "# 21. Testing\n\n## Long\n\n".str_repeat("word word word word\n", 5000));

    try {
        $this->app->instance(DocsIndex::class, new DocsIndex($directory));

        $content = readDocs(['page' => '21-testing', 'section' => 'long']);

        expect($content['truncated'])->toBeTrue()
            ->and(mb_strlen($content['content']))->toBe(ReadDocs::MAX_CONTENT);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('is read-only and idempotent', function () {
    $tool = (new ReadDocs)->toArray();

    expect($tool['name'])->toBe('read-docs')
        ->and($tool['annotations']['readOnlyHint'] ?? null)->toBeTrue()
        ->and($tool['annotations']['idempotentHint'] ?? null)->toBeTrue()
        ->and($tool['inputSchema']['required'] ?? [])->toBe(['page'])
        ->and($tool['inputSchema']['properties']['page']['enum'] ?? [])->toBe(readDocsShippedPages());
});

it('is not offered and refuses to run while the gate is closed', function () {
    config(['larsadmin.mcp.enabled' => false]);

    $tool = new ReadDocs;
    $response = $tool->handle(new Request(['page' => '03-fields']));

    expect($tool->shouldRegister())->toBeFalse()
        ->and($response->isError())->toBeTrue();

    LarsadminServer::tool(ReadDocs::class, ['page' => '03-fields'])->assertHasErrors();
});
