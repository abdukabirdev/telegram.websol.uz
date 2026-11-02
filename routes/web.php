<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\CatalogController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\GroupController;
use App\Http\Controllers\Web\LocaleController;
use App\Http\Controllers\Web\Monitoring\AnalyticsController;
use App\Http\Controllers\Web\Monitoring\ApiLogController;
use App\Http\Controllers\Web\Monitoring\ApiUsageController;
use App\Http\Controllers\Web\Monitoring\EventController;
use App\Http\Controllers\Web\Monitoring\LogController;
use App\Http\Controllers\Web\Monitoring\OverviewController;
use App\Http\Controllers\Web\Monitoring\SettingsController;
use App\Http\Controllers\Web\PhoneController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\SendController;
use App\Http\Controllers\Web\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::post('locale/{locale}', LocaleController::class)->name('locale');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'create'])->name('login');
    Route::post('login', [AuthController::class, 'store'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthController::class, 'destroy'])->name('logout');

    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard/data', [DashboardController::class, 'data'])->name('dashboard.data');
    Route::get('pulse', [DashboardController::class, 'pulse'])->name('dashboard.pulse');

    // Telegram accounts and their login
    Route::prefix('phones')->name('phones.')->controller(PhoneController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('data', 'data')->name('data');
        Route::post('/', 'store')->name('store')->middleware('throttle:10,1');
        Route::get('{phone}', 'show')->name('show');
        Route::post('{phone}/code', 'code')->name('code')->middleware('throttle:10,1');
        Route::post('{phone}/password', 'password')->name('password')->middleware('throttle:10,1');
        Route::post('{phone}/relogin', 'relogin')->name('relogin')->middleware('throttle:10,1');
        Route::post('{phone}/cancel', 'cancel')->name('cancel');
        Route::post('{phone}/check', 'check')->name('check');
        Route::post('{phone}/logout', 'logout')->name('logout');
        Route::delete('{phone}', 'destroy')->name('destroy');
    });

    // Catalogs (peer lists)
    Route::get('catalogs/data', [CatalogController::class, 'data'])->name('catalogs.data');
    Route::get('catalogs/users', [CatalogController::class, 'users'])->name('catalogs.users');
    Route::post('catalogs/{catalog}/copy', [CatalogController::class, 'copy'])->name('catalogs.copy');
    Route::post('catalogs/{catalog}/distribute', [CatalogController::class, 'distribute'])->name('catalogs.distribute');
    Route::post('catalogs/{catalog}/remove-peer', [CatalogController::class, 'removePeer'])->name('catalogs.remove-peer');
    Route::resource('catalogs', CatalogController::class)->except('show');

    // Sending
    Route::get('send', [SendController::class, 'create'])->name('send.create');
    Route::get('send/preview', [SendController::class, 'preview'])->name('send.preview');
    Route::post('send', [SendController::class, 'store'])->name('send.store')->middleware('throttle:20,1');

    Route::prefix('sends')->name('sends.')->controller(GroupController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('data', 'data')->name('data');
        Route::post('cancel-all', 'cancelAll')->name('cancel-all');
        Route::get('{group}', 'show')->name('show');
        Route::get('{group}/progress', 'progress')->name('progress');
        Route::post('{group}/cancel', 'cancel')->name('cancel');
        Route::post('{group}/retry', 'retry')->name('retry');
        Route::post('loops/{loop}/stop', 'stopLoop')->name('loops.stop');
    });

    // Profile
    Route::prefix('profile')->name('profile.')->controller(ProfileController::class)->group(function () {
        Route::get('/', 'edit')->name('edit');
        Route::put('/', 'update')->name('update');
        Route::post('avatar', 'avatar')->name('avatar');
        Route::delete('avatar', 'deleteAvatar')->name('avatar.delete');
        Route::post('tokens', 'createToken')->name('tokens.store');
        Route::delete('tokens/{token}', 'deleteToken')->name('tokens.destroy');
    });

    // Admins and the superadmin (accounts are the only difference: User::oversees)
    Route::middleware('can:admin')->group(function () {
        Route::get('users/data', [UserController::class, 'data'])->name('users.data');
        Route::resource('users', UserController::class)->except('show');

        Route::prefix('monitoring')->name('monitoring.')->group(function () {
            Route::get('/', [OverviewController::class, 'index'])->name('index');
            Route::get('data', [OverviewController::class, 'data'])->name('data');

            Route::get('events', [EventController::class, 'index'])->name('events');
            Route::get('events/data', [EventController::class, 'data'])->name('events.data');
            Route::post('events/resolve-all', [EventController::class, 'resolveAll'])->name('events.resolve-all');
            Route::get('events/{event}', [EventController::class, 'show'])->name('events.show');
            Route::post('events/{event}/resolve', [EventController::class, 'resolve'])->name('events.resolve');

            Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics');
            Route::get('analytics/data', [AnalyticsController::class, 'data'])->name('analytics.data');

            Route::get('api', ApiUsageController::class)->name('api');
            Route::get('api/logs', [ApiLogController::class, 'index'])->name('api.logs');
            Route::get('api/logs/{log}', [ApiLogController::class, 'show'])->name('api.logs.show');
            Route::get('logs', LogController::class)->name('logs');

            Route::get('settings', [SettingsController::class, 'edit'])->name('settings');
            Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
            Route::post('settings/test-alert', [SettingsController::class, 'testAlert'])->name('settings.test-alert');
            Route::post('settings/sweep', [SettingsController::class, 'sweep'])->name('settings.sweep');
        });
    });
});
