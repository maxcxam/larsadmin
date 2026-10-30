<?php

use Illuminate\Support\Facades\Route;
use Maxcxam\Larsadmin\Http\Controllers\ActionController;
use Maxcxam\Larsadmin\Http\Controllers\AttachController;
use Maxcxam\Larsadmin\Http\Controllers\DashboardController;
use Maxcxam\Larsadmin\Http\Controllers\EntityController;
use Maxcxam\Larsadmin\Http\Controllers\LocaleController;
use Maxcxam\Larsadmin\Http\Controllers\LoginController;
use Maxcxam\Larsadmin\Http\Controllers\MetricController;
use Maxcxam\Larsadmin\Http\Controllers\RelatableController;
use Maxcxam\Larsadmin\Http\Controllers\RelatedController;

Route::get('login', [LoginController::class, 'show'])->name('login');
Route::post('login', [LoginController::class, 'store'])->middleware('throttle:larsadmin-login')->name('login.store');
Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
Route::post('locale', [LocaleController::class, 'update'])->middleware('throttle:30,1')->name('locale.update');

Route::middleware(config('larsadmin.auth_middleware', []))->group(function () {
    Route::get('/', [DashboardController::class, 'home'])->name('dashboard');
    Route::get('dashboards/{dashboard}', [DashboardController::class, 'show'])->name('dashboards.show');

    Route::get('api/dashboards/{dashboard}/metrics/{metric}', [MetricController::class, 'dashboard'])->middleware('throttle:larsadmin-metrics')->name('api.dashboard-metric');
    Route::get('api/entities/{entity}/metrics/{metric}', [MetricController::class, 'entity'])->middleware('throttle:larsadmin-metrics')->name('api.entity-metric');

    Route::get('api/entities/{entity}/fields/{field}/options', [RelatableController::class, 'index'])->name('api.options');
    Route::get('api/entities/{entity}/{id}/related/{field}', [RelatedController::class, 'index'])->name('api.related');

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
