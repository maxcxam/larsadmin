<?php

use Illuminate\Support\Facades\Route;
use Maxcxam\Larsadmin\Http\Controllers\ActionController;
use Maxcxam\Larsadmin\Http\Controllers\AttachController;
use Maxcxam\Larsadmin\Http\Controllers\AuditController;
use Maxcxam\Larsadmin\Http\Controllers\BroadcastAuthController;
use Maxcxam\Larsadmin\Http\Controllers\DashboardController;
use Maxcxam\Larsadmin\Http\Controllers\DownloadController;
use Maxcxam\Larsadmin\Http\Controllers\EntityController;
use Maxcxam\Larsadmin\Http\Controllers\ExtensionAssetController;
use Maxcxam\Larsadmin\Http\Controllers\FileController;
use Maxcxam\Larsadmin\Http\Controllers\GlobalSearchController;
use Maxcxam\Larsadmin\Http\Controllers\LocaleController;
use Maxcxam\Larsadmin\Http\Controllers\LoginController;
use Maxcxam\Larsadmin\Http\Controllers\MetricController;
use Maxcxam\Larsadmin\Http\Controllers\NotificationController;
use Maxcxam\Larsadmin\Http\Controllers\RelatableController;
use Maxcxam\Larsadmin\Http\Controllers\RelatedController;
use Maxcxam\Larsadmin\Http\Controllers\RunController;
use Maxcxam\Larsadmin\Http\Controllers\ToolController;

Route::get('login', [LoginController::class, 'show'])->name('login');
Route::post('login', [LoginController::class, 'store'])->middleware('throttle:larsadmin-login')->name('login.store');
Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
Route::post('locale', [LocaleController::class, 'update'])->middleware('throttle:30,1')->name('locale.update');

Route::middleware(config('larsadmin.auth_middleware', []))->group(function () {
    Route::get('/', [DashboardController::class, 'home'])->name('dashboard');
    Route::get('dashboards/{dashboard}', [DashboardController::class, 'show'])->name('dashboards.show');

    Route::get('api/dashboards/{dashboard}/metrics/{metric}', [MetricController::class, 'dashboard'])->middleware('throttle:larsadmin-metrics')->name('api.dashboard-metric');
    Route::get('api/entities/{entity}/metrics/{metric}', [MetricController::class, 'entity'])->middleware('throttle:larsadmin-metrics')->name('api.entity-metric');

    Route::get('api/search', [GlobalSearchController::class, 'index'])->middleware('throttle:larsadmin-search')->name('api.search');

    Route::get('api/entities/{entity}/fields/{field}/options', [RelatableController::class, 'index'])->name('api.options');
    Route::get('api/entities/{entity}/{id}/related/{field}', [RelatedController::class, 'index'])->name('api.related');
    Route::get('api/entities/{entity}/{id}/audit', [AuditController::class, 'record'])->middleware('throttle:larsadmin-audit')->name('api.audit');

    Route::get('audit', [AuditController::class, 'index'])->middleware('throttle:larsadmin-audit')->name('audit.index');

    // Notifications and queued actions (1.4): the bell and run routes answer 404 while "larsadmin.notifications.enabled" is off.
    Route::middleware('throttle:larsadmin-notifications')->group(function () {
        Route::get('api/notifications', [NotificationController::class, 'index'])->name('api.notifications');
        Route::get('api/notifications/status', [NotificationController::class, 'status'])->name('api.notifications.status');
        Route::post('api/notifications/read', [NotificationController::class, 'readAll'])->name('api.notifications.read-all');
        Route::post('api/notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('api.notifications.read');
        Route::delete('api/notifications/{notification}', [NotificationController::class, 'destroy'])->whereUuid('notification')->name('api.notifications.destroy');

        Route::get('api/runs', [RunController::class, 'index'])->name('api.runs');
        Route::get('api/runs/{run}', [RunController::class, 'show'])->whereUlid('run')->name('api.runs.show');
        Route::post('api/runs/{run}/cancel', [RunController::class, 'cancel'])->whereUlid('run')->name('api.runs.cancel');

        Route::post('broadcasting/auth', BroadcastAuthController::class)->name('broadcasting.auth');
    });

    Route::middleware('throttle:larsadmin-files')->group(function () {
        Route::get('runs/{run}/download', [RunController::class, 'download'])->whereUlid('run')->name('runs.download');
        Route::get('downloads/{token}', DownloadController::class)->where('token', '[A-Za-z0-9_\-=]+')->name('downloads.show');

        // Built files of registered extensions (1.5, ADR-031): the controller only serves allow-listed files inside the registered directory.
        Route::get('extensions/{vendor}/{package}/{version}/{file}', [ExtensionAssetController::class, 'show'])
            ->where(['vendor' => '[a-z0-9_.\-]+', 'package' => '[a-z0-9_.\-]+', 'version' => '[a-f0-9]{1,64}', 'file' => '.+'])
            ->name('extensions.asset');
    });

    // Tools of extensions (1.5): a page with the tool's component; an unknown or invisible tool is a 404.
    Route::get('tools/{tool}', [ToolController::class, 'show'])->name('tools.show');

    Route::get('files/{entity}/{id}/{field}/{token}', [FileController::class, 'show'])
        ->where('token', '[a-f0-9]{32}')
        ->middleware(['signed:relative', 'throttle:larsadmin-files'])
        ->name('files.show');

    Route::prefix('entities/{entity}')->name('entities.')->controller(EntityController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::post('actions/{action}', [ActionController::class, 'run'])->name('actions.run');
        Route::get('{id}', 'show')->name('show');
        Route::get('{id}/edit', 'edit')->name('edit');
        Route::put('{id}', 'update')->name('update');
        Route::delete('{id}', 'destroy')->name('destroy');
        Route::post('{id}/attach/{field}', [AttachController::class, 'store'])->name('attach');
        Route::delete('{id}/attach/{field}/{relatedId}', [AttachController::class, 'destroy'])->name('detach');
    });
});
