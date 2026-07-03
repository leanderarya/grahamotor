<?php

namespace App\Services;

use App\Models\PinAttempt;

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

        if (! $lastFailure) {
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
