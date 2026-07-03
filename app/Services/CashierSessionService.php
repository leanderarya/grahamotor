<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\Transaction;
use Carbon\Carbon;
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
        if ($this->getOpenSession($userId) !== null) {
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
    }

    /**
     * Close an open cashier session and return closing report data.
     */
    public function closeSession(int $userId, float $closingCashPhysical, ?string $notes = null): array
    {
        $session = $this->getOpenSession($userId);

        if ($session === null) {
            throw ValidationException::withMessages([
                'closing_cash_physical' => 'Tidak ada sesi kasir yang sedang berjalan.',
            ]);
        }

        // Safety: Recalculate totals from actual transactions before closing
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
        $expectedCash = (float) $session->opening_cash + (float) $session->cash_sales_total;
        $difference = $session->cash_difference;
        $status = 'open';

        if ($session->closed_at !== null) {
            $status = $difference == 0.0 ? 'balance' : ($difference < 0 ? 'minus' : 'over');
        }

        return [
            'id' => $session->id,
            'opening_cash' => (float) $session->opening_cash,
            'cash_sales_total' => (float) $session->cash_sales_total,
            'non_cash_sales_total' => (float) $session->non_cash_sales_total,
            'transactions_count' => $session->transactions_count,
            'expected_cash' => $session->closed_at ? (float) $session->expected_cash : $expectedCash,
            'closing_cash_physical' => $session->closing_cash_physical !== null ? (float) $session->closing_cash_physical : null,
            'cash_difference' => $difference !== null ? (float) $difference : null,
            'status' => $status,
            'opened_at' => Carbon::parse($session->opened_at)->toIso8601String(),
            'closed_at' => $session->closed_at ? Carbon::parse($session->closed_at)->toIso8601String() : null,
            'opening_notes' => $session->opening_notes,
            'closing_notes' => $session->closing_notes,
        ];
    }
}
