<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\{ImportController, BatchController, ExportController, AuthController};

Route::middleware('isLogin')->group(function () {
    Route::get('/', [BatchController::class, 'index'])->name('batches.index');
    Route::get('/batches/{batch}', [BatchController::class, 'show'])->name('batches.show');

    Route::get('/import', [ImportController::class, 'create'])->name('import.create');
    Route::post('/import', [ImportController::class, 'store'])->name('import.store');

    Route::get('/batches/{batch}/export.csv', [ExportController::class, 'csv'])->name('batches.export.csv');
    Route::get('/batches/{batch}/keywords', [BatchController::class, 'keywords'])->name('batches.keywords');

    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

    Route::post('/import-batches/{batch}/refresh-prices', [ImportController::class, 'refreshPrices'])
        ->name('batches.refresh.prices');

    Route::get('/import-batches/{batch}/poll-status', [ImportController::class, 'pollStatus'])
        ->name('batches.poll.status');
});

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.perform');