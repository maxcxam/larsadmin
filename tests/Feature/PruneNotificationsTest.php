<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Console\InstallCommand;
use Maxcxam\Larsadmin\Console\PruneNotificationsCommand;
use Maxcxam\Larsadmin\LarsadminServiceProvider;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * Insert $count notifications of the type created $daysAgo days ago.
 */
function pnNotifications(int $count, int $daysAgo, string $type = 'larsadmin'): void
{
    $createdAt = now()->subDays($daysAgo)->format('Y-m-d H:i:s');

    foreach (array_chunk(range(1, $count), 500) as $chunk) {
        DB::table('notifications')->insert(array_map(fn () => [
            'id' => (string) Str::uuid(),
            'type' => $type,
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id' => 1,
            'data' => '{}',
            'read_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ], $chunk));
    }
}

function pnRun(int $daysAgo, string $status = 'completed', ?array $result = null): ActionRun
{
    $run = ActionRun::query()->create([
        'entity' => 'posts', 'action' => 'export', 'action_name' => 'Export', 'user_type' => 'App\\Models\\User',
        'user_id' => '1', 'locale' => 'en', 'status' => $status, 'result' => $result,
    ]);
    $run->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

    return $run;
}

/**
 * Give the run a file() result: its own copy under larsadmin/runs/{run}/ (as the worker makes it), or any path.
 */
function pnFile(ActionRun $run, string $name, ?string $path = null, bool $put = true): string
{
    $path ??= ActionRun::filePrefix($run->id).$name;

    if ($put) {
        Storage::disk('local')->put($path, 'x');
    }

    $run->forceFill(['result' => ['type' => 'file', 'disk' => 'local', 'path' => $path, 'filename' => $name]])->save();

    return $path;
}

it('deletes panel notifications, runs and their own result files older than 30 days by default', function () {
    pnNotifications(3, 40);
    pnNotifications(2, 10);
    pnNotifications(2, 40, 'App\Notifications\InvoicePaid');
    $old = pnFile(pnRun(40), 'old.csv');
    pnRun(40, 'running');
    pnRun(31, 'queued');
    pnFile(pnRun(45), 'x', '../outside.csv', false);
    pnFile(pnRun(45), 'gone.csv', put: false);
    $host = pnFile(pnRun(45), 'inv-1.pdf', 'invoices/inv-1.pdf');
    $kept = pnRun(5);
    $new = pnFile($kept, 'new.csv');

    $this->artisan('larsadmin:prune-notifications')
        ->expectsOutputToContain('Deleted 3 panel notifications, 6 action runs and 1 result file older than 30 days.')
        ->assertSuccessful();

    expect(DB::table('notifications')->where('type', 'larsadmin')->count())->toBe(2)
        ->and(DB::table('notifications')->where('type', '!=', 'larsadmin')->count())->toBe(2)
        ->and(ActionRun::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(Storage::disk('local')->exists($old))->toBeFalse()
        ->and(Storage::disk('local')->directoryExists(dirname($old)))->toBeFalse()
        ->and(Storage::disk('local')->exists($new))->toBeTrue()
        // A host file a run pointed at is never the panel's to delete (SECN-03).
        ->and(Storage::disk('local')->exists($host))->toBeTrue();
});

it('fails abandoned runs before pruning: once, notified, payload erased (CRN-02)', function () {
    config(['larsadmin.actions.stale_after' => 600, 'larsadmin.actions.queued_stale_after' => 3600]);
    $this->travelTo(now()->subHours(2));
    $stale = pnRun(0, 'running');
    $stale->forceFill(['payload' => ['selection' => [1]], 'ran' => 0])->save();
    $staleQueued = pnRun(0, 'queued');
    $this->travelBack();
    $this->travelTo(now()->subMinutes(5));
    $fresh = pnRun(0, 'running');
    $this->travelBack();

    $this->artisan('larsadmin:prune-notifications')->expectsOutputToContain('Failed 2 abandoned action runs')->assertSuccessful();

    expect($stale->refresh()->only(['status', 'level', 'payload']))->toBe(['status' => 'failed', 'level' => 'danger', 'payload' => null])
        ->and($staleQueued->refresh()->status)->toBe('failed')
        ->and($fresh->refresh()->status)->toBe('running');

    // Once: a second sweep finds nothing.
    $this->artisan('larsadmin:prune-notifications')->doesntExpectOutputToContain('abandoned')->assertSuccessful();
});

it('uses the configured retention and the --days option', function () {
    pnNotifications(1, 20);
    pnNotifications(1, 5);
    pnRun(20);

    config(['larsadmin.notifications.retention_days' => 10]);
    $this->artisan('larsadmin:prune-notifications')->expectsOutputToContain('Deleted 1 panel notification, 1 action run and 0 result files older than 10 days.')->assertSuccessful();

    $this->artisan('larsadmin:prune-notifications', ['--days' => '2'])->assertSuccessful();

    expect(DB::table('notifications')->count())->toBe(0);
});

it('keeps everything when the retention is null', function () {
    config(['larsadmin.notifications.retention_days' => null]);
    pnNotifications(1, 1000);

    $this->artisan('larsadmin:prune-notifications')->expectsOutputToContain('retention is disabled')->assertSuccessful();

    expect(DB::table('notifications')->count())->toBe(1);
});

it('rejects an invalid number of days', function (string $days) {
    $this->artisan('larsadmin:prune-notifications', ['--days' => $days])->assertFailed();
})->with(['zero' => ['0'], 'negative' => ['-3'], 'text' => ['abc']]);

it('deletes in chunks of 1000', function () {
    pnNotifications(PruneNotificationsCommand::CHUNK + 5, 40);
    DB::enableQueryLog();

    $this->artisan('larsadmin:prune-notifications')->assertSuccessful();

    $deletes = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_starts_with(strtolower($sql), 'delete') && str_contains($sql, 'notifications'));

    expect($deletes)->toHaveCount(2)->and(DB::table('notifications')->count())->toBe(0);
});

it('works while notifications are disabled and skips missing tables', function () {
    config(['larsadmin.notifications.enabled' => false]);
    pnRun(40);
    Schema::drop('notifications');

    $this->artisan('larsadmin:prune-notifications')->expectsOutputToContain('does not exist')->assertSuccessful();
    expect(ActionRun::count())->toBe(0);

    Schema::drop('larsadmin_action_runs');
    $this->artisan('larsadmin:prune-notifications')->assertSuccessful();
});

it('prunes a run whose file cannot be deleted, reporting the error', function () {
    Exceptions::fake();
    $run = pnRun(40);
    $run->forceFill(['result' => ['type' => 'file', 'disk' => 'not-configured', 'path' => ActionRun::filePrefix($run->id).'a.csv', 'filename' => 'a.csv']])->save();

    $this->artisan('larsadmin:prune-notifications')->assertSuccessful();

    expect(ActionRun::count())->toBe(0);
    Exceptions::assertReported(InvalidArgumentException::class);
});

it('is registered and publishes the runs migration with the package migrations', function () {
    expect(array_key_exists('larsadmin:prune-notifications', $this->app[Kernel::class]->all()))->toBeTrue();

    $paths = ServiceProvider::pathsToPublish(LarsadminServiceProvider::class, 'larsadmin-migrations');
    $source = array_key_first($paths);

    expect(file_exists($source.'/2026_11_15_000000_create_larsadmin_action_runs_table.php'))->toBeTrue();
});

it('publishes the migrations and mentions notifications on install', function () {
    $command = new class extends InstallCommand
    {
        protected $signature = 'larsadmin:install-notifications';

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

    $this->artisan('larsadmin:install-notifications')
        ->expectsOutputToContain('make:notifications-table')
        ->assertSuccessful();

    expect($command::$calls)->toContain(['vendor:publish', ['--tag' => 'larsadmin-migrations']]);
});

it('drops the runs table on rollback', function () {
    $migration = require __DIR__.'/../../database/migrations/2026_11_15_000000_create_larsadmin_action_runs_table.php';

    $migration->down();
    expect(Schema::hasTable('larsadmin_action_runs'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('larsadmin_action_runs'))->toBeTrue();
});

it('registers the notifications rate limiter', function () {
    expect(RateLimiter::limiter('larsadmin-notifications'))->not->toBeNull();
});
