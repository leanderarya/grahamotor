<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\CashierSession;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DraftService
{
    public function __construct(
        private PricingService $pricing,
        private InvoiceService $invoice,
        private CashierSessionService $sessionService,
    ) {}

    /**
     * Save a draft transaction (manual save, redirects to checkout).
     */
    public function save(Authenticatable $user, array $validated): Transaction
    {
        return DB::transaction(function () use ($validated, $user) {
            $openSession = $this->sessionService->lockOpenSession($user->id);
            $this->assertSessionCanSaveTo($openSession, $validated['cashier_session_id'] ?? null);

            $draft = $this->findOrCreateDraft($validated, $openSession->id, $user->id);
            $this->syncDraftItems($draft, $validated['cart'], $validated['customer_type']);

            return $draft;
        }, 3);
    }

    /**
     * Auto-save a draft transaction (debounced, returns JSON).
     */
    public function autoSave(Authenticatable $user, array $validated): Transaction
    {
        return DB::transaction(function () use ($validated, $user) {
            $openSession = $this->sessionService->lockOpenSession($user->id);
            $this->assertSessionCanSaveTo($openSession, $validated['cashier_session_id'] ?? null);

            $draft = $this->findOrCreateDraft($validated, $openSession->id, $user->id, allowMissing: true);
            $this->syncDraftItems($draft, $validated['cart'], $validated['customer_type']);

            return $draft;
        }, 3);
    }

    /**
     * Clear draft(s) — optionally filtered by draft_id.
     */
    public function clear(?int $draftId, int $userId, ?int $expectedSessionId = null): void
    {
        if ($draftId === null) {
            return;
        }

        DB::transaction(function () use ($draftId, $userId, $expectedSessionId): void {
            $session = $this->sessionService->lockOpenSession($userId);

            if ($session === null) {
                return;
            }

            $this->assertSessionCanSaveTo($session, $expectedSessionId);

            $query = Transaction::query()
                ->where('status', 'draft')
                ->where('user_id', $userId)
                ->where('cashier_session_id', $session->id)
                ->lockForUpdate();

            $query->whereKey($draftId);

            foreach ($query->get() as $draft) {
                $draft->transactionItems()->delete();
                $draft->delete();
            }
        }, 3);
    }

    /**
     * Destroy a specific draft.
     */
    public function destroy(Transaction $transaction, int $userId): bool
    {
        return DB::transaction(function () use ($transaction, $userId): bool {
            $session = $this->sessionService->lockOpenSession($userId);
            if ($session === null) {
                return false;
            }

            $this->sessionService->assertNotOverdue($session);
            $draft = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->first();

            if (
                ! $draft
                || $draft->status !== 'draft'
                || $draft->user_id !== $userId
                || (int) $draft->cashier_session_id !== $session->id
            ) {
                return false;
            }

            $draft->transactionItems()->delete();
            $draft->delete();

            return true;
        }, 3);
    }

    /**
     * Find an existing draft by ID or create a new one.
     */
    private function findOrCreateDraft(array $validated, int $sessionId, int $userId, bool $allowMissing = false): Transaction
    {
        if (! empty($validated['draft_id'])) {
            $draft = Transaction::where('id', $validated['draft_id'])
                ->where('status', 'draft')
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($draft) {
                if ((int) $draft->cashier_session_id !== $sessionId) {
                    throw ValidationException::withMessages([
                        'draft_id' => 'Draft berasal dari sesi kasir lain dan tidak dapat dialihkan.',
                    ]);
                }

                $draft->transactionItems()->delete();

                return $draft;
            }

            if (! $allowMissing) {
                abort(404);
            }
        }

        return Transaction::create([
            'user_id' => $userId,
            'cashier_session_id' => $sessionId,
            'invoice_number' => $this->invoice->generate(),
            'customer_type' => $validated['customer_type'],
            'total_amount' => 0,
            'total_profit' => 0,
            'payment_method' => 'cash',
            'status' => 'draft',
        ]);
    }

    /**
     * Sync draft items from cart data — recreate all items and recalculate totals.
     * Optimized: bulk-loads products in 1 query, batch-inserts items.
     *
     * @return int[] Array of product IDs that were not found
     */
    private function syncDraftItems(Transaction $draft, array $cart, string $customerType): array
    {
        $grandTotal = 0;
        $totalProfit = 0;
        $missingProductIds = [];

        // Bulk-load all products in 1 query instead of N individual queries
        $productIds = array_column($cart, 'id');
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        $itemsToInsert = [];

        foreach ($cart as $item) {
            $product = $products->get($item['id']);
            if (! $product) {
                $missingProductIds[] = $item['id'];
                continue;
            }

            $finalPrice = $this->pricing->getEffectivePrice($product, $customerType);
            $subtotal = $finalPrice * $item['qty'];
            $profit = ($finalPrice - $product->cost_price) * $item['qty'];

            $itemsToInsert[] = [
                'transaction_id' => $draft->id,
                'product_id' => $product->id,
                'quantity' => $item['qty'],
                'price_at_time' => $finalPrice,
                'cost_at_time' => $product->cost_price,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $grandTotal += $subtotal;
            $totalProfit += $profit;
        }

        // Batch insert instead of N individual create() calls
        if (! empty($itemsToInsert)) {
            TransactionItem::insert($itemsToInsert);
        }

        $draft->update([
            'total_amount' => $grandTotal,
            'total_profit' => $totalProfit,
            'customer_type' => $customerType,
        ]);

        return $missingProductIds;
    }

    private function assertSessionCanSaveTo(?CashierSession $session, ?int $expectedSessionId = null): void
    {
        if ($session === null) {
            throw ValidationException::withMessages([
                'cart' => 'Buka kasir terlebih dahulu.',
            ]);
        }

        $this->sessionService->assertNotOverdue($session);

        if ($expectedSessionId !== null && $expectedSessionId !== $session->id) {
            throw ValidationException::withMessages([
                'session' => 'Sesi kasir sudah berubah. Draft tidak dialihkan ke sesi lain.',
            ]);
        }
    }
}
