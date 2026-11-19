<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => to_route(auth()->check() ? 'generations.index' : 'login'))->name('home');

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', 'generations')->name('dashboard');

    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('generations.store');
    Route::get('generations/{generation}', [GenerationController::class, 'show'])->name('generations.show');

    Route::get('admin/report', ReportController::class)
        ->middleware('can:viewAdminReport')
        ->name('admin.report');
    Route::post('admin/report/sync', [ReportController::class, 'sync'])
        ->middleware(['can:viewAdminReport', 'throttle:10,1'])
        ->name('admin.report.sync');
});

require __DIR__.'/settings.php';
