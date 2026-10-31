<?php

namespace Maxcxam\Larsadmin\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Support\PanelUrl;

/**
 * A notification shown in the panel's bell. Stored by the "database" channel in the host's
 * "notifications" table with type "larsadmin". Extend it to add other channels (mail, ...).
 */
class LarsadminNotification extends Notification
{
    use Queueable;

    public const string TYPE = 'larsadmin';

    public const array LEVELS = ['info', 'success', 'warning', 'danger'];

    /** Icons the panel can render; anything else falls back to the level's icon. */
    public const array ICONS = ['bell', 'check', 'alert', 'info', 'download', 'file', 'history'];

    public const int TITLE_LENGTH = 200;

    public const int BODY_LENGTH = 2000;

    public const int LABEL_LENGTH = 200;

    protected string $title;

    protected ?string $body = null;

    protected string $level = 'info';

    protected ?string $icon = null;

    protected ?string $url = null;

    protected ?string $urlLabel = null;

    protected ?string $run = null;

    public function __construct(string $title)
    {
        $this->title($title);
    }

    /**
     * Start a notification with its title (trimmed, at most 200 characters, not empty).
     */
    public static function make(string $title): static
    {
        return new static($title);
    }

    /**
     * @throws InvalidArgumentException when the title is empty
     */
    public function title(string $title): static
    {
        $title = trim($title);

        if ($title === '') {
            throw new InvalidArgumentException('A panel notification needs a title.');
        }

        $this->title = mb_substr($title, 0, self::TITLE_LENGTH);

        return $this;
    }

    /**
     * Plain text shown under the title (at most 2000 characters, newlines kept).
     */
    public function body(?string $body): static
    {
        $body = $body !== null ? trim($body) : null;

        $this->body = $body === null || $body === '' ? null : mb_substr($body, 0, self::BODY_LENGTH);

        return $this;
    }

    /**
     * @throws InvalidArgumentException when the level is not one of LEVELS
     */
    public function level(string $level): static
    {
        if (! in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException("Unknown notification level [{$level}].");
        }

        $this->level = $level;

        return $this;
    }

    public function info(): static
    {
        return $this->level('info');
    }

    public function success(): static
    {
        return $this->level('success');
    }

    public function warning(): static
    {
        return $this->level('warning');
    }

    public function danger(): static
    {
        return $this->level('danger');
    }

    /**
     * @throws InvalidArgumentException when the icon is not one of ICONS
     */
    public function icon(?string $icon): static
    {
        if ($icon !== null && ! in_array($icon, self::ICONS, true)) {
            throw new InvalidArgumentException("Unknown notification icon [{$icon}].");
        }

        $this->icon = $icon;

        return $this;
    }

    /**
     * A link opened from the notification: a relative path ("/admin/...") or an absolute http(s) URL on the
     * application's host (request host, else APP_URL host, or the panel domain). Otherwise InvalidArgumentException.
     */
    public function url(?string $url, ?string $label = null): static
    {
        $this->url = $url !== null ? PanelUrl::guard($url, PanelUrl::applicationHosts()) : null;
        $this->urlLabel = self::label($label);

        return $this;
    }

    /**
     * Link the notification to a queued action run: the panel builds its link from the run when read.
     *
     * @internal Used by the package for run notifications.
     */
    public function forRun(string $runId, ?string $label = null): static
    {
        $this->run = $runId;
        $this->url = null;
        $this->urlLabel = self::label($label);

        return $this;
    }

    /**
     * Shortcut for Notification::send($notifiables, $this).
     */
    public function sendTo(mixed $notifiables): void
    {
        Notifications::send($notifiables, $this);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return self::TYPE;
    }

    /**
     * The stored "data".
     *
     * @return array{v: int, title: string, body: string|null, level: string, icon: string|null, url: string|null, urlLabel: string|null, run: string|null}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'v' => 1,
            'title' => $this->title,
            'body' => $this->body,
            'level' => $this->level,
            'icon' => $this->icon,
            'url' => $this->url,
            'urlLabel' => $this->urlLabel,
            'run' => $this->run,
        ];
    }

    private static function label(?string $label): ?string
    {
        $label = $label !== null ? trim($label) : null;

        return $label === null || $label === '' ? null : mb_substr($label, 0, self::LABEL_LENGTH);
    }
}
