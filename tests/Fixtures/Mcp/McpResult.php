<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Mcp;

use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Reads larsadmin MCP tool results the same way on every supported laravel/mcp (^0.7.1 ... ^1.0): the JSON text
 * content, which every version returns. Where a version also sends structured content, it must be the same data.
 */
final class McpResult
{
    /**
     * The JSON-RPC response of a server test call, as the client receives it.
     *
     * @return array<string, mixed>
     */
    public static function raw(TestResponse $response): array
    {
        return (fn () => $this->response->toArray())->call($response);
    }

    /**
     * The decoded JSON payload of a successful server test call.
     *
     * @return array<string, mixed>
     */
    public static function json(TestResponse $response): array
    {
        $result = self::raw($response)['result'] ?? [];

        Assert::assertFalse((bool) ($result['isError'] ?? false), 'The tool answered with an error: '.json_encode($result));

        $payload = self::decode(array_column($result['content'] ?? [], 'text'));

        if (isset($result['structuredContent'])) {
            Assert::assertEquals($payload, json_decode((string) json_encode($result['structuredContent']), true));
        }

        return $payload;
    }

    /**
     * The decoded JSON payload of a tool's handle() result.
     *
     * @return array<string, mixed>
     */
    public static function fromHandle(Response|ResponseFactory $result): array
    {
        $responses = $result instanceof ResponseFactory ? $result->responses()->all() : [$result];

        foreach ($responses as $response) {
            Assert::assertFalse($response->isError(), 'The tool answered with an error: '.$response->content());
        }

        return self::decode(array_map(fn (Response $response) => (string) $response->content(), $responses));
    }

    /**
     * @param  list<string>  $texts
     * @return array<string, mixed>
     */
    private static function decode(array $texts): array
    {
        foreach ($texts as $text) {
            $decoded = json_decode((string) $text, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        Assert::fail('The tool returned no JSON text content.');
    }
}
