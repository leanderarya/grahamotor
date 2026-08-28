# Sprint 1: Security Hardening

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all critical security vulnerabilities in the Graha Mesran POS application — PIN hashing, brute-force protection, API rate limiting, token expiry, and authorization checks.

**Architecture:** Extend existing Laravel authentication system with proper password hashing for PINs, add failed attempt tracking, implement rate limiting on API routes, add token expiration, and enforce ownership checks on data access.

**Tech Stack:** Laravel 12, Laravel Sanctum, MySQL

## Global Constraints

- All changes must be backward-compatible with existing data (migration-based)
- No breaking changes to the Capacitor Android app
- PINs are 4 digits, stored and verified via hashing
- Kasir users authenticate via PIN only (no email/password)
- Admin users authenticate via email/password through Filament

---

## Task 1: Hash PINs

**Files:**
- Create: `database/migrations/2026_07_05_000000_hash_user_pins.php`
- Modify: `app/Models/User.php`
- Modify: `app/Http/Controllers/PinLoginController.php`
- Modify: `app/Http/Controllers/Api/AuthController.php`

**Interfaces:**
- Produces: PINs stored as bcrypt hashes in the `users` table
- Consumes: Existing plaintext PINs (one-time migration)

### Step 1: Create migration to hash existing PINs

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        // Hash all existing plaintext PINs
        $users = DB::table('users')
            ->whereNotNull('pin')
            ->where('pin', '!=', '')
            ->get();

        foreach ($users as $user) {
            // Only hash if not already hashed (check for bcrypt prefix)
            if (!str_starts_with($user->pin, '$2y$')) {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['pin' => Hash::make($user->pin)]);
            }
        }
    }

    public function down(): void
    {
        // Cannot reverse — PINs are one-way hashed
        // This is acceptable for a security migration
    }
};
```

### Step 2: Update User model

In `app/Models/User.php`, update the casts:

```php
protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'pin' => 'hashed', // Changed from 'string'
        'two_factor_confirmed_at' => 'datetime',
    ];
}
```

### Step 3: Update PinLoginController

In `app/Http/Controllers/PinLoginController.php`, replace the login logic:

```php
public function store(Request $request)
{
    $request->validate([
        'pin' => ['required', 'digits:4'],
    ]);

    // Find kasir user — PIN comparison is now done via Hash::check
    $user = User::where('role', 'kasir')
        ->whereNotNull('pin')
        ->first();

    if (! $user || !Hash::check($request->pin, $user->pin)) {
        // Log failed attempt for lockout tracking (Task 3)
        return back()->withErrors([
            'pin' => 'PIN salah. Silakan coba lagi.',
        ]);
    }

    Auth::login($user);

    return redirect()->route('transactions.create');
}
```

### Step 4: Update Api/AuthController

In `app/Http/Controllers/Api/AuthController.php`, apply the same Hash::check logic:

```php
public function login(Request $request): JsonResponse
{
    $request->validate([
        'pin' => ['required', 'digits:4'],
    ]);

    $user = User::where('role', 'kasir')
        ->whereNotNull('pin')
        ->first();

    if (! $user || !Hash::check($request->pin, $user->pin)) {
        return response()->json([
            'message' => 'PIN salah.',
        ], 401);
    }

    // Revoke previous tokens (single device)
    $user->tokens()->delete();

    $token = $user->createToken('kasir-android', ['*'], now()->addDays(30))->plainTextToken;

    return response()->json([
        'token' => $token,
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
        ],
    ]);
}
```

### Step 5: Add Hash import

Add to both controllers:

```php
use Illuminate\Support\Facades\Hash;
```

### Step 6: Commit

```bash
git add app/Models/User.php app/Http/Controllers/PinLoginController.php app/Http/Controllers/Api/AuthController.php
git commit -m "security: hash PINs instead of storing plaintext"
```

---

## Task 2: Add API Rate Limiting

**Files:**
- Modify: `routes/api.php`
- Create: `app/Providers/RouteServiceProvider.php` (if not exists)

**Interfaces:**
- Produces: Rate limiting on `POST /api/transactions` (30 requests per minute)

### Step 1: Add throttle to API transaction routes

In `routes/api.php`, update the transaction store route:

```php
// Transactions
Route::post('/transactions', [TransactionController::class, 'store'])->middleware('throttle:30,1');
```

### Step 2: Add throttle to other write endpoints

```php
Route::post('/session/open', [SessionController::class, 'open'])->middleware('throttle:10,1');
Route::post('/session/close', [SessionController::class, 'close'])->middleware('throttle:10,1');
Route::post('/draft', [DraftController::class, 'save'])->middleware('throttle:30,1');
Route::put('/draft/auto-save', [DraftController::class, 'autoSave'])->middleware('throttle:60,1');
Route::post('/draft/clear', [DraftController::class, 'clear'])->middleware('throttle:30,1');
```

### Step 3: Commit

```bash
git add routes/api.php
git commit -m "security: add rate limiting to API write endpoints"
```

---

## Task 3: Implement PIN Lockout

**Files:**
- Create: `database/migrations/2026_07_05_000001_create_pin_attempts_table.php`
- Create: `app/Models/PinAttempt.php`
- Create: `app/Services/PinSecurityService.php`
- Modify: `app/Http/Controllers/PinLoginController.php`
- Modify: `app/Http/Controllers/Api/AuthController.php`

**Interfaces:**
- Produces: `PinSecurityService::recordAttempt($pin, $ip)`
- Produces: `PinSecurityService::isLockedOut($ip)`
- Produces: `PinSecurityService::getRemainingLockoutTime($ip)`

### Step 1: Create pin_attempts migration

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pin_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45); // IPv4 or IPv6
            $table->string('pin_used', 10); // The PIN that was tried
            $table->boolean('success')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ip_address', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pin_attempts');
    }
};
```

