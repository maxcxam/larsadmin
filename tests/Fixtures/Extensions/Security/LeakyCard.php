<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Extensions\Security;

use Illuminate\Auth\Access\AuthorizationException;
use Maxcxam\Larsadmin\Metrics\CardRequest;
use Maxcxam\Larsadmin\Metrics\DataCard;
use RuntimeException;

/**
 * A data card whose data is private to the user (cache leak probe) and which can fail with secrets in its
 * exception messages (error leak probe).
 */
class LeakyCard extends DataCard
{
    public string $component = 'acme/leaky';

    /** 'runtime' or 'authorization' to fail; null to answer. */
    public static ?string $fail = null;

    public static ?int $ttl = null;

    public static int $calls = 0;

    /** @var array<mixed> */
    public static array $keyParts = [];

    public function rules(): array
    {
        return ['q' => ['sometimes', 'string', 'max:5']];
    }

    public function data(CardRequest $request): mixed
    {
        static::$calls++;

        match (static::$fail) {
            'runtime' => throw new RuntimeException('SQLSTATE: password=hunter2 at '.__FILE__),
            'authorization' => throw new AuthorizationException('Only hunter2 may see this'),
            default => null,
        };

        return ['secret' => 'secret-of-'.$request->user()?->getAuthIdentifier(), 'owner' => $request->owner, 'scope' => static::$keyParts];
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
        static::$fail = null;
        static::$ttl = null;
        static::$calls = 0;
        static::$keyParts = [];
    }
}
