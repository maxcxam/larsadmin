<?php

namespace Maxcxam\Larsadmin\Console;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Actions\Queued\RunQueuedAction;
use Maxcxam\Larsadmin\Files\StoredFile;
use Maxcxam\Larsadmin\Notifications\LarsadminNotification;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'larsadmin:prune-notifications')]
class PruneNotificationsCommand extends Command
{
    /**
     * The number of rows deleted per query.
     */
    public const int CHUNK = 1000;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'larsadmin:prune-notifications {--days= : Delete rows older than this many days (default: config "larsadmin.notifications.retention_days")}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete Larsadmin panel notifications, queued action runs and their result files older than the retention period';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $option = $this->option('days');
        $days = $option !== null ? $option : config('larsadmin.notifications.retention_days', 30);

        if ($days !== null && (! is_int($days) && ! (is_string($days) && ctype_digit($days)) || (int) $days < 1)) {
            $this->components->error('The number of days must be an integer of at least 1.');

            return self::FAILURE;
        }

        $abandoned = $this->failAbandonedRuns();

        if ($abandoned > 0) {
            $this->components->info("Failed {$abandoned} abandoned action ".($abandoned === 1 ? 'run' : 'runs').' (no heartbeat within their timeout).');
        }

        if ($days === null) {
            $this->components->info('Notification retention is disabled ("larsadmin.notifications.retention_days" is null): nothing was deleted.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays((int) $days);

        $notifications = $this->pruneNotifications($cutoff);
        [$runs, $files] = $this->pruneRuns($cutoff);

        $this->components->info("Deleted {$notifications} panel ".($notifications === 1 ? 'notification' : 'notifications')
            .", {$runs} action ".($runs === 1 ? 'run' : 'runs')
            ." and {$files} result ".($files === 1 ? 'file' : 'files')." older than {$days} days.");

        return self::SUCCESS;
    }

    /**
     * Fail the queued/running runs whose heartbeat is older than their stale threshold (the job was lost):
     * once each, with the owner notified and the payload erased.
     */
    protected function failAbandonedRuns(): int
    {
        $model = new ActionRun;

        if (! Schema::connection($model->getConnectionName())->hasTable($model->getTable())) {
            return 0;
        }

        $failed = 0;

        ActionRun::query()->active()->chunkById(self::CHUNK, function ($runs) use (&$failed) {
            foreach ($runs as $run) {
                if ($run->isStale() && RunQueuedAction::abandon($run)) {
                    $failed++;
                }
            }
        });

        return $failed;
    }

    /**
     * Delete the panel notifications (type "larsadmin", read or not) created before the cutoff.
     */
    protected function pruneNotifications(Carbon $cutoff): int
    {
        $model = new DatabaseNotification;

        if (! Schema::connection($model->getConnectionName())->hasTable($model->getTable())) {
            $this->components->warn('The "'.$model->getTable().'" table does not exist: no notifications were pruned.');

            return 0;
        }

        $deleted = 0;

        do {
            // Selected first, then deleted by key: DELETE ... LIMIT is not portable.
            $ids = DatabaseNotification::query()
                ->where('type', LarsadminNotification::TYPE)
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();

            if ($ids !== []) {
                $deleted += DatabaseNotification::query()->whereKey($ids)->toBase()->delete();
            }
        } while (count($ids) === self::CHUNK);

        return $deleted;
    }

    /**
     * Delete the runs created before the cutoff (finished, and stale queued/running ones) and the files
     * their file() results handed over to the panel.
     *
     * @return array{0: int, 1: int}
     */
    protected function pruneRuns(Carbon $cutoff): array
    {
        $model = new ActionRun;

        if (! Schema::connection($model->getConnectionName())->hasTable($model->getTable())) {
            $this->components->warn('The "'.$model->getTable().'" table does not exist: no action runs were pruned.');

            return [0, 0];
        }

        $deleted = 0;
        $files = 0;

        do {
            $runs = ActionRun::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get(['id', 'result']);

            foreach ($runs as $run) {
                $files += $this->deleteFile($run) ? 1 : 0;
            }

            if ($runs->isNotEmpty()) {
                $deleted += ActionRun::query()->whereKey($runs->modelKeys())->toBase()->delete();
            }
        } while ($runs->count() === self::CHUNK);

        return [$deleted, $files];
    }

    /**
     * Delete a run's result file, only when it is the panel's own copy ("larsadmin/runs/{run}/..."): files of
     * the host are never touched. A failing disk is reported and the run is pruned anyway.
     */
    protected function deleteFile(ActionRun $run): bool
    {
        $result = is_array($run->result) ? $run->result : [];
        $prefix = ActionRun::filePrefix((string) $run->getKey());

        if (
            ($result['type'] ?? null) !== ActionResponse::FILE
            || ! is_string($result['disk'] ?? null)
            || ! is_string($result['path'] ?? null)
            || ! StoredFile::isSafePath($result['path'])
            || ! str_starts_with($result['path'], $prefix)
        ) {
            return false;
        }

        try {
            $disk = Storage::disk($result['disk']);
            $deleted = $disk->exists($result['path']) && $disk->delete($result['path']);
            $disk->deleteDirectory(rtrim($prefix, '/'));

            return $deleted;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
