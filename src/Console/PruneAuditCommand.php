<?php

namespace Maxcxam\Larsadmin\Console;

use Illuminate\Console\Command;
use Maxcxam\Larsadmin\Audit\AuditEntry;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'larsadmin:prune-audit')]
class PruneAuditCommand extends Command
{
    /**
     * The number of entries deleted per query.
     */
    public const int CHUNK = 1000;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'larsadmin:prune-audit {--days= : Delete entries older than this many days (default: config "larsadmin.audit.retention_days")}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete Larsadmin audit log entries older than the retention period';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $option = $this->option('days');
        $days = $option !== null ? $option : config('larsadmin.audit.retention_days', 90);

        if ($days === null) {
            $this->components->info('Audit log retention is disabled ("larsadmin.audit.retention_days" is null): nothing was deleted.');

            return self::SUCCESS;
        }

        if (! is_int($days) && ! (is_string($days) && ctype_digit($days)) || (int) $days < 1) {
            $this->components->error('The number of days must be an integer of at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays((int) $days);
        $deleted = 0;

        do {
            // Selected first, then deleted by key: DELETE ... LIMIT is not portable.
            $ids = AuditEntry::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();

            if ($ids !== []) {
                $deleted += AuditEntry::query()->whereKey($ids)->toBase()->delete();
            }
        } while (count($ids) === self::CHUNK);

        $this->components->info("Deleted {$deleted} audit log ".($deleted === 1 ? 'entry' : 'entries')." older than {$days} days.");

        return self::SUCCESS;
    }
}
