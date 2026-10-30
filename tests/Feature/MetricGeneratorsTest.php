<?php

use App\Larsadmin\Dashboards\GenSalesDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Maxcxam\Larsadmin\Dashboards\Dashboard;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Metrics\Partition;
use Maxcxam\Larsadmin\Metrics\Progress;
use Maxcxam\Larsadmin\Metrics\Trend;
use Maxcxam\Larsadmin\Metrics\Value;

afterEach(function () {
    File::deleteDirectory(app_path('Larsadmin/Metrics'));
    File::deleteDirectory(app_path('Larsadmin/Dashboards'));
});

it('registers the generator commands', function (string $command) {
    expect(array_keys(Artisan::all()))->toContain($command);
})->with(['larsadmin:dashboard', 'larsadmin:value', 'larsadmin:trend', 'larsadmin:partition', 'larsadmin:progress']);

it('generates each metric class into App\\Larsadmin\\Metrics', function (string $command, string $name, string $parent) {
    $path = app_path("Larsadmin/Metrics/{$name}.php");
    File::delete($path);

    expect(Artisan::call($command, ['name' => $name]))->toBe(0)
        ->and(File::exists($path))->toBeTrue()
        ->and(File::get($path))
        ->toContain('namespace App\Larsadmin\Metrics;')
        ->toContain("class {$name} extends ".class_basename($parent))
        ->toContain('public function calculate(MetricRequest $request)')
        ->toContain('// Placeholder:')
        ->toContain('// SECURITY: unscoped — replace with your Entity class')
        ->toContain('cacheKeyParts()')
        ->toContain('$this->scoped(')
        ->not->toContain('{{');

    // The generated class loads, instantiates and serializes as a card.
    require_once $path;
    $class = "App\\Larsadmin\\Metrics\\{$name}";
    $card = $class::make();

    expect($card)->toBeInstanceOf($parent)
        ->and($card->jsonSerialize())->toMatchArray(['uriKey' => str($name)->kebab()->toString()])
        ->and($card->jsonSerialize()['component'])->toBe($card->component);
})->with([
    'value' => ['larsadmin:value', 'GenValueMetric', Value::class],
    'trend' => ['larsadmin:trend', 'GenTrendMetric', Trend::class],
    'partition' => ['larsadmin:partition', 'GenPartitionMetric', Partition::class],
    'progress' => ['larsadmin:progress', 'GenProgressMetric', Progress::class],
]);

it('generates a dashboard class into App\\Larsadmin\\Dashboards', function () {
    $path = app_path('Larsadmin/Dashboards/GenSalesDashboard.php');

    expect(Artisan::call('larsadmin:dashboard', ['name' => 'GenSalesDashboard']))->toBe(0)
        ->and(File::get($path))
        ->toContain('namespace App\Larsadmin\Dashboards;')
        ->toContain('class GenSalesDashboard extends Dashboard')
        ->toContain('public function cards(Request $request): array')
        ->not->toContain('function name()');

    require_once $path;
    $dashboard = GenSalesDashboard::make();

    // The name is the humanized class name of the base class, not "GenSalesDashboard".
    expect($dashboard)->toBeInstanceOf(Dashboard::class)
        ->and($dashboard->name())->toBe('Gen Sales Dashboard')
        ->and($dashboard->uriKey())->toBe('gen-sales-dashboard')
        ->and($dashboard->cards(Request::create('/')))->toBe([])
        ->and($dashboard->authorizedToSee(Request::create('/')))->toBeTrue();
});

it('supports nested names', function () {
    Artisan::call('larsadmin:value', ['name' => 'Reports/NestedRevenue']);

    $path = app_path('Larsadmin/Metrics/Reports/NestedRevenue.php');

    expect(File::get($path))->toContain('namespace App\Larsadmin\Metrics\Reports;');
});

it('only overwrites an existing class with --force', function (string $command, string $directory, string $name) {
    $path = app_path("Larsadmin/{$directory}/{$name}.php");

    Artisan::call($command, ['name' => $name]);
    File::put($path, 'custom');

    Artisan::call($command, ['name' => $name]);
    expect(File::get($path))->toBe('custom');

    Artisan::call($command, ['name' => $name, '--force' => true]);
    expect(File::get($path))->toContain("class {$name} extends");
})->with([
    'dashboard' => ['larsadmin:dashboard', 'Dashboards', 'ForceDashboard'],
    'value' => ['larsadmin:value', 'Metrics', 'ForceValue'],
    'trend' => ['larsadmin:trend', 'Metrics', 'ForceTrend'],
    'partition' => ['larsadmin:partition', 'Metrics', 'ForcePartition'],
    'progress' => ['larsadmin:progress', 'Metrics', 'ForceProgress'],
]);

it('is not picked up as an entity when the directory is registered', function () {
    Artisan::call('larsadmin:value', ['name' => 'IgnoredMetric']);
    Artisan::call('larsadmin:dashboard', ['name' => 'IgnoredDashboard']);

    Larsadmin::entitiesIn(app_path('Larsadmin'));

    expect(Larsadmin::registeredEntities())->toBe([]);
});

it('ships a service provider stub that registers the Main dashboard', function () {
    $stub = File::get(__DIR__.'/../../stubs/LarsadminServiceProvider.stub');

    expect($stub)->toContain('protected function dashboards(): array')
        ->toContain('use Maxcxam\Larsadmin\Dashboards\Main;')
        ->toContain('new Main,');
});
