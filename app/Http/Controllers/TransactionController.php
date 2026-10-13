<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Transaction;
use App\Services\CashierSessionService;
use App\Services\ClosingReportService;
use App\Services\DraftService;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TransactionController extends Controller
{
    public function __construct(
        private CashierSessionService $sessionService,
        private TransactionService $transactionService,
        private DraftService $draftService,
    ) {}

    public function create()
    {
        $user = auth()->user();
        $openSession = $this->sessionService->getOpenSession($user->id);

        $categories = Product::where('stock', '>', 0)
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category')
            ->sort()
            ->values();

        $activeDraft = Transaction::draft()
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        if ($activeDraft) {
            $activeDraft->load('transactionItems.product');
        }

        return Inertia::render('Transactions/Create', [
            'products' => Product::with('vehicles')
                ->where('stock', '>', 0)
                ->select('id', 'sku', 'name', 'category', 'image_path', 'volume_liter', 'stock', 'sell_price', 'workshop_price')
                ->get(),
            'categories' => $categories,
            'cashierSession' => $openSession ? $this->sessionService->buildSessionPayload($openSession) : null,
            'activeDraft' => $activeDraft ? [
                'id' => $activeDraft->id,
                'invoice_number' => $activeDraft->invoice_number,
                'customer_type' => $activeDraft->customer_type,
                'total_amount' => (float) $activeDraft->total_amount,
                'transaction_items' => $activeDraft->transactionItems->map(fn ($item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'price_at_time' => (float) $item->price_at_time,
                    'product' => $item->product ? [
                        'id' => $item->product->id,
                        'sku' => $item->product->sku,
                        'name' => $item->product->name,
                        'category' => $item->product->category,
                        'image_path' => $item->product->image_path,
                        'image_url' => $item->product->image_url,
                        'volume_liter' => $item->product->volume_liter ? (float) $item->product->volume_liter : null,
                        'stock' => $item->product->stock,
                        'sell_price' => (float) $item->product->sell_price,
                        'workshop_price' => $item->product->workshop_price ? (float) $item->product->workshop_price : null,
                        'display_name' => $item->product->display_name,
                    ] : null,
                ]),
            ] : null,
        ]);
    }

    public function saveDraft(Request $request)
    {
        $validated = $request->validate([
            'cart' => 'required|array|min:1',
            'cart.*.id' => 'required|exists:products,id',
            'cart.*.qty' => 'required|integer|min:1',
            'customer_type' => 'required|in:general,workshop',
            'draft_id' => 'nullable|exists:transactions,id',
        ]);

        $openSession = $this->sessionService->getOpenSession(auth()->id());
        if ($openSession === null) {
            throw ValidationException::withMessages([
                'cart' => 'Buka kasir terlebih dahulu.',
            ]);
        }

        $draft = $this->draftService->save(auth()->user(), $validated);

        return redirect()->route('transactions.checkout', ['transaction' => $draft->id]);
    }

    public function checkout(Request $request, $transactionId = null)
    {
        $draftId = $transactionId ?? $request->query('draft');

        if (! $draftId) {
            return redirect()->route('transactions.create');
        }

        $draft = Transaction::with(['transactionItems.product', 'user'])
            ->where('id', $draftId)
            ->where('status', 'draft')
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $openSession = $this->sessionService->getOpenSession(auth()->id());

        return Inertia::render('Transactions/Checkout', [
            'draft' => [
                'id' => $draft->id,
                'invoice_number' => $draft->invoice_number,
                'customer_type' => $draft->customer_type,
                'total_amount' => (float) $draft->total_amount,
                'total_profit' => (float) $draft->total_profit,
                'items' => $draft->transactionItems->map(fn ($item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->display_name ?? $item->product->name,
                    'quantity' => $item->quantity,
                    'price_at_time' => (float) $item->price_at_time,
                    'subtotal' => (float) ($item->quantity * $item->price_at_time),
                ]),
            ],
            'cashierSession' => $openSession ? $this->sessionService->buildSessionPayload($openSession) : null,
        ]);
    }

    public function autoSaveDraft(Request $request)
    {
        $validated = $request->validate([
            'cart' => 'required|array|min:1',
            'cart.*.id' => 'required|exists:products,id',
            'cart.*.qty' => 'required|integer|min:1',
            'customer_type' => 'required|in:general,workshop',
            'draft_id' => 'nullable|exists:transactions,id',
        ]);

        $openSession = $this->sessionService->getOpenSession(auth()->id());
        if ($openSession === null) {
            return response()->json(['message' => 'Buka kasir terlebih dahulu.'], 422);
        }

        $draft = $this->draftService->autoSave(auth()->user(), $validated);

        return response()->json([
            'draft_id' => $draft->id,
            'message' => 'Draft tersimpan.',
        ]);
    }

    public function clearDraft(Request $request)
    {
        $this->draftService->clear(
            $request->filled('draft_id') ? $request->draft_id : null,
            auth()->id(),
        );

        return response()->json(['message' => 'Draft dihapus.']);
    }

    public function destroyDraft(Transaction $transaction)
    {
        if (! $this->draftService->destroy($transaction, auth()->id())) {
            abort(403);
        }

        return redirect()->route('transactions.create')->with('success', 'Draft transaksi dibatalkan.');
    }

    public function recap()
    {
        $user = auth()->user();
        $openSession = $this->sessionService->getOpenSession($user->id);
        $recapData = $this->transactionService->getRecap($user->id, $openSession);

        return Inertia::render('Transactions/Recap', [
            'cashierSession' => $openSession ? $this->sessionService->buildSessionPayload($openSession) : null,
            'summary' => $recapData['summary'],
            'transactions' => $recapData['transactions']->map(function (Transaction $transaction) {
                return [
                    'id' => $transaction->id,
                    'invoice_number' => $transaction->invoice_number,
                    'created_at' => $transaction->created_at?->toIso8601String(),
                    'payment_method' => $transaction->payment_method,
                    'customer_type' => $transaction->customer_type,
                    'total_amount' => (float) $transaction->total_amount,
                    'items_count' => $transaction->transactionItems->sum('quantity'),
                ];
            }),
            'topProducts' => $recapData['topProducts'],
        ]);
    }

    public function show(Transaction $transaction)
    {
        $transaction->load(['transactionItems.product', 'user']);

        return Inertia::render('Transactions/Show', [
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

    public function history()
    {
        $transactions = $this->transactionService->getHistory(auth()->id());

        return Inertia::render('Transactions/History', [
            'transactions' => $transactions,
        ]);
    }

    public function void(Request $request, Transaction $transaction)
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->transactionService->voidTransaction($transaction, auth()->id(), $request->reason);

        return back()->with('success', 'Transaksi berhasil dibatalkan.');
    }

    public function openSession(Request $request)
    {
        $validated = $request->validate([
            'opening_cash' => 'required|numeric|min:0',
            'opening_notes' => 'nullable|string|max:1000',
        ]);

        $this->sessionService->openSession(
            auth()->id(),
            $validated['opening_cash'],
            $validated['opening_notes'] ?? null,
        );

        return redirect()->route('transactions.create')->with('success', 'Kasir berhasil dibuka.');
    }

    public function closeSession(Request $request)
    {
        $validated = $request->validate([
            'closing_cash_physical' => 'required|numeric|min:0',
            'closing_notes' => 'nullable|string|max:1000',
        ]);

        $closingData = $this->sessionService->closeSession(
            auth()->id(),
            $validated['closing_cash_physical'],
            $validated['closing_notes'] ?? null,
        );

        // pos.ts posts this endpoint with Accept: application/json so it can read
        // closingData directly; a redirect would make fetch() parse HTML and throw.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Sesi kasir berhasil ditutup.',
                'closingData' => $closingData,
            ]);
        }

        return back()
            ->with('success', 'Sesi kasir berhasil ditutup.')
            ->with('closingData', $closingData);
    }

    public function store(Request $request)
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
            $this->transactionService->processPayment(auth()->user(), $validated);

            // Not back(): the draft just became 'paid', so returning to
            // /pos/checkout/{id} would 404 (checkout() only serves drafts).
            return redirect()->route('transactions.create')->with('success', 'Transaksi Berhasil Disimpan!');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Transaction store failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->back()->withErrors(['error' => 'Terjadi kesalahan sistem. Silakan coba lagi.']);
        }
    }
}