### Step 2: Create PinAttempt model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PinAttempt extends Model
{
    protected $fillable = [
        'ip_address',
        'pin_used',
        'success',
    ];

    protected $casts = [
        'success' => 'boolean',
    ];
}
```

### Step 3: Create PinSecurityService

```php
<?php

namespace App\Services;

use App\Models\PinAttempt;
use Carbon\Carbon;

class PinSecurityService
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;
    private const ATTEMPT_WINDOW_MINUTES = 15;

    /**
     * Record a PIN attempt (success or failure).
     */
    public function recordAttempt(string $pin, string $ip, bool $success): void
    {
        PinAttempt::create([
            'ip_address' => $ip,
            'pin_used' => $pin,
            'success' => $success,
        ]);

        // Cleanup old attempts (older than 1 hour)
        PinAttempt::where('created_at', '<', now()->subHour())->delete();
    }

    /**
     * Check if the IP is currently locked out.
     */
    public function isLockedOut(string $ip): bool
    {
        $recentFailures = $this->getRecentFailures($ip);

        return $recentFailures >= self::MAX_ATTEMPTS;
    }

    /**
     * Get remaining lockout time in seconds.
     */
    public function getRemainingLockoutTime(string $ip): int
    {
        $lastFailure = PinAttempt::where('ip_address', $ip)
            ->where('success', false)
            ->where('created_at', '>=', now()->subMinutes(self::LOCKOUT_MINUTES))
            ->latest('created_at')
            ->first();

        if (!$lastFailure) {
            return 0;
        }

        $lockoutEnd = $lastFailure->created_at->addMinutes(self::LOCKOUT_MINUTES);

        if ($lockoutEnd->isPast()) {
            return 0;
        }

        return (int) $lockoutEnd->diffInSeconds(now());
    }

    /**
     * Get number of recent failed attempts.
     */
    private function getRecentFailures(string $ip): int
    {
        return PinAttempt::where('ip_address', $ip)
            ->where('success', false)
            ->where('created_at', '>=', now()->subMinutes(self::ATTEMPT_WINDOW_MINUTES))
            ->count();
    }
}
```

### Step 4: Update PinLoginController

```php
public function store(Request $request)
{
    $request->validate([
        'pin' => ['required', 'digits:4'],
    ]);

    $ip = $request->ip();
    $pinSecurity = app(PinSecurityService::class);

    // Check if IP is locked out
    if ($pinSecurity->isLockedOut($ip)) {
        $remaining = $pinSecurity->getRemainingLockoutTime($ip);
        return back()->withErrors([
            'pin' => "Terlalu banyak percobaan. Coba lagi dalam {$remaining} detik.",
        ]);
    }

    // Find kasir user
    $user = User::where('role', 'kasir')
        ->whereNotNull('pin')
        ->first();

    if (! $user || !Hash::check($request->pin, $user->pin)) {
        // Record failed attempt
        $pinSecurity->recordAttempt($request->pin, $ip, false);

        return back()->withErrors([
            'pin' => 'PIN salah. Silakan coba lagi.',
        ]);
    }

    // Record successful attempt
    $pinSecurity->recordAttempt($request->pin, $ip, true);

    Auth::login($user);

    return redirect()->route('transactions.create');
}
```

### Step 5: Update Api/AuthController

Apply the same lockout logic to the API login endpoint.

### Step 6: Commit

```bash
git add app/Models/PinAttempt.php app/Services/PinSecurityService.php app/Http/Controllers/PinLoginController.php app/Http/Controllers/Api/AuthController.php
git commit -m "security: implement PIN brute-force lockout after 5 failed attempts"
```

---

## Task 4: Sanctum Token Expiration

**Files:**
- Modify: `app/Http/Controllers/Api/AuthController.php`

**Interfaces:**
- Produces: Tokens expire after 30 days

### Step 1: Update token creation

In `app/Http/Controllers/Api/AuthController.php`, update the login method:

```php
$token = $user->createToken('kasir-android', ['*'], now()->addDays(30))->plainTextToken;
```

### Step 2: Commit

```bash
git add app/Http/Controllers/Api/AuthController.php
git commit -m "security: set Sanctum token expiration to 30 days"
```

---

## Task 5: API Transaction Ownership Check

**Files:**
- Modify: `app/Http/Controllers/Api/TransactionController.php`

**Interfaces:**
- Produces: Kasir can only access their own transactions

### Step 1: Add ownership check to show method

```php
public function show(Transaction $transaction): JsonResponse
{
    // Ownership check: kasir can only view their own transactions
    if (auth()->id() !== $transaction->user_id) {
        return response()->json(['message' => 'Akses ditolak.'], 403);
    }

    $transaction->load(['transactionItems.product', 'user']);

    return response()->json([
        'transaction' => [
            // ... existing response data
        ],
    ]);
}
```

### Step 2: Add ownership check to void method

```php
public function void(Request $request, Transaction $transaction): JsonResponse
{
    // Ownership check
    if (auth()->id() !== $transaction->user_id) {
        return response()->json(['message' => 'Akses ditolak.'], 403);
    }

    // ... existing void logic
}
```

### Step 3: Commit

```bash
git add app/Http/Controllers/Api/TransactionController.php
git commit -m "security: add ownership check to transaction show and void endpoints"
```

---

## Task 6: Replace $guarded with $fillable

**Files:**
- Modify: `app/Models/Asset.php`
- Modify: `app/Models/StockAdjustment.php`

**Interfaces:**
- Produces: Explicit mass-assignment protection

### Step 1: Update Asset model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Asset extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'purchase_date',
        'price',
        'condition',
        'location',
        'note',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'price' => 'decimal:2',
    ];

    // ... rest of model
}
```

