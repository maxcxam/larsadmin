<?php

namespace Maxcxam\Larsadmin\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Larsadmin;

/**
 * Who made a change, captured when the change is requested. Serializable (toArray/fromArray), so a
 * queued action can carry it and write the same entries later.
 *
 * @internal Not part of the public API.
 */
final readonly class AuditActor
{
    public function __construct(
        public ?string $type = null,
        public ?string $id = null,
        public ?string $guard = null,
        public ?string $label = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}

    /**
     * The panel guard's user of the request; without a user every attribute is null (the "system").
     */
    public static function fromRequest(Request $request): self
    {
        $user = $request->user(Larsadmin::guard());

        if ($user === null) {
            return new self;
        }

        $label = Larsadmin::auditActorLabel($user);
        $agent = $request->userAgent();

        return new self(
            type: $user instanceof Model ? $user->getMorphClass() : $user::class,
            id: (string) $user->getAuthIdentifier(),
            guard: Larsadmin::guard() ?? config('auth.defaults.guard'),
            label: $label !== null ? mb_substr($label, 0, 255) : null,
            ip: config('larsadmin.audit.store_ip', true) ? $request->ip() : null,
            userAgent: config('larsadmin.audit.store_user_agent', true) && is_string($agent) && $agent !== '' ? mb_substr($agent, 0, 512) : null,
        );
    }

    /**
     * @return array{type: string|null, id: string|null, guard: string|null, label: string|null, ip: string|null, userAgent: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'guard' => $this->guard,
            'label' => $this->label,
            'ip' => $this->ip,
            'userAgent' => $this->userAgent,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $string = fn (string $key) => is_string($data[$key] ?? null) ? $data[$key] : null;

        return new self($string('type'), $string('id'), $string('guard'), $string('label'), $string('ip'), $string('userAgent'));
    }

    /**
     * The actor columns of an audit entry.
     *
     * @return array<string, string|null>
     */
    public function columns(): array
    {
        return [
            'actor_type' => $this->type,
            'actor_id' => $this->id,
            'actor_guard' => $this->guard,
            'actor_label' => $this->label,
            'ip_address' => $this->ip,
            'user_agent' => $this->userAgent,
        ];
    }
}
