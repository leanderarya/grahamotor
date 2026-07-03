<?php

namespace App\Http\Controllers;

use App\Models\User;
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

        // Find kasir user — PIN comparison is now done via Hash::check
        $user = User::where('role', 'kasir')
            ->whereNotNull('pin')
            ->first();

        if (! $user || ! Hash::check($request->pin, $user->pin)) {
            return back()->withErrors([
                'pin' => 'PIN salah. Silakan coba lagi.',
            ]);
        }

        Auth::login($user);

        return redirect()->route('transactions.create');
    }
}
