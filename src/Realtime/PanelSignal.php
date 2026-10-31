<?php

namespace Maxcxam\Larsadmin\Realtime;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;

/**
 * A signal to the panel's client: "something changed, fetch it". The payload holds identifiers and
 * progress numbers only, never titles, texts or URLs (the relay may be a third party).
 *
 * @internal Not part of the public API.
 */
final class PanelSignal implements ShouldBroadcastNow
{
    public const string NOTIFICATION = 'notification';

    public const string RUN = 'run';

    public function __construct(
        public readonly string $channel,
        public readonly string $kind,
        public readonly string $id,
        public readonly ?string $status = null,
        public readonly ?int $processed = null,
        public readonly ?int $total = null,
    ) {}

    /**
     * A new notification in the inbox of the user given by morph type and id.
     */
    public static function notification(string $userType, string $userId, string $id): self
    {
        return new self(Realtime::channelForKey($userType, $userId), self::NOTIFICATION, $id);
    }

    /**
     * The progress or the final status of a run, to its owner.
     */
    public static function run(ActionRun $run): self
    {
        return new self(
            Realtime::channelForKey($run->user_type, $run->user_id),
            self::RUN,
            (string) $run->getKey(),
            $run->status,
            (int) $run->processed,
            $run->total !== null ? (int) $run->total : null,
        );
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channel);
    }

    public function broadcastAs(): string
    {
        return 'larsadmin.signal';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        if ($this->kind === self::NOTIFICATION) {
            return ['kind' => $this->kind, 'id' => $this->id];
        }

        return [
            'kind' => $this->kind,
            'id' => $this->id,
            'status' => $this->status,
            'processed' => $this->processed,
            'total' => $this->total,
        ];
    }
}
