<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function __construct(
        private PricingService $pricing,
        private InvoiceService $invoice,
        private CashierSessionService $sessionService,
    ) {}

    /**
     * Process a payment: either finalize a draft or create a new paid transaction.
     * Handles stock decrement and session totals update.
     *
     * @return Transaction The paid transaction
     */
    public function processPayment(Authenticatable $user, array $validated): Transaction
    {
        /** @var Transaction $transaction */
        $transaction = DB::transaction(function () use ($validated, $user) {
            $openSession = $this->sessionService->lockOpenSession($user->id);

            if ($openSession === null) {
                throw ValidationException::withMessages([
                    'cart' => 'Buka kasir terlebih dahulu sebelum memproses transaksi.',
                ]);
            }

            if (filter_var($validated['cashier_session_id'] ?? null, FILTER_VALIDATE_INT) !== $openSession->id) {
                throw ValidationException::withMessages([
                    'session' => 'Sesi transaksi tidak valid atau sudah berubah. Transaksi tidak dialihkan ke sesi lain.',
                ]);
            }

            $this->sessionService->assertNotOverdue($openSession);

            if (! empty($validated['draft_id'])) {
                $transaction = Transaction::where('id', $validated['draft_id'])
                    ->where('status', 'draft')
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $transaction->cashier_session_id !== $openSession->id) {
                    throw ValidationException::withMessages([
                        'draft_id' => 'Draft berasal dari sesi kasir lain dan tidak dapat dialihkan.',
                    ]);
                }

                $transaction->transactionItems()->delete();

                $transaction->update([
                    'payment_method' => $validated['payment_method'],
                    'customer_type' => $validated['customer_type'],
                    'amount_paid' => $validated['amount_paid'],
                    'change_amount' => $validated['change_amount'],
                    'status' => 'paid',
                ]);
            } else {
                $transaction = Transaction::create([
                    'user_id' => $user->id,
                    'cashier_session_id' => $openSession->id,
                    'invoice_number' => $this->invoice->generate(),
                    'payment_method' => $validated['payment_method'],
                    'customer_type' => $validated['customer_type'],
                    'amount_paid' => $validated['amount_paid'],
                    'change_amount' => $validated['change_amount'],
                    'total_amount' => 0,
                    'total_profit' => 0,
                    'status' => 'paid',
                ]);
            }

            $grandTotal = 0;
            $totalProfit = 0;

            foreach ($validated['cart'] as $item) {
                $product = Product::lockForUpdate()->find($item['id']);

                if ($product->stock < $item['qty']) {
                    throw ValidationException::withMessages([
                        'cart' => "Stok barang '{$product->name}' tidak mencukupi. Sisa: {$product->stock}",
                    ]);
                }

                $finalPrice = $this->pricing->getEffectivePrice($product, $validated['customer_type']);
                $subtotal = $finalPrice * $item['qty'];
                $profit = ($finalPrice - $product->cost_price) * $item['qty'];

                TransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'product_id' => $product->id,
                    'quantity' => $item['qty'],
                    'price_at_time' => $finalPrice,
                    'cost_at_time' => $product->cost_price,
                ]);

                $product->decrement('stock', $item['qty']);

                $grandTotal += $subtotal;
                $totalProfit += $profit;
            }

            $transaction->update([
                'total_amount' => $grandTotal,
                'total_profit' => $totalProfit,
            ]);

            // Update session totals
            $cashSales = $validated['payment_method'] === 'cash' ? $grandTotal : 0;
            $nonCashSales = $validated['payment_method'] === 'cash' ? 0 : $grandTotal;

            $openSession->increment('transactions_count');
            $openSession->increment('cash_sales_total', $cashSales);
            $openSession->increment('non_cash_sales_total', $nonCashSales);

            return $transaction;
        }, 3);

        return $transaction;
    }

    /**
     * Void a paid transaction: restore stock, mark as voided, recalculate session totals.
     * Only the kasir who created the transaction can void it.
     */
    public function voidTransaction(Transaction $transaction, int $userId, string $reason): void
    {
        DB::transaction(function () use ($transaction, $userId, $reason) {
            \App\Models\User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            $transaction = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($transaction->status === 'voided') {
                throw ValidationException::withMessages([
                    'transaction' => 'Transaksi sudah dibatalkan.',
                ]);
            }

            if ($transaction->user_id !== $userId) {
                throw ValidationException::withMessages([
                    'transaction' => 'Anda hanya bisa membatalkan transaksi milik sendiri.',
                ]);
            }

            $transaction->load('transactionItems.product');

            // Restore stock
            foreach ($transaction->transactionItems as $item) {
                Product::query()->whereKey($item->product_id)->lockForUpdate()->firstOrFail()
                    ->increment('stock', $item->quantity);
            }

            // Mark as voided
            $transaction->update([
                'status' => 'voided',
                'void_reason' => $reason,
                'voided_by' => $userId,
                'voided_at' => now(),
            ]);

            // Recalculate session totals
            if ($transaction->cashier_session_id) {
                $session = CashierSession::query()
                    ->whereKey($transaction->cashier_session_id)
                    ->lockForUpdate()
                    ->first();
                if ($session && $session->closed_at === null) {
                    $session->recalculateTotals();
                }
            }
        }, 3);
    }

    /**
     * Get recap summary for a user's transactions within current session or today.
     */
    public function getRecap(int $userId, ?CashierSession $openSession): array
    {
        $today = now()->startOfDay();

        // Single aggregate query for all summary metrics — no N queries
        $summaryQuery = Transaction::query()
            ->where('user_id', $userId)
            ->where('status', 'paid')
            ->when($openSession !== null, fn ($q) => $q->where('cashier_session_id', $openSession->id))
            ->when($openSession === null, fn ($q) => $q->where('created_at', '>=', $today));

        $agg = (clone $summaryQuery)->selectRaw(
            'COUNT(*) as total_transactions,
            COALESCE(SUM(CASE WHEN payment_method = \'cash\' THEN total_amount ELSE 0 END), 0) as cash_total,
            COALESCE(SUM(CASE WHEN payment_method != \'cash\' THEN total_amount ELSE 0 END), 0) as non_cash_total,
            COALESCE(SUM(total_amount), 0) as revenue_total,
            COALESCE(SUM(total_profit), 0) as profit_total'
        )->first();

        $summary = [
            'total_transactions' => (int) $agg->total_transactions,
            'cash_total' => (float) $agg->cash_total,
            'non_cash_total' => (float) $agg->non_cash_total,
            'revenue_total' => (float) $agg->revenue_total,
            'profit_total' => (float) $agg->profit_total,
        ];

        // Transactions list — limited to 20 most recent with eager loading
        $transactions = Transaction::query()
            ->with(['transactionItems.product'])
            ->where('user_id', $userId)
            ->where('status', 'paid')
            ->tap(function ($q) use ($openSession, $today) {
                if ($openSession !== null) {
                    $q->where('cashier_session_id', $openSession->id);
                } else {
                    $q->where('created_at', '>=', $today);
                }
            })
            ->latest()
            ->limit(20)
            ->get();

        $topProducts = $transactions
            ->flatMap(fn (Transaction $transaction) => $transaction->transactionItems)
            ->groupBy('product_id')
            ->map(function ($items) {
                $firstItem = $items->first();
                $product = $firstItem?->product;

                return [
                    'product_name' => $product?->display_name ?? 'Produk terhapus',
                    'image_url' => $product?->image_url,
                    'quantity' => (int) $items->sum('quantity'),
                    'revenue' => (float) $items->sum(fn ($item) => $item->quantity * $item->price_at_time),
                ];
            })
            ->sortByDesc('quantity')
            ->take(8)
            ->values();

        return [
            'summary' => $summary,
            'transactions' => $transactions,
            'topProducts' => $topProducts,
        ];
    }

    /**
     * Get paginated transaction history for a user.
     */
    public function getHistory(int $userId, int $perPage = 20)
    {
        return Transaction::with('user')
            ->where('user_id', $userId)
            ->whereIn('status', ['paid', 'voided'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
