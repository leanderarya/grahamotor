/**
 * Shared POS domain types.
 * Single source of truth — import from here instead of redefining inline.
 */

// ── Product ──────────────────────────────────────────────────────────────────

export interface Product {
    id: number;
    sku: string;
    name: string;
    category: string | null;
    image_url: string | null;
    image_path?: string | null;
    volume_liter: number | null;
    stock: number;
    sell_price: number;
    workshop_price: number | null;
    display_name: string;
    vehicles?: { brand?: string; model?: string }[];
}

// ── Cart ─────────────────────────────────────────────────────────────────────

export interface CartItem {
    id: number;
    name: string;
    sku?: string;
    stock: number | string;
    sell_price: number | string;
    workshop_price?: number | string;
    volume_liter?: number | string;
    image_url?: string;
    qty: number;
}

// ── Cashier Session ──────────────────────────────────────────────────────────

export interface CashierSession {
    id?: number;
    opening_cash?: number | string;
    cash_sales_total?: number | string;
    non_cash_sales_total?: number | string;
    transactions_count?: number;
    opened_at?: string;
    is_overdue?: boolean;
    overdue_at?: string;
}

// ── Draft ────────────────────────────────────────────────────────────────────

export interface ActiveDraft {
    id: number;
    invoice_number?: string;
    customer_type?: string;
    total_amount?: number;
    transaction_items?: {
        product: Product;
        quantity: number;
    }[];
}

// ── Transaction ──────────────────────────────────────────────────────────────

export interface TransactionListItem {
    id: number;
    invoice_number: string;
    created_at: string;
    items_count: number;
    customer_type: string;
    total_amount: number;
    payment_method: string;
    status: string;
    void_reason?: string;
    voided_at?: string;
    user?: { name: string };
}

export interface TransactionDetail {
    id: number;
    invoice_number: string;
    created_at: string;
    payment_method: string;
    customer_type: string;
    total_amount: number;
    amount_paid: number;
    change_amount: number;
    cashier_name?: string;
    items: TransactionDetailItem[];
}

export interface TransactionDetailItem {
    id: number;
    product_name: string;
    quantity: number;
    price_at_time: number;
    subtotal: number;
}

// ── Checkout / Draft ─────────────────────────────────────────────────────────

export interface CheckoutDraft {
    id: number;
    invoice_number: string;
    customer_type: string;
    total_amount: number;
    total_profit?: number;
    items: CheckoutDraftItem[];
}

export interface CheckoutDraftItem {
    id: number;
    product_id: number;
    product_name: string;
    quantity: number;
    price_at_time: number;
    subtotal: number;
}

// ── Recap ────────────────────────────────────────────────────────────────────

export interface RecapSummary {
    total_transactions: number;
    revenue_total: number;
    profit_total: number;
    cash_total: number;
    non_cash_total: number;
}

export interface TopProduct {
    product_name: string;
    image_url?: string;
    quantity: number;
    revenue: number;
}

// ── Paginated ────────────────────────────────────────────────────────────────

export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
}
