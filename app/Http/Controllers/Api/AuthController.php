<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PinSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'pin' => ['required', 'digits:4'],
        ]);

        $ip = $request->ip();
        $pinSecurity = app(PinSecurityService::class);

        // Check if IP is locked out
        if ($pinSecurity->isLockedOut($ip)) {
            $remaining = $pinSecurity->getRemainingLockoutTime($ip);
            return response()->json([
                'message' => "Terlalu banyak percobaan. Coba lagi dalam {$remaining} detik.",
            ], 429);
        }

        $user = User::where('role', 'kasir')
            ->whereNotNull('pin')
            ->first();

        if (! $user || ! Hash::check($request->pin, $user->pin)) {
            // Record failed attempt
            $pinSecurity->recordAttempt($request->pin, $ip, false);

            return response()->json([
                'message' => 'PIN salah.',
            ], 401);
        }

        // Record successful attempt
        $pinSecurity->recordAttempt($request->pin, $ip, true);

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

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Berhasil logout.',
        ]);
    }
}
