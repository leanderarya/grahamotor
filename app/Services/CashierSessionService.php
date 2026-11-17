<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\ShiftOverrideAudit;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierSessionService
{
    public function __construct(
        private ClosingReportService $closingReport,
    ) {}

    /**
     * Get the currently open cashier session for the given user.
     */
    public function getOpenSession(int $userId): ?CashierSession
    {
        return CashierSession::query()
            ->where('user_id', $userId)
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();
    }

    /**
     * Open a new cashier session.
     */
    public function openSession(int $userId, float $openingCash, ?string $notes = null): CashierSession
    {
        return DB::transaction(function () use ($userId, $openingCash, $notes): CashierSession {
            $this->lockCashier($userId);

            if ($this->findOpenSessionForUpdate($userId) !== null) {
                throw ValidationException::withMessages([
                    'opening_cash' => 'Masih ada sesi kasir yang belum ditutup.',
                ]);
            }

            return CashierSession::create([
                'user_id' => $userId,
                'opening_cash' => $openingCash,
                'opened_at' => now(),
                'opening_notes' => $notes,
            ]);
        }, 3);
    }

    /**
     * Close an open cashier session and return closing report data.
     */
    public function closeSession(int $userId, float $closingCashPhysical, ?string $notes = null): array
    {
        return DB::transaction(function () use ($userId, $closingCashPhysical, $notes): array {
            $this->lockCashier($userId);
            $session = $this->findOpenSessionForUpdate($userId);

            if ($session === null) {
                throw ValidationException::withMessages([
                    'closing_cash_physical' => 'Tidak ada sesi kasir yang sedang berjalan.',
                ]);
            }

            $this->assertNotOverdue($session);

            return $this->settleSession($session, $closingCashPhysical, $notes);
        }, 3);
    }

    /**
     * Close an overdue cashier session from the admin panel with an audit record.
     */
    public function closeOverdueSession(
        int $sessionId,
        int $actorId,
        float $closingCashPhysical,
        string $reason,
    ): array {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Alasan override minimal 5 karakter.',
            ]);
        }

        return DB::transaction(function () use ($sessionId, $actorId, $closingCashPhysical, $reason): array {
            $sessionOwnerId = CashierSession::query()->whereKey($sessionId)->value('user_id');
            if ($sessionOwnerId === null) {
                throw ValidationException::withMessages([
                    'session' => 'Sesi kasir tidak ditemukan.',
                ]);
            }

            $this->lockCashier((int) $sessionOwnerId);
            $session = CashierSession::query()->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $actor = User::query()->whereKey($actorId)->firstOrFail();

            if (! $actor->is_active || (! $actor->isOwner() && ! $actor->isAdmin())) {
                abort(403);
            }

            if ($session->closed_at !== null || ! $this->isOverdue($session)) {
                throw ValidationException::withMessages([
                    'session' => 'Override hanya dapat digunakan untuk sesi terbuka yang sudah melewati batas 00.00 WIB.',
                ]);
            }

            $report = $this->settleSession($session, $closingCashPhysical, $reason);

            ShiftOverrideAudit::create([
                'cashier_session_id' => $session->id,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'closing_cash_physical' => $session->closing_cash_physical,
                'expected_cash' => $session->expected_cash,
                'cash_difference' => $session->cash_difference,
                'created_at' => now(),
            ]);

            return $report;
        }, 3);
    }

    /**
     * Lock the cashier row and their open session before a write operation.
     * All session, draft, and payment writes use this lock order.
     */
    public function lockOpenSession(int $userId): ?CashierSession
    {
        $this->lockCashier($userId);

        return $this->findOpenSessionForUpdate($userId);
    }

    public function assertNotOverdue(CashierSession $session): void
    {
        if ($this->isOverdue($session)) {
            throw ValidationException::withMessages([
                'session' => 'Shift melewati batas 00.00 WIB. Minta Admin atau Owner menutup shift sebelum melanjutkan.',
            ]);
        }
    }

    public function isOverdue(CashierSession $session, ?CarbonInterface $at = null): bool
    {
        if ($session->closed_at !== null) {
            return false;
        }

        $nowInJakarta = ($at ? Carbon::instance($at) : Carbon::now('Asia/Jakarta'))
            ->copy()
            ->setTimezone('Asia/Jakarta');
        $openedAt = $session->opened_at instanceof CarbonInterface
            ? Carbon::instance($session->opened_at)
            : Carbon::parse($session->opened_at, config('app.timezone'));

        return $openedAt->copy()->setTimezone('Asia/Jakarta')->lt($nowInJakarta->startOfDay());
    }

    /**
     * Recalculate session totals from actual paid transactions.
     * Safety net against incremental update drift.
     */
    private function recalculateSessionTotals(CashierSession $session): void
    {
        $paidTransactions = Transaction::where('cashier_session_id', $session->id)
            ->where('status', 'paid')
            ->get();

        $session->update([
            'transactions_count' => $paidTransactions->count(),
            'cash_sales_total' => (float) $paidTransactions->where('payment_method', 'cash')->sum('total_amount'),
            'non_cash_sales_total' => (float) $paidTransactions->where('payment_method', '!=', 'cash')->sum('total_amount'),
        ]);
    }

    /**
     * Build the session payload array for frontend consumption.
     * Used by both web (Inertia) and API (JSON) controllers.
     */
    public function buildSessionPayload(CashierSession $session): array
    {
        $openedAt = Carbon::parse($session->opened_at)->setTimezone('Asia/Jakarta');
        $cutoffAt = $openedAt->copy()->startOfDay()->addDay();
        $expectedCash = (float) $session->opening_cash + (float) $session->cash_sales_total;
        $difference = $session->cash_difference;
        $status = 'open';

        if ($session->closed_at !== null) {
            $status = $difference == 0.0 ? 'balance' : ($difference < 0 ? 'minus' : 'over');
        }

        return [
            'id' => $session->id,
            'is_overdue' => $session->closed_at === null && $this->isOverdue($session),
            'overdue_at' => $cutoffAt->toIso8601String(),
            'opening_cash' => (float) $session->opening_cash,
            'cash_sales_total' => (float) $session->cash_sales_total,
            'non_cash_sales_total' => (float) $session->non_cash_sales_total,
            'transactions_count' => $session->transactions_count,
            'expected_cash' => $session->closed_at ? (float) $session->expected_cash : $expectedCash,
            'closing_cash_physical' => $session->closing_cash_physical !== null ? (float) $session->closing_cash_physical : null,
            'cash_difference' => $difference !== null ? (float) $difference : null,
            'status' => $status,
            'opened_at' => $openedAt->toIso8601String(),
            'closed_at' => $session->closed_at ? Carbon::parse($session->closed_at)->toIso8601String() : null,
            'opening_notes' => $session->opening_notes,
            'closing_notes' => $session->closing_notes,
        ];
    }

    private function lockCashier(int $userId): User
    {
        $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

        if (! $user->isCashier()) {
            throw ValidationException::withMessages([
                'session' => 'Hanya akun kasir yang dapat mengelola sesi POS.',
            ]);
        }

        return $user;
    }

    private function findOpenSessionForUpdate(int $userId): ?CashierSession
    {
        $sessions = CashierSession::query()
            ->where('user_id', $userId)
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->lockForUpdate()
            ->get();

        if ($sessions->count() > 1) {
            throw ValidationException::withMessages([
                'session' => 'Ditemukan lebih dari satu shift terbuka. Minta Owner memeriksa data sebelum melanjutkan.',
            ]);
        }

        return $sessions->first();
    }

    private function settleSession(CashierSession $session, float $closingCashPhysical, ?string $notes): array
    {
        $this->recalculateSessionTotals($session);

        $expectedCash = (float) $session->opening_cash + (float) $session->cash_sales_total;
        $difference = $closingCashPhysical - $expectedCash;

        $session->update([
            'closing_cash_physical' => $closingCashPhysical,
            'expected_cash' => $expectedCash,
            'cash_difference' => $difference,
            'closing_notes' => $notes,
            'closed_at' => now(),
        ]);

        return $this->closingReport->buildReport($session);
    }
}
