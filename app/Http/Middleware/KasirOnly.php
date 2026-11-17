<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class KasirOnly
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if (! $user) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect()->route('pin.login');
        }

        if (! $user->isCashier()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Akun ini tidak memiliki akses ke endpoint kasir.'], 403);
            }

            return ($user->isOwner() || $user->isAdmin())
                ? redirect('/admin')
                : redirect()->route('pin.login');
        }

        return $next($request);
    }
}
