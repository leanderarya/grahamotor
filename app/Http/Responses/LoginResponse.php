<?php

namespace App\Http\Responses;

use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract; // <--- JANGAN LUPA IMPORT INI

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        $user = auth()->user();

        // Cek Role Database
        if ($user->isOwner() || $user->isAdmin()) {
            return Inertia::location(url('/admin'));
        }

        return redirect()->intended(route('transactions.create'));
    }
}