### Step 2: Update StockAdjustment model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustment extends Model
{
    protected $fillable = [
        'product_id',
        'user_id',
        'adjustment_date',
        'system_stock',
        'physical_stock',
        'difference',
        'type',
        'note',
    ];

    // ... rest of model
}
```

### Step 3: Commit

```bash
git add app/Models/Asset.php app/Models/StockAdjustment.php
git commit -m "security: replace \$guarded with explicit \$fillable on Asset and StockAdjustment"
```

---

## Task 7: Hide API Error Details

**Files:**
- Modify: `app/Http/Controllers/Api/TransactionController.php`

**Interfaces:**
- Produces: Generic error messages to client, detailed logs internally

### Step 1: Update error handling

```php
try {
    $transaction = $this->transactionService->processPayment(auth()->user(), $validated);

    return response()->json([
        'message' => 'Transaksi berhasil.',
        'transaction' => [
            // ... existing response
        ],
    ]);
} catch (ValidationException $e) {
    throw $e;
} catch (\Exception $e) {
    // Log the full error internally
    \Log::error('Transaction store failed', [
        'user_id' => auth()->id(),
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    // Return generic message to client
    return response()->json([
        'message' => 'Terjadi kesalahan sistem. Silakan coba lagi.',
    ], 500);
}
```

### Step 2: Add Log facade import

```php
use Illuminate\Support\Facades\Log;
```

### Step 3: Commit

```bash
git add app/Http/Controllers/Api/TransactionController.php
git commit -m "security: hide internal error details from API responses"
```

---

## Summary

| Task | Feature | Effort |
|------|---------|--------|
| 1 | Hash PINs | 2 hours |
| 2 | API rate limiting | 30 min |
| 3 | PIN lockout | 3 hours |
| 4 | Token expiration | 10 min |
| 5 | Ownership check | 15 min |
| 6 | Replace $guarded | 30 min |
| 7 | Hide error details | 30 min |
| **Total** | | **~6.5 hours** |

---

## Verification

After completing all tasks:

```bash
# Run migration
php artisan migrate

# Verify PIN hashing works
php artisan tinker --execute="echo App\Models\User::first()->pin;"

# Verify rate limiting
curl -X POST http://localhost:8000/api/transactions -H "Content-Type: application/json"

# Verify token expiration
php artisan tinker --execute="echo App\Models\PersonalAccessToken::first()->expires_at;"

# Build frontend (no changes needed for this sprint)
npm run build
```
