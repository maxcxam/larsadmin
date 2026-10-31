<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Cards;

use Maxcxam\Larsadmin\Metrics\CardRequest;
use Maxcxam\Larsadmin\Metrics\DataCard;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

/**
 * A data card listing the newest posts; "limit" and "status" are its only parameters.
 */
class TopPosts extends DataCard
{
    public string $component = 'acme/top-posts';

    /** How many times data() ran (cache tests). */
    public static int $calls = 0;

    /** Seconds to cache; null disables caching. */
    public static ?int $ttl = null;

    /** @var array<mixed> */
    public static array $keyParts = [];

    /** The last request data() received. */
    public static ?CardRequest $lastRequest = null;

    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'status' => ['sometimes', 'string', 'in:draft,live'],
        ];
    }

    public function data(CardRequest $request): mixed
    {
        static::$calls++;
        static::$lastRequest = $request;

        return Post::query()
            ->when($request->get('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderBy('id')
            ->limit((int) $request->get('limit', 5))
            ->get(['id', 'title'])
            ->map(fn (Post $post) => ['id' => $post->id, 'title' => $post->title, 'user' => $request->user()?->getAuthIdentifier()])
            ->all();
    }

    public function cacheFor(): ?int
    {
        return static::$ttl;
    }

    public function cacheKeyParts(CardRequest $request): array
    {
        return static::$keyParts;
    }

    public static function reset(): void
    {
        static::$calls = 0;
        static::$ttl = null;
        static::$keyParts = [];
        static::$lastRequest = null;
    }
}
