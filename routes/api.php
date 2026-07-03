<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DraftController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

// Public routes — rate limited to prevent brute force on 4-digit PIN
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

// Protected routes — kasir only
Route::middleware(['auth:sanctum', 'kasir-only'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // Products
    Route::get('/products', [ProductController::class, 'index']);

    // Cashier session
    Route::get('/session', [SessionController::class, 'status']);
    Route::post('/session/open', [SessionController::class, 'open'])->middleware('throttle:10,1');
    Route::post('/session/close', [SessionController::class, 'close'])->middleware('throttle:10,1');

    // Transactions
    Route::post('/transactions', [TransactionController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);
    Route::get('/recap', [TransactionController::class, 'recap']);
    Route::get('/history', [TransactionController::class, 'history']);
    Route::post('/transactions/{transaction}/void', [TransactionController::class, 'void']);

    // Drafts
    Route::post('/draft', [DraftController::class, 'save'])->middleware('throttle:30,1');
    Route::put('/draft/auto-save', [DraftController::class, 'autoSave'])->middleware('throttle:60,1');
    Route::post('/draft/clear', [DraftController::class, 'clear'])->middleware('throttle:30,1');
    Route::delete('/draft/{transaction}', [DraftController::class, 'destroy']);
});
