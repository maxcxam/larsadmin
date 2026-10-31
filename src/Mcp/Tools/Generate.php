<?php

namespace Maxcxam\Larsadmin\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Maxcxam\Larsadmin\Mcp\McpGate;
use Maxcxam\Larsadmin\Scaffolding\Scaffolder;
use Maxcxam\Larsadmin\Scaffolding\ScaffoldPlan;
use Throwable;

/**
 * MCP tool "generate" (design 1.6 §6.5, ADR-039): the larsadmin:* generators through the shared Scaffolder. It
 * returns the plan with the file contents by default and writes only with write: true, never over an existing file
 * without force: true, never outside the application and never in production. No process is run.
 *
 * @internal
 */
#[IsDestructive]
final class Generate extends Tool
{
    /**
     * The longest response, in characters; longer plans are returned without the file contents.
     */
    public const int MAX_RESPONSE = 50000;

    protected string $description = 'Generate Larsadmin classes with the package generators (the same code as php artisan '
        .'larsadmin:entity, action, filter, dashboard, value, trend, partition, progress, field, card [--data], tool '
        .'and extension). Returns the plan (paths, status create/exists/overwrite and file contents) without writing; '
        .'pass write: true to write the files. force: true (with write: true) OVERWRITES existing files, replacing the code in them.';

    public function shouldRegister(): bool
    {
        return McpGate::enabled();
    }

    /**
     * The parameter is untyped: the JsonSchema class differs across the supported laravel/mcp versions.
     *
     * @param  JsonSchema  $schema
     * @return array<string, mixed>
     */
    public function schema($schema): array
    {
        return [
            'kind' => $schema->string()
                ->enum(array_keys(Scaffolder::KINDS))
                ->description('What to generate. "data-card" is larsadmin:card --data.')
                ->required(),
            'name' => $schema->string()
                ->description('The class name, StudlyCase with "/" for sub-namespaces (e.g. "Post", "Blog/Post"); for "extension" the Composer package name (e.g. "acme/larsadmin-rating").')
                ->required(),
            'options' => $schema->object()
                ->description('Only the options of the kind\'s command: entity {model: string}; action {destructive: bool}; field, card, data-card, tool {extension: string, the package directory relative to the application}; extension {path: string, field: string, card: string}. Other kinds take none; unknown keys are refused.'),
            'write' => $schema->boolean()
                ->description('false (default): only return the plan with the file contents. true: write the files.'),
            'force' => $schema->boolean()
                ->description('Overwrite existing files; only together with write: true. Default false.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (! McpGate::enabled()) {
            return Response::error((string) McpGate::reason());
        }

        $kind = $request->get('kind');
        $name = $request->get('name');
        $options = $request->get('options') ?? [];
        $write = $request->get('write') ?? false;
        $force = $request->get('force') ?? false;

        if (! is_string($kind) || ! array_key_exists($kind, Scaffolder::KINDS)) {
            return Response::error('The kind must be one of: '.implode(', ', array_keys(Scaffolder::KINDS)).'.');
        }

        if (! is_string($name) || $name === '' || strlen($name) > 200) {
            return Response::error('The name must be a non-empty string of at most 200 characters.');
        }

        if (! is_array($options) || ($options !== [] && array_is_list($options))) {
            return Response::error('The options must be an object.');
        }

        if (! is_bool($write) || ! is_bool($force)) {
            return Response::error('write and force must be booleans.');
        }

        if ($force && ! $write) {
            return Response::error('force is used only together with write: true.');
        }

        $scaffolder = app(Scaffolder::class);

        try {
            $plan = $scaffolder->plan($kind, $name, $options, strict: true);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            return Response::error('The plan could not be built ('.$e::class.').');
        }

        if (! $write) {
            return $this->respond($plan, false, false);
        }

        // Defence in depth: the server never runs in production, and generate never writes there.
        if (app()->environment('production')) {
            return Response::error('generate never writes in production.');
        }

        if (! $force && $plan->existing() !== []) {
            return $this->respond($plan, false, false, ['Nothing was written: '.count($plan->existing()).' file(s) already exist. Call generate again with write: true and force: true to overwrite them.']);
        }

        try {
            $paths = $scaffolder->write($plan, $force);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            return Response::error('The files could not be written ('.$e::class.').');
        }

        Log::info('larsadmin.mcp.generate', ['kind' => $kind, 'name' => $name, 'force' => $force, 'paths' => $paths]);

        return $this->respond($plan, true, $force);
    }

    /**
     * @param  list<string>  $hints
     */
    protected function respond(ScaffoldPlan $plan, bool $written, bool $force, array $hints = []): ResponseFactory
    {
        $data = $plan->toArray($force, contents: ! $written && $hints === []);
        $result = [
            'kind' => $data['kind'],
            'written' => $written,
            'files' => $data['files'],
            'nextSteps' => [...$hints, ...$data['nextSteps']],
        ];

        if (strlen((string) json_encode($result)) > self::MAX_RESPONSE) {
            $result['files'] = $plan->toArray($force, contents: false)['files'];
            $result['truncated'] = true;
        }

        return Response::structured($result);
    }
}
