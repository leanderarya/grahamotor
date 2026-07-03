<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PinSecurityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class PinLoginController extends Controller
{
    public function show()
    {
        return Inertia::render('Auth/PinLogin');
    }

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

        if (! $user || ! Hash::check($request->pin, $user->pin)) {
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
}
