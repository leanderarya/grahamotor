# Sprint 2: Resilience & Error Handling

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve application resilience with React Error Boundaries, better error visibility for users, performance optimizations, and data integrity safeguards.

**Architecture:** Add error boundary components to catch render failures, implement proper error reporting for auto-save failures, optimize memory usage in closing reports, and add safety checks for session totals.

**Tech Stack:** Laravel 12, React 19, Inertia.js, MySQL

## Global Constraints

- Error boundaries must show recovery UI, not blank screens
- Auto-save failures must be visible to the cashier (subtle toast)
- Closing report must not load all transactions into memory
- Session totals must be recalculated on close for data integrity

---

## Task 1: React Error Boundary

**Files:**
- Create: `resources/js/Components/ErrorBoundary.tsx`
- Modify: `resources/js/Pages/Transactions/Create.tsx`
- Modify: `resources/js/app.tsx`

**Interfaces:**
- Produces: `ErrorBoundary` component that catches render errors
- Produces: Recovery UI with "Reload" button

### Step 1: Create ErrorBoundary component

```tsx
import React, { Component, ErrorInfo, ReactNode } from 'react';
import { AlertTriangle, RefreshCw } from 'lucide-react';

interface Props {
    children: ReactNode;
    fallback?: ReactNode;
}

interface State {
    hasError: boolean;
    error: Error | null;
}

export class ErrorBoundary extends Component<Props, State> {
    public state: State = {
        hasError: false,
        error: null,
    };

    public static getDerivedStateFromError(error: Error): State {
        return { hasError: true, error };
    }

    public componentDidCatch(error: Error, errorInfo: ErrorInfo) {
        console.error('Uncaught error:', error, errorInfo);

        // In production, you might want to send this to an error tracking service
        // For now, we'll just log it
    }

    private handleReload = () => {
        window.location.reload();
    };

    public render() {
        if (this.state.hasError) {
            if (this.props.fallback) {
                return this.props.fallback;
            }

            return (
                <div className="flex h-screen flex-col items-center justify-center bg-white p-8">
                    <div className="max-w-md text-center">
                        <AlertTriangle className="mx-auto h-16 w-16 text-amber-500" />
                        <h1 className="mt-4 text-xl font-bold text-slate-950">
                            Terjadi Kesalahan
                        </h1>
                        <p className="mt-2 text-sm text-slate-600">
                            Aplikasi mengalami error yang tidak terduga.
                            Silakan muat ulang halaman untuk melanjutkan.
                        </p>
                        <button
                            onClick={this.handleReload}
                            className="mt-6 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
                        >
                            <RefreshCw className="h-4 w-4" />
                            Muat Ulang
                        </button>
                        <p className="mt-4 text-xs text-slate-400">
                            Error: {this.state.error?.message || 'Unknown error'}
                        </p>
                    </div>
                </div>
            );
        }

        return this.props.children;
    }
}
```

### Step 2: Wrap POS layout with ErrorBoundary

In `resources/js/Pages/Transactions/Create.tsx`, wrap the return:

```tsx
import { ErrorBoundary } from '@/Components/ErrorBoundary';

export default function TabletPOS({ products, cashierSession, activeDraft }) {
    // ... existing code

    return (
        <ErrorBoundary>
            <div className="flex h-screen flex-col bg-white">
                {/* ... existing JSX */}
            </div>
        </ErrorBoundary>
    );
}
```

### Step 3: Wrap app entry point

In `resources/js/app.tsx`, wrap the root component:

```tsx
import { ErrorBoundary } from '@/Components/ErrorBoundary';

// ... existing imports

const app = createInertiaApp({
    resolve: (name) => import(`./Pages/${name}`),
    setup({ el, App, props }) {
        createRoot(el).render(
            <ErrorBoundary>
                <App {...props} />
            </ErrorBoundary>
        );
    },
});
```

### Step 4: Commit

```bash
git add resources/js/Components/ErrorBoundary.tsx resources/js/Pages/Transactions/Create.tsx resources/js/app.tsx
git commit -m "feat(ui): add React Error Boundary with recovery UI"
```

---

## Task 2: Auto-Save Error Visibility

**Files:**
- Modify: `resources/js/Pages/Transactions/Create.tsx`

**Interfaces:**
- Produces: Subtle toast notification on repeated auto-save failures
- Consumes: `notifyError` from `@/Components/app-notifications`

### Step 1: Add auto-save error tracking state

In `resources/js/Pages/Transactions/Create.tsx`, add state:

```tsx
const [autoSaveFailedCount, setAutoSaveFailedCount] = useState(0);
```

### Step 2: Update auto-save error handling

