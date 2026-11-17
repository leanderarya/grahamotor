<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\DraftService;
use App\Services\CashierSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DraftController extends Controller
{
    public function __construct(
        private DraftService $draftService,
        private CashierSessionService $sessionService,
    ) {}

    public function save(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cart' => 'required|array|min:1',
            'cart.*.id' => 'required|exists:products,id',
            'cart.*.qty' => 'required|integer|min:1',
            'customer_type' => 'required|in:general,workshop',
            'draft_id' => 'nullable|exists:transactions,id',
            'cashier_session_id' => 'nullable|integer|min:1',
        ]);

        $openSession = $this->sessionService->getOpenSession(auth()->id());
        if ($openSession === null) {
            throw ValidationException::withMessages([
                'cart' => 'Buka kasir terlebih dahulu.',
            ]);
        }

        $draft = $this->draftService->save(auth()->user(), $validated);

        return response()->json([
            'draft_id' => $draft->id,
            'invoice_number' => $draft->invoice_number,
            'total_amount' => (float) $draft->total_amount,
            'message' => 'Draft tersimpan.',
        ]);
    }

    public function autoSave(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cart' => 'required|array|min:1',
            'cart.*.id' => 'required|exists:products,id',
            'cart.*.qty' => 'required|integer|min:1',
            'customer_type' => 'required|in:general,workshop',
            'draft_id' => 'nullable|exists:transactions,id',
            'cashier_session_id' => 'nullable|integer|min:1',
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

    public function clear(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'draft_id' => 'nullable|integer|min:1',
            'cashier_session_id' => 'nullable|integer|min:1',
        ]);

        $this->draftService->clear(
            isset($validated['draft_id']) ? (int) $validated['draft_id'] : null,
            auth()->id(),
            isset($validated['cashier_session_id']) ? (int) $validated['cashier_session_id'] : null,
        );

        return response()->json(['message' => 'Draft dihapus.']);
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        if (! $this->draftService->destroy($transaction, auth()->id())) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json(['message' => 'Draft dibatalkan.']);
    }
}
