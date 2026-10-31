<?php

namespace Maxcxam\Larsadmin\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Maxcxam\Larsadmin\Mcp\Docs\DocsIndex;
use Maxcxam\Larsadmin\Mcp\McpGate;

/**
 * MCP tool "search-docs": full-text search over the English Larsadmin guides of the installed package (design
 * §6.1). Read-only; the results point to sections that read-docs returns in full.
 *
 * @internal
 */
#[IsReadOnly]
#[IsIdempotent]
final class SearchDocs extends Tool
{
    public const int MIN_QUERY = 2;

    public const int MAX_QUERY = 200;

    public const int MAX_LIMIT = 10;

    public const int DEFAULT_LIMIT = 5;

    protected string $description = 'Search the Larsadmin guides (the English docs bundled with the installed '
        .'package) for entities, fields, relations, filters, actions, metrics, dashboards, files, search, audit log, '
        .'notifications, theming and extensions. Returns the best matching sections with an excerpt; read a whole '
        .'section with read-docs (page + anchor).';

    public function shouldRegister(): bool
    {
        return McpGate::enabled();
    }

    /**
     * Untyped parameter: the schema builder class is not the same across the supported laravel/mcp versions.
     *
     * @param  JsonSchema  $schema
     * @return array<string, mixed>
     */
    public function schema($schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Words or identifiers to search for, e.g. "belongsTo searchable" or "larsadmin:filter".')
                ->min(self::MIN_QUERY)
                ->max(self::MAX_QUERY)
                ->required(),
            'limit' => $schema->integer()
                ->description('How many sections to return (1-10).')
                ->min(1)
                ->max(self::MAX_LIMIT)
                ->default(self::DEFAULT_LIMIT),
            'page' => $schema->string()
                ->description('Only search this guide page, e.g. "03-fields".')
                ->enum(DocsIndex::resolve()->pages() ?: ['']),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (! McpGate::enabled()) {
            return Response::error((string) McpGate::reason());
        }

        $index = DocsIndex::resolve();
        $query = $request->get('query');
        $limit = $request->get('limit', self::DEFAULT_LIMIT);
        $page = $request->get('page');

        if (! is_string($query) || mb_strlen(trim($query)) < self::MIN_QUERY || mb_strlen($query) > self::MAX_QUERY) {
            return Response::error('The "query" must be a string of '.self::MIN_QUERY.' to '.self::MAX_QUERY.' characters.');
        }

        if (is_string($limit) && preg_match('/^\d{1,2}$/', $limit)) {
            $limit = (int) $limit;
        }

        if ($limit === null) {
            $limit = self::DEFAULT_LIMIT;
        }

        if (! is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) {
            return Response::error('The "limit" must be an integer from 1 to '.self::MAX_LIMIT.'.');
        }

        if ($page === '') {
            $page = null;
        }

        if ($page !== null && (! is_string($page) || ! $index->hasPage($page))) {
            return Response::error('Unknown "page". Available pages: '.implode(', ', $index->pages()).'.');
        }

        return Response::structured([
            'version' => McpGate::packageVersion(),
            'results' => $index->search($query, $limit, $page),
        ]);
    }
}
