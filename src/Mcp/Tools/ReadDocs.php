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
 * MCP tool "read-docs": one page of the English Larsadmin guides, or one section of it with its subsections (design
 * §6.2). The page is looked up in the allowlist from the guide directory listing; no path from the client is ever
 * opened, and the content is capped at MAX_CONTENT characters.
 *
 * @internal
 */
#[IsReadOnly]
#[IsIdempotent]
final class ReadDocs extends Tool
{
    /**
     * The maximum Markdown length returned, so a response stays below 50 000 characters with its table of contents.
     */
    public const int MAX_CONTENT = 40000;

    public const int MAX_SECTION = 200;

    protected string $description = 'Read a Larsadmin guide page (the English docs bundled with the installed '
        .'package): its table of contents and either the introduction with the first section, or the given section '
        .'with its subsections as Markdown. Find pages and anchors with search-docs.';

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
            'page' => $schema->string()
                ->description('The guide page, e.g. "03-fields".')
                ->enum(DocsIndex::resolve()->pages() ?: [''])
                ->required(),
            'section' => $schema->string()
                ->description('A section anchor (e.g. "masking-sensitive-fields") or heading; omit for the page start.')
                ->max(self::MAX_SECTION),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (! McpGate::enabled()) {
            return Response::error((string) McpGate::reason());
        }

        $index = DocsIndex::resolve();
        $page = $request->get('page');
        $section = $request->get('section');

        if (! is_string($page) || ! $index->hasPage($page)) {
            return Response::error('Unknown "page". Available pages: '.implode(', ', $index->pages()).'.');
        }

        if ($section === '') {
            $section = null;
        }

        if ($section !== null && (! is_string($section) || mb_strlen($section) > self::MAX_SECTION)) {
            return Response::error('The "section" must be an anchor or a heading of at most '.self::MAX_SECTION.' characters.');
        }

        $document = $index->read($page, $section);

        if ($document === null) {
            $anchors = array_column($index->read($page)['sections'] ?? [], 'anchor');

            return Response::error('Unknown "section" on page '.$page.'. Available sections: '.implode(', ', $anchors).'.');
        }

        $truncated = mb_strlen($document['content']) > self::MAX_CONTENT;

        if ($truncated) {
            $document['content'] = mb_substr($document['content'], 0, self::MAX_CONTENT);
        }

        return JsonResult::make([...$document, 'truncated' => $truncated]);
    }
}
