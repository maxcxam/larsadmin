<?php

namespace Maxcxam\Larsadmin\Notifications;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Actions\Queued\RunPresenter;
use Maxcxam\Larsadmin\Support\PanelUrl;

/**
 * Turns rows of the "notifications" table into the bell's JSON. Everything stored is re-checked when
 * read: text is cut to its limits, the level and icon come from allow-lists, the URL must still be
 * panel-internal and the link of a run notification is rebuilt from the run (one query per page).
 *
 * @internal Not part of the public API.
 */
final class NotificationPresenter
{
    public function __construct(private readonly Model $user, private readonly string $timezone) {}

    /**
     * @param  iterable<DatabaseNotification>  $notifications
     * @return list<array<string, mixed>>
     */
    public function present(iterable $notifications): array
    {
        $notifications = collect($notifications);

        $runIds = $notifications
            ->map(fn (DatabaseNotification $notification) => $this->string($this->data($notification)['run'] ?? null, 26))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $runs = $runIds === []
            ? collect()
            : ActionRun::query()->ownedBy($this->user)->whereKey($runIds)->get()->keyBy(fn (ActionRun $run) => strtolower((string) $run->getKey()));

        $hosts = PanelUrl::applicationHosts();

        return $notifications
            ->map(function (DatabaseNotification $notification) use ($runs, $hosts) {
                $data = $this->data($notification);
                $runId = $this->string($data['run'] ?? null, 26);
                $level = in_array($data['level'] ?? null, LarsadminNotification::LEVELS, true) ? $data['level'] : 'info';
                $icon = in_array($data['icon'] ?? null, LarsadminNotification::ICONS, true) ? $data['icon'] : null;
                $label = $this->string($data['urlLabel'] ?? null, LarsadminNotification::LABEL_LENGTH);
                $download = false;

                if ($runId !== null) {
                    /** @var ActionRun|null $run */
                    $run = $runs->get(strtolower($runId));
                    $url = $run !== null ? RunPresenter::resultUrl($run) : null;
                    $download = $url !== null && RunPresenter::isDownload($run);
                    $label = $url !== null ? ($label ?? RunPresenter::resultLabel($run)) : null;
                } else {
                    $url = $this->string($data['url'] ?? null, 2048);
                    $url = PanelUrl::isSafe($url, $hosts) ? $url : null;
                    $label = $url !== null ? $label : null;
                }

                return [
                    'id' => (string) $notification->getKey(),
                    'title' => $this->string($data['title'] ?? null, LarsadminNotification::TITLE_LENGTH) ?? '',
                    'body' => $this->string($data['body'] ?? null, LarsadminNotification::BODY_LENGTH),
                    'level' => $level,
                    'icon' => $icon,
                    'url' => $url,
                    'urlLabel' => $label,
                    'download' => $download,
                    'read' => $notification->read_at !== null,
                    'createdAt' => $notification->created_at !== null
                        ? Carbon::instance($notification->created_at)->setTimezone($this->timezone)->toIso8601String()
                        : null,
                    'run' => $runId,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function data(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        return is_array($data) ? $data : [];
    }

    private function string(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
