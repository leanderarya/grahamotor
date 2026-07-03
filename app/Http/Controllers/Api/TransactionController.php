<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\CashierSessionService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    public function __construct(
        private TransactionService $transactionService,
        private CashierSessionService $sessionService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cart' => 'required|array|min:1',
            'cart.*.id' => 'required|exists:products,id',
            'cart.*.qty' => 'required|integer|min:1',
            'payment_method' => 'required|in:cash,qris,bank',
            'amount_paid' => 'required|numeric|min:0',
            'change_amount' => 'required|numeric',
            'customer_type' => 'required|in:general,workshop',
            'draft_id' => 'nullable|exists:transactions,id',
        ]);

        try {
            $transaction = $this->transactionService->processPayment(auth()->user(), $validated);

            return response()->json([
                'message' => 'Transaksi berhasil.',
                'transaction' => [
                    'id' => $transaction->id,
                    'invoice_number' => $transaction->invoice_number,
                    'total_amount' => (float) $transaction->total_amount,
                    'payment_method' => $transaction->payment_method,
                    'status' => $transaction->status,
                ],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Transaction store failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Terjadi kesalahan sistem. Silakan coba lagi.',
            ], 500);
        }
    }

    public function show(Transaction $transaction): JsonResponse
    {
        // Ownership check: kasir can only view their own transactions
        if (auth()->id() !== $transaction->user_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $transaction->load(['transactionItems.product', 'user']);

        return response()->json([
            'transaction' => [
                'id' => $transaction->id,
                'invoice_number' => $transaction->invoice_number,
                'created_at' => $transaction->created_at?->toIso8601String(),
                'payment_method' => $transaction->payment_method,
                'customer_type' => $transaction->customer_type,
                'total_amount' => (float) $transaction->total_amount,
                'amount_paid' => (float) $transaction->amount_paid,
                'change_amount' => (float) $transaction->change_amount,
                'cashier_name' => $transaction->user?->name ?? '-',
                'items' => $transaction->transactionItems->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product_name' => $item->product?->display_name ?? 'Produk terhapus',
                        'quantity' => $item->quantity,
                        'price_at_time' => (float) $item->price_at_time,
                        'subtotal' => (float) ($item->quantity * $item->price_at_time),
                    ];
                }),
            ],
        ]);
    }

    public function recap(): JsonResponse
    {
        $user = auth()->user();
        $openSession = $this->sessionService->getOpenSession($user->id);
        $recapData = $this->transactionService->getRecap($user->id, $openSession);

        return response()->json([
            'session' => $openSession ? $this->sessionService->buildSessionPayload($openSession) : null,
            'summary' => $recapData['summary'],
            'transactions' => $recapData['transactions']->map(fn (Transaction $t) => [
                'id' => $t->id,
                'invoice_number' => $t->invoice_number,
                'created_at' => $t->created_at?->toIso8601String(),
                'payment_method' => $t->payment_method,
                'customer_type' => $t->customer_type,
                'total_amount' => (float) $t->total_amount,
                'items_count' => $t->transactionItems->sum('quantity'),
            ]),
        ]);
    }

    public function history(): JsonResponse
    {
        $transactions = $this->transactionService->getHistory(auth()->id());

        return response()->json([
            'transactions' => $transactions->items(),
            'current_page' => $transactions->currentPage(),
            'last_page' => $transactions->lastPage(),
            'total' => $transactions->total(),
        ]);
    }

    public function void(Request $request, Transaction $transaction): JsonResponse
    {
        // Ownership check
        if (auth()->id() !== $transaction->user_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->transactionService->voidTransaction($transaction, auth()->id(), $request->reason);

            return response()->json([
                'message' => 'Transaksi berhasil dibatalkan.',
                'transaction' => $transaction->fresh(),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
