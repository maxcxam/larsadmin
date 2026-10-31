<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Realtime;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The Pusher broadcaster without the Pusher SDK: the same channel conventions and auth flow, the
 * private-channel signature computed locally (HMAC-SHA256 of "socket:channel" with the secret), and
 * broadcasts recorded instead of sent (or failing, to simulate a dead relay).
 */
class FakePusherBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    /** @var list<array{channels: list<string>, event: string, payload: array<string, mixed>}> */
    public static array $sent = [];

    public static bool $fail = false;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(public array $config) {}

    public function auth($request)
    {
        $channelName = $this->normalizeChannelName($request->channel_name);

        if (empty($request->channel_name) || ($this->isGuardedChannel($request->channel_name) && ! $this->retrieveUser($request, $channelName))) {
            throw new AccessDeniedHttpException;
        }

        return parent::verifyUserCanAccessChannel($request, $channelName);
    }

    public function validAuthenticationResponse($request, $result)
    {
        return ['auth' => $this->config['key'].':'.hash_hmac('sha256', $request->socket_id.':'.$request->channel_name, $this->config['secret'])];
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        if (static::$fail) {
            throw new RuntimeException('The relay is down.');
        }

        static::$sent[] = ['channels' => array_map('strval', $channels), 'event' => $event, 'payload' => $payload];
    }

    public static function reset(): void
    {
        static::$sent = [];
        static::$fail = false;
    }
}
