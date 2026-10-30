<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Maxcxam\Larsadmin\Audit\AuditEntry;
use Maxcxam\Larsadmin\Console\InstallCommand;
use Maxcxam\Larsadmin\Console\PruneAuditCommand;
use Maxcxam\Larsadmin\LarsadminServiceProvider;

/**
 * Insert $count entries created $daysAgo days ago.
 */
function pruneRows(int $count, int $daysAgo): void
{
    $createdAt = now()->subDays($daysAgo)->format('Y-m-d H:i:s');

    foreach (array_chunk(range(1, $count), 500) as $chunk) {
        DB::table('larsadmin_audit_entries')->insert(array_map(fn () => [
            'batch_id' => '00000000-0000-0000-0000-000000000001',
            'event' => 'updated',
            'entity' => 'posts',
            'created_at' => $createdAt,
        ], $chunk));
    }
}

it('deletes entries older than the retention period, 90 days by default (AC-10)', function () {
    pruneRows(3, 120);
    pruneRows(2, 91);
    pruneRows(4, 89);

    $this->artisan('larsadmin:prune-audit')
        ->expectsOutputToContain('Deleted 5 audit log entries older than 90 days.')
        ->assertSuccessful();

    expect(AuditEntry::count())->toBe(4);
});

it('uses the configured retention and the --days option (AC-10)', function () {
    pruneRows(1, 40);
    pruneRows(1, 20);
    pruneRows(1, 5);

    config(['larsadmin.audit.retention_days' => 30]);
    $this->artisan('larsadmin:prune-audit')->expectsOutputToContain('Deleted 1 audit log entry older than 30 days.')->assertSuccessful();
    expect(AuditEntry::count())->toBe(2);

    $this->artisan('larsadmin:prune-audit', ['--days' => '10'])->assertSuccessful();
    expect(AuditEntry::count())->toBe(1);
});

it('keeps everything when the retention is null (AC-10)', function () {
    config(['larsadmin.audit.retention_days' => null]);
    pruneRows(2, 1000);

    $this->artisan('larsadmin:prune-audit')->expectsOutputToContain('retention is disabled')->assertSuccessful();

    expect(AuditEntry::count())->toBe(2);
});

it('rejects an invalid number of days', function (mixed $days, bool $option) {
    pruneRows(1, 1000);

    if (! $option) {
        config(['larsadmin.audit.retention_days' => $days]);
    }

    $this->artisan('larsadmin:prune-audit', $option ? ['--days' => $days] : [])
        ->expectsOutputToContain('at least 1')
        ->assertFailed();

    expect(AuditEntry::count())->toBe(1);
})->with([
    'text option' => ['abc', true],
    'zero option' => ['0', true],
    'negative option' => ['-5', true],
    'zero config' => [0, false],
    'float config' => [1.5, false],
]);

it('deletes in batches of 1000, also while the log is disabled', function () {
    config(['larsadmin.audit.enabled' => false]);
    pruneRows(2345, 200);
    pruneRows(3, 1);

    DB::enableQueryLog();
    $this->artisan('larsadmin:prune-audit')->assertSuccessful();

    $deletes = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_starts_with(strtolower($sql), 'delete'));

    expect(AuditEntry::count())->toBe(3)
        ->and($deletes)->toHaveCount(3)
        ->and(PruneAuditCommand::CHUNK)->toBe(1000);
});

it('registers the migration under the larsadmin-migrations tag', function () {
    $paths = ServiceProvider::pathsToPublish(LarsadminServiceProvider::class, 'larsadmin-migrations');
    $source = realpath(__DIR__.'/../../database/migrations');

    expect(array_map('realpath', array_keys($paths)))->toBe([$source])
        ->and(array_values($paths))->toBe([database_path('migrations')])
        ->and(file_exists($source.'/2026_11_01_000000_create_larsadmin_audit_entries_table.php'))->toBeTrue();
});

it('publishes the migration on install', function () {
    $command = new class extends InstallCommand
    {
        protected $signature = 'larsadmin:install-audit';

        /** @var list<array<string, mixed>> */
        public static array $calls = [];

        public function call($command, array $arguments = []): int
        {
            self::$calls[] = [$command, $arguments];

            return 0;
        }

        protected function registerApplicationProvider(): void {}

        protected function installUkrainianValidation(): void {}
    };

    app(Kernel::class)->registerCommand($command);

    $this->artisan('larsadmin:install-audit')
        ->expectsOutputToContain('LARSADMIN_AUDIT=true')
        ->assertSuccessful();

    expect($command::$calls)->toContain(['vendor:publish', ['--tag' => 'larsadmin-migrations']]);
});

it('creates and drops the table on the configured connection', function () {
    $migration = require __DIR__.'/../../database/migrations/2026_11_01_000000_create_larsadmin_audit_entries_table.php';

    config(['larsadmin.audit.table' => 'custom_audit']);
    $migration->up();

    expect(DB::getSchemaBuilder()->hasTable('custom_audit'))->toBeTrue()
        ->and($migration->getConnection())->toBeNull();

    $migration->down();

    expect(DB::getSchemaBuilder()->hasTable('custom_audit'))->toBeFalse();
});
