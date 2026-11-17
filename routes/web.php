<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\PinLoginController;
use Illuminate\Support\Facades\Auth;

// --- LOGIN KASIR (PIN) ---
Route::get('/pin-login', [PinLoginController::class, 'show'])->name('pin.login');
Route::post('/pin-login', [PinLoginController::class, 'store'])->name('pin.login.store')->middleware('throttle:5,1');

// --- ROUTE UTAMA (ROOT) ---
Route::get('/', function () {
    if (!auth()->check()) {
        // Kasir ke PIN login, admin ke login biasa
        return redirect()->route('pin.login');
    }

    $user = auth()->user();

    if ($user->isOwner() || $user->isAdmin()) {
        return redirect('/admin');
    }

    return $user->isCashier()
        ? redirect()->route('transactions.create')
        : redirect()->route('pin.login');
});

Route::post('/logout', function () {
    $user = auth()->user();
    Auth::guard('web')->logout();

    request()->session()->invalidate();
    request()->session()->regenerateToken();

    // Admin balik ke login biasa, kasir ke PIN login
    if ($user && ($user->isOwner() || $user->isAdmin())) {
        return redirect('/login');
    }

    return redirect()->route('pin.login');
})->middleware(['auth', 'prevent-open-cashier-logout'])->name('logout');

// Switch user — force logout without session check, redirect to PIN login
Route::post('/switch-user', function () {
    Auth::guard('web')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('pin.login');
})->middleware(['auth'])->name('switch-user');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', function () {
        $user = auth()->user();

        if ($user->isOwner() || $user->isAdmin()) {
            return redirect('/admin'); // Admin ke Filament
        }

        if ($user->isCashier()) {
            return redirect()->route('transactions.create'); // Kasir ke POS
        }

        return redirect()->route('pin.login');
    })->name('dashboard');

    // Rute Kasir (hanya untuk kasir, admin dilempar ke /admin)
    Route::middleware('kasir-only')->group(function () {
        Route::get('/pos', [TransactionController::class, 'create'])->name('transactions.create');
        Route::get('/pos/recap', [TransactionController::class, 'recap'])->name('transactions.recap');
        Route::get('/pos/transaction/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
        Route::post('/pos', [TransactionController::class, 'store'])->name('transactions.store')->middleware('throttle:30,1');
        Route::post('/pos/session/open', [TransactionController::class, 'openSession'])->name('transactions.session.open');
        Route::post('/pos/session/close', [TransactionController::class, 'closeSession'])->name('transactions.session.close');
        Route::get('/pos/checkout/{transaction?}', [TransactionController::class, 'checkout'])->name('transactions.checkout');
        Route::post('/pos/draft', [TransactionController::class, 'saveDraft'])->name('transactions.draft.save');
        Route::put('/pos/draft/auto-save', [TransactionController::class, 'autoSaveDraft'])->name('transactions.draft.autoSave');
        Route::post('/pos/draft/clear', [TransactionController::class, 'clearDraft'])->name('transactions.draft.clear');
        Route::delete('/pos/draft/{transaction}', [TransactionController::class, 'destroyDraft'])->name('transactions.draft.destroy');
        Route::get('/pos/history', [TransactionController::class, 'history'])->name('transactions.history');
        Route::post('/pos/transaction/{transaction}/void', [TransactionController::class, 'void'])->name('transactions.void');

        Route::get('/settings/printer', function () {
            return Inertia::render('settings/Printer');
        })->name('settings.printer');
    });
});

require __DIR__.'/settings.php';
