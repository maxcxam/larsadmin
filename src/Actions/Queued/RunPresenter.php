<?php

namespace Maxcxam\Larsadmin\Actions\Queued;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Files\StoredFile;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Notifications\LarsadminNotification;
use Maxcxam\Larsadmin\Support\PanelUrl;
use Throwable;

/**
 * The JSON of a run (design §10.4), its result link and its completion notification. The payload and the
 * disk/path of a file result never leave the server; links are rebuilt and re-checked when read.
 *
 * @internal Not part of the public API.
 */
final class RunPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(ActionRun $run, string $timezone): array
    {
        $entity = Larsadmin::entityForKey($run->entity);
        $url = self::resultUrl($run);

        return [
            'id' => (string) $run->getKey(),
            'action' => $run->action_name,
            'entity' => ['uriKey' => $run->entity, 'label' => $entity !== null ? $entity::label() : $run->entity],
            'status' => $run->status,
            'total' => $run->total,
            'processed' => (int) $run->processed,
            'skipped' => (int) $run->skipped,
            'level' => $run->level,
            'message' => $run->message,
            'url' => $url,
            'urlLabel' => $url !== null ? self::resultLabel($run) : null,
            'download' => $url !== null && self::isDownload($run),
            'cancellable' => $run->isActive() && $run->cancel_requested_at === null,
            'cancelRequested' => $run->cancel_requested_at !== null,
            'createdAt' => self::time($run->created_at, $timezone),
            'startedAt' => self::time($run->started_at, $timezone),
            'finishedAt' => self::time($run->finished_at, $timezone),
        ];
    }

    /**
     * The link of a completed run's result: a redirect or download URL that is still panel-internal, or the
     * run's own download route for a file result (its existence is checked by the download only). Null otherwise.
     */
    public static function resultUrl(ActionRun $run): ?string
    {
        $result = is_array($run->result) ? $run->result : [];

        if ($run->status !== ActionRun::COMPLETED) {
            return null;
        }

        return match ($result['type'] ?? null) {
            ActionResponse::REDIRECT, ActionResponse::DOWNLOAD => is_string($result['url'] ?? null) && PanelUrl::isSafe($result['url'], PanelUrl::applicationHosts())
                ? $result['url']
                : null,
            // No storage call in lists (remote disks): the download route answers 404 once the file is gone.
            ActionResponse::FILE => is_string($result['disk'] ?? null) && is_string($result['path'] ?? null) && StoredFile::isSafePath($result['path'])
                ? route('larsadmin.runs.download', $run->getKey(), false)
                : null,
            default => null,
        };
    }

    /**
     * Whether the result is downloaded rather than opened.
     */
    public static function isDownload(ActionRun $run): bool
    {
        return in_array($run->result['type'] ?? null, [ActionResponse::DOWNLOAD, ActionResponse::FILE], true);
    }

    public static function filename(ActionRun $run): string
    {
        $filename = $run->result['filename'] ?? null;

        return is_string($filename) && $filename !== '' ? $filename : 'download';
    }

    /**
     * "Open" for a redirect, "Download {filename}" for a download.
     */
    public static function resultLabel(ActionRun $run): ?string
    {
        return match ($run->result['type'] ?? null) {
            ActionResponse::REDIRECT => __('larsadmin::actions.run_open'),
            ActionResponse::DOWNLOAD, ActionResponse::FILE => __('larsadmin::actions.run_download', ['filename' => self::filename($run)]),
            default => null,
        };
    }

    /**
     * Whether a file result is still on its disk (its path is re-checked; a broken disk counts as gone).
     */
    public static function fileExists(ActionRun $run): bool
    {
        $result = is_array($run->result) ? $run->result : [];
        $disk = $result['disk'] ?? null;
        $path = $result['path'] ?? null;

        if (($result['type'] ?? null) !== ActionResponse::FILE || ! is_string($disk) || ! is_string($path) || ! StoredFile::isSafePath($path)) {
            return false;
        }

        try {
            return Storage::disk($disk)->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The notification sent to the owner when the run finished (in the current locale: the run's).
     */
    public static function notification(ActionRun $run): LarsadminNotification
    {
        $title = match ($run->status) {
            ActionRun::COMPLETED => 'larsadmin::actions.run_completed_title',
            ActionRun::CANCELLED => 'larsadmin::actions.run_cancelled_title',
            default => 'larsadmin::actions.run_failed_title',
        };

        $level = in_array($run->level, LarsadminNotification::LEVELS, true) ? $run->level : 'info';

        return LarsadminNotification::make(__($title, ['action' => $run->action_name]))
            ->body($run->message)
            ->level($level)
            ->icon(self::isDownload($run) ? 'download' : ($level === 'success' ? 'check' : 'alert'))
            ->forRun((string) $run->getKey(), self::resultLabel($run));
    }

    private static function time(mixed $time, string $timezone): ?string
    {
        return $time !== null ? Carbon::instance($time)->setTimezone($timezone)->toIso8601String() : null;
    }
}
