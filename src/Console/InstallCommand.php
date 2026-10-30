<?php

namespace Maxcxam\Larsadmin\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'larsadmin:install')]
class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'larsadmin:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the Larsadmin config, assets, service provider and migrations';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->components->info('Installing Larsadmin.');

        $this->call('vendor:publish', ['--tag' => 'larsadmin-config']);
        $this->call('vendor:publish', ['--tag' => 'larsadmin-assets', '--force' => true]);
        $this->call('vendor:publish', ['--tag' => 'larsadmin-provider']);
        $this->call('vendor:publish', ['--tag' => 'larsadmin-migrations']);

        $this->registerApplicationProvider();
        $this->installUkrainianValidation();

        $this->components->info('Larsadmin installed. Visit '.url(config('larsadmin.path')).' to get started.');
        $this->components->info('To enable the audit log: run `php artisan migrate`, define the "viewLarsadminAudit" gate, set LARSADMIN_AUDIT=true and schedule `php artisan larsadmin:prune-audit`.');
    }

    /**
     * Copy the Ukrainian validation messages into the application, never overwriting an existing file.
     */
    protected function installUkrainianValidation(): void
    {
        $target = lang_path('uk/validation.php');

        if (file_exists($target)) {
            $this->components->info('lang/uk/validation.php already exists, leaving it untouched.');

            return;
        }

        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($target));
        $files->copy(__DIR__.'/../../stubs/lang/uk/validation.php', $target);

        $this->components->info('Ukrainian validation messages copied to lang/uk/validation.php.');
    }

    /**
     * Register the published service provider in the application.
     */
    protected function registerApplicationProvider(): void
    {
        if (! file_exists(app_path('Providers/LarsadminServiceProvider.php'))) {
            return;
        }

        ServiceProvider::addProviderToBootstrapFile('App\\Providers\\LarsadminServiceProvider');
    }
}
