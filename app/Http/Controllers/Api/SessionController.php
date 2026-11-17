<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CashierSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function __construct(
        private CashierSessionService $sessionService,
    ) {}

    public function status(): JsonResponse
    {
        $session = $this->sessionService->getOpenSession(auth()->id());

        return response()->json([
            'session' => $session ? $this->sessionService->buildSessionPayload($session) : null,
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'opening_cash' => 'required|numeric|min:0',
            'opening_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $session = $this->sessionService->openSession(
                auth()->id(),
                $validated['opening_cash'],
                $validated['opening_notes'] ?? null,
            );

            return response()->json([
                'message' => 'Kasir berhasil dibuka.',
                'session' => $this->sessionService->buildSessionPayload($session),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        }
    }

    public function close(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'closing_cash_physical' => 'required|numeric|min:0',
            'closing_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $closingData = $this->sessionService->closeSession(
                auth()->id(),
                $validated['closing_cash_physical'],
                $validated['closing_notes'] ?? null,
            );

            return response()->json([
                'message' => 'Sesi kasir berhasil ditutup.',
                'closingData' => $closingData,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        }
    }
}