```tsx
// Auto-save draft setiap keranjang berubah (debounce 800ms)
useEffect(() => {
    if (skipAutoSave.current) return;
    if (!sessionState) return;

    if (saveTimerRef.current) clearTimeout(saveTimerRef.current);

    saveTimerRef.current = setTimeout(async () => {
        if (data.cart.length === 0) {
            try {
                await posService.clearDraft(draftId);
                setDraftId(null);
                setAutoSaveFailedCount(0); // Reset on success
            } catch (error) {
                console.error('Clear draft failed:', error);
            }
            return;
        }

        // Ada item → auto-save
        try {
            const result = await posService.autoSaveDraft(
                data.cart.map(item => ({ id: item.id, qty: item.qty })),
                customerType,
                draftId,
            );
            if (result?.draft_id) setDraftId(result.draft_id);
            setAutoSaveFailedCount(0); // Reset on success
        } catch (error) {
            console.error('Auto-save failed:', error);
            setAutoSaveFailedCount(prev => prev + 1);

            // Show toast after 3 consecutive failures
            if (autoSaveFailedCount >= 2) {
                notifyError('Auto-save gagal. Pastikan koneksi internet stabil.');
            }
        }
    }, 800);

    return () => {
        if (saveTimerRef.current) clearTimeout(saveTimerRef.current);
    };
}, [data.cart, customerType, sessionState, draftId]);
```

### Step 3: Commit

```bash
git add resources/js/Pages/Transactions/Create.tsx
git commit -m "feat(ui): show toast notification on repeated auto-save failures"
```

---

## Task 3: ClosingReportService Memory Optimization

**Files:**
- Modify: `app/Services/ClosingReportService.php`

**Interfaces:**
- Produces: Uses SQL SUM instead of loading all transactions into memory

### Step 1: Replace collection sum with SQL aggregate

In `app/Services/ClosingReportService.php`, update the `buildReport` method:

```php
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
            SUM(total_amount) as total_revenue,
            SUM(total_profit) as total_profit,
            SUM(CASE WHEN payment_method = ? THEN total_amount ELSE 0 END) as cash_total,
            SUM(CASE WHEN payment_method != ? THEN total_amount ELSE 0 END) as non_cash_total
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
```

### Step 2: Commit

```bash
git add app/Services/ClosingReportService.php
git commit -m "perf: optimize ClosingReportService to use SQL aggregates instead of loading all transactions"
```

---

## Task 4: Recalculate Session Totals on Close

**Files:**
- Modify: `app/Services/CashierSessionService.php`

**Interfaces:**
- Produces: Session totals recalculated from actual transactions before closing
- Produces: Data integrity safeguard against counter drift

### Step 1: Add recalculation before close

In `app/Services/CashierSessionService.php`, update the `closeSession` method:

```php
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
    $paidTransactions = \App\Models\Transaction::where('cashier_session_id', $session->id)
        ->where('status', 'paid')
        ->get();

    $session->update([
        'transactions_count' => $paidTransactions->count(),
        'cash_sales_total' => (float) $paidTransactions->where('payment_method', 'cash')->sum('total_amount'),
        'non_cash_sales_total' => (float) $paidTransactions->where('payment_method', '!=', 'cash')->sum('total_amount'),
    ]);
}
```

### Step 2: Commit

```bash
git add app/Services/CashierSessionService.php
git commit -m "fix: recalculate session totals from actual transactions before closing"
```

---

## Task 5: Fix handleCloseSession Dependencies

**Files:**
- Modify: `resources/js/Pages/Transactions/Create.tsx`

**Interfaces:**
- Produces: Optimized useCallback with minimal dependencies

### Step 1: Clean up dependency array

In `resources/js/Pages/Transactions/Create.tsx`, update the `handleCloseSession` useCallback:

```tsx
const handleCloseSession = useCallback(async () => {
    if (data.cart.length > 0) {
        notifyWarning(
            'Kosongkan keranjang sebelum tutup kasir.',
            'Keranjang masih terisi',
        );
        return;
    }

    setIsClosingSession(true);

    try {
        const closingData = await posService.closeSession(
            Number(closingCashPhysical || 0),
            closingNotes,
        );

        setClosingData(closingData);
        setShowClosingReport(true);
        setSessionState(null);
        setClosingCashPhysical('');
        setClosingNotes('');
        setShowSettlementModal(false);
        setShowOpenSessionModal(true);
        reset();
        setSearch('');
    } catch (error: any) {
        notifyError(error?.message || 'Gagal menutup kasir.');
    } finally {
        setIsClosingSession(false);
    }
}, [data.cart.length, closingCashPhysical, closingNotes, reset, setSearch]);
```

### Step 2: Commit

```bash
git add resources/js/Pages/Transactions/Create.tsx
git commit -m "fix: optimize handleCloseSession useCallback dependencies"
```

---

## Summary

| Task | Feature | Effort |
|------|---------|--------|
| 1 | React Error Boundary | 2 hours |
| 2 | Auto-save error visibility | 1 hour |
| 3 | ClosingReportService optimization | 30 min |
| 4 | Recalculate session totals on close | 15 min |
| 5 | Fix useCallback dependencies | 10 min |
| **Total** | | **~3.5 hours** |

---

## Verification

After completing all tasks:

```bash
# Build frontend
npm run build

# Test error boundary by temporarily adding a throw in a component
# Verify the recovery UI appears

# Test auto-save by disconnecting network briefly
# Verify toast appears after 3 failures

# Test session close with different scenarios
# Verify totals are accurate
```
