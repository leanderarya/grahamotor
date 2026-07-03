<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\Transaction;
use App\Models\TransactionItem;

class ClosingReportService
{
    /**
     * Build closing report data for a cashier session.
     * Returns the array consumed by both web and API controllers.
     */
    public function buildReport(CashierSession $session): array
    {
        $expectedCash = (float) $session->opening_cash + (float) $session->cash_sales_total;
        $closingCash = (float) $session->closing_cash_physical;
        $difference = $closingCash - $expectedCash;

        // Use SQL aggregate instead of loading all transactions
        $transactionStats = Transaction::where('cashier_session_id', $session->id)
            ->where('status', 'paid')
            ->selectRaw('
                COUNT(*) as total_transactions,
                COALESCE(SUM(total_amount), 0) as total_revenue,
                COALESCE(SUM(total_profit), 0) as total_profit,
                COALESCE(SUM(CASE WHEN payment_method = ? THEN total_amount ELSE 0 END), 0) as cash_total,
                COALESCE(SUM(CASE WHEN payment_method != ? THEN total_amount ELSE 0 END), 0) as non_cash_total
            ', ['cash', 'cash'])
            ->first();

        $paymentBreakdown = Transaction::where('cashier_session_id', $session->id)
            ->where('status', 'paid')
            ->selectRaw('payment_method, COUNT(*) as count, SUM(total_amount) as total')
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');

        $topProducts = TransactionItem::whereHas('transaction', fn ($q) => $q->where('cashier_session_id', $session->id)->where('status', 'paid'))
            ->join('products', 'products.id', '=', 'transaction_items.product_id')
            ->selectRaw('products.name as product_name, products.volume_liter, SUM(transaction_items.quantity) as qty, SUM(transaction_items.quantity * transaction_items.price_at_time) as revenue')
            ->groupBy('products.id', 'products.name', 'products.volume_liter')
            ->orderByDesc('qty')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                $volume = $item->volume_liter ? rtrim(rtrim(number_format((float) $item->volume_liter, 2, '.', ''), '0'), '.') : null;
                $name = $volume ? "{$item->product_name} ({$volume}L)" : $item->product_name;

                return [
                    'name' => $name,
                    'quantity' => (int) $item->qty,
                    'revenue' => (float) $item->revenue,
                ];
            });

        return [
            'date' => now()->toLocaleDateString('id-ID'),
            'cashierName' => $session->user?->name ?? '-',
            'openedAt' => $session->opened_at->format('H:i'),
            'closedAt' => now()->format('H:i'),
            'duration' => $session->opened_at->diffForHumans(now(), true),
            'totalTransactions' => (int) $transactionStats->total_transactions,
            'totalRevenue' => (float) $transactionStats->total_revenue,
            'totalProfit' => (float) $transactionStats->total_profit,
            'cashTotal' => (float) $transactionStats->cash_total,
            'nonCashTotal' => (float) $transactionStats->non_cash_total,
            'openingCash' => (float) $session->opening_cash,
            'cashSales' => (float) $session->cash_sales_total,
            'expectedCash' => $expectedCash,
            'physicalCash' => $closingCash,
            'difference' => $difference,
            'settlementStatus' => $difference === 0 ? 'balance' : ($difference < 0 ? 'minus' : 'over'),
            'topProducts' => $topProducts->toArray(),
            'paymentBreakdown' => $paymentBreakdown->toArray(),
        ];
    }
}
