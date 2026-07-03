/**
 * POS Service — abstraction over web (Inertia) and native (Capacitor API) transports.
 *
 * Every POS operation goes through here instead of inline `if (isNative())` branches.
 * Components call posService.openSession() etc. and don't care which transport fires.
 */
import { router } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { isNative } from '@/lib/capacitor';
import { getCsrfToken } from '@/lib/csrf';
import { apiClient } from '@/api/client';
import { productCache } from '@/lib/product-cache';

// ── Session ──────────────────────────────────────────────────────────────────

export async function openSession(openingCash: number, openingNotes: string): Promise<void> {
    if (isNative()) {
        await apiClient.post('/session/open', {
            opening_cash: openingCash,
            opening_notes: openingNotes,
        });
        return;
    }
    router.post(route('transactions.session.open'), {
        opening_cash: openingCash,
        opening_notes: openingNotes,
    });
}

export async function closeSession(
    closingCashPhysical: number,
    closingNotes: string,
): Promise<Record<string, unknown> | null> {
    if (isNative()) {
        const data = await apiClient.post('/session/close', {
            closing_cash_physical: closingCashPhysical,
            closing_notes: closingNotes,
        });
        return data.closingData ?? null;
    }

    // Web: fetch for JSON response so we can get closingData back
    const response = await fetch(route('transactions.session.close'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
        },
        body: JSON.stringify({
            closing_cash_physical: closingCashPhysical,
            closing_notes: closingNotes,
        }),
    });

    if (!response.ok) {
        const err = await response.json().catch(() => ({}));
        throw new Error(err.message || 'Gagal menutup kasir.');
    }

    const data = await response.json();
    return data.closingData ?? null;
}

// ── Drafts ───────────────────────────────────────────────────────────────────

interface DraftCart {
    id: number;
    qty: number;
}

export async function saveDraft(
    cart: DraftCart[],
    customerType: string,
    draftId: number | null,
): Promise<void> {
    if (isNative()) {
        await apiClient.post('/draft', { cart, customer_type: customerType, draft_id: draftId });
        return;
    }
    router.post(route('transactions.draft.save'), { cart, customer_type: customerType, draft_id: draftId });
}

export async function autoSaveDraft(
    cart: DraftCart[],
    customerType: string,
    draftId: number | null,
): Promise<{ draft_id?: number } | null> {
    const payload = { cart, customer_type: customerType, draft_id: draftId };

    if (isNative()) {
        return apiClient.put('/draft/auto-save', payload);
    }

    const response = await fetch(route('transactions.draft.autoSave'), {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok) return null;
    return response.json();
}

export async function clearDraft(draftId: number | null): Promise<void> {
    if (isNative()) {
        await apiClient.post('/draft/clear', { draft_id: draftId });
        return;
    }

    await fetch(route('transactions.draft.clear'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ draft_id: draftId }),
    });
}

// ── Transactions ─────────────────────────────────────────────────────────────

interface PaymentPayload {
    draft_id: number;
    cart: { id: number; qty: number }[];
    payment_method: string;
    amount_paid: number;
    change_amount: number;
    customer_type: string;
}

export async function processPayment(
    payload: PaymentPayload,
): Promise<{ transaction?: { invoice_number: string } } | void> {
    if (isNative()) {
        return apiClient.post('/transactions', payload);
    }
    router.post(route('transactions.store'), payload);
}

export async function voidTransaction(
    transactionId: number,
    reason: string,
): Promise<void> {
    if (isNative()) {
        await apiClient.post(`/transactions/${transactionId}/void`, { reason });
        return;
    }
    router.post(route('transactions.void', transactionId), { reason });
}

// ── Product Cache ────────────────────────────────────────────────────────────

interface CachedProduct {
    id: number;
    sku: string;
    name: string;
    category: string | null;
    image_url: string | null;
    volume_liter: number | null;
    stock: number;
    sell_price: number;
    workshop_price: number | null;
    display_name: string;
    min_stock?: number;
}

export async function fetchAndCacheProducts(): Promise<CachedProduct[]> {
    if (!isNative()) {
        return [];
    }

    try {
        const data = await apiClient.get<{ products: CachedProduct[] }>('/products');
        const products = data.products || [];

        // Cache for offline use
        await productCache.save(products);

        return products;
    } catch {
        // If fetch fails, try to load from cache
        return productCache.load();
    }
}

export async function getOfflineProducts(): Promise<CachedProduct[]> {
    return productCache.load();
}
