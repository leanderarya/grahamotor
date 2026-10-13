import { formatRupiah, getProductLabel } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Minus, Plus, Trash2 } from 'lucide-react';
import { useEffect, useRef } from 'react';

interface CartItem {
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

interface CheckoutPanelProps {
    cart: CartItem[];
    productById: Map<number, CartItem>;
    getProductPrice: (product: CartItem | null | undefined) => number;
    clearCart: () => void;
    removeItem: (id: number) => void;
    updateQty: (id: number, delta: number) => void;
    totalAmount: number;
    totalQty: number;
    isWorkshop: boolean;
    hasOpenSession: boolean;
    customerType: string;
    onCustomerTypeChange: (type: string) => void;
    onSaveDraft: () => void;
    mobile?: boolean;
    onClose?: () => void;
}

export function CheckoutPanel({
    cart,
    productById,
    getProductPrice,
    clearCart,
    removeItem,
    updateQty,
    totalAmount,
    totalQty,
    isWorkshop,
    onCustomerTypeChange,
    onSaveDraft,
    mobile = false,
    onClose,
}: CheckoutPanelProps) {
    const dialogRef = useRef<HTMLElement>(null);
    const closeButtonRef = useRef<HTMLButtonElement>(null);
    const previousFocusRef = useRef<HTMLElement | null>(null);

    useEffect(() => {
        if (!mobile) return;

        previousFocusRef.current =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        closeButtonRef.current?.focus();

        return () => previousFocusRef.current?.focus();
    }, [mobile]);

    useEffect(() => {
        if (!mobile) return;

        const dialog = dialogRef.current;
        if (!dialog) return;

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                onClose?.();
                return;
            }

            if (event.key !== 'Tab') return;
            const focusable = Array.from(
                dialog.querySelectorAll<HTMLElement>(
                    'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
                ),
            ).filter((element) => element.getClientRects().length > 0);
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (!first || !last) return;

            if (!dialog.contains(document.activeElement)) {
                event.preventDefault();
                (event.shiftKey ? last : first).focus();
            } else if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [mobile, onClose]);

    return (
        <section
            ref={dialogRef}
            className={cn(
                'flex flex-col border-slate-200 bg-white',
                mobile
                    ? 'h-[85dvh] w-full rounded-t-2xl border-t shadow-2xl'
                    : 'w-full border-l',
            )}
            role={mobile ? 'dialog' : undefined}
            aria-modal={mobile || undefined}
            aria-labelledby={mobile ? 'mobile-cart-title' : undefined}
        >
            {mobile && (
                <button
                    ref={closeButtonRef}
                    type="button"
                    onClick={onClose}
                    className="min-h-11 self-end px-4 py-2 text-sm font-semibold text-slate-500"
                >
                    Tutup
                </button>
            )}
            {/* Header */}
            <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <div
                    className="text-sm font-bold text-slate-950"
                    id={mobile ? 'mobile-cart-title' : undefined}
                >
                    Keranjang
                    {cart.length > 0 && (
                        <span className="ml-1.5 text-slate-400">
                            ({cart.length})
                        </span>
                    )}
                </div>
                <div className="flex items-center gap-1">
                    {cart.length > 0 && (
                        <button
                            type="button"
                            onClick={clearCart}
                            aria-label="Kosongkan keranjang"
                            className="flex h-11 w-11 items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-red-50 hover:text-red-600"
                        >
                            <Trash2 aria-hidden="true" className="h-4 w-4" />
                        </button>
                    )}
                </div>
            </div>

            {/* Cart Items */}
            <div className="flex-1 overflow-y-auto">
                {cart.length === 0 && (
                    <div className="flex flex-col items-center justify-center px-4 py-16 text-center">
                        <div className="text-3xl" aria-hidden="true">
                            🛒
                        </div>
                        <div className="mt-3 text-sm font-semibold text-slate-500">
                            Keranjang kosong
                        </div>
                        <div className="mt-1 text-xs text-slate-400">
                            Tap produk untuk mulai
                        </div>
                    </div>
                )}

                {cart.map((item, index) => {
                    const product = productById.get(item.id) || item;
                    const price = getProductPrice(product);

                    return (
                        <div
                            key={item.id}
                            className="touch-tooltip"
                            data-tooltip={getProductLabel(item)}
                        >
                            <div className="flex items-start gap-3 px-4 py-3">
                                <div className="min-w-0 flex-1">
                                    <div className="line-clamp-1 text-sm font-semibold text-slate-950">
                                        {getProductLabel(item)}
                                    </div>
                                    <div className="mt-0.5 text-xs text-slate-500">
                                        Rp {formatRupiah(price)} × {item.qty}
                                    </div>
                                </div>

                                <div className="flex items-center gap-1.5">
                                    <button
                                        type="button"
                                        onClick={() => updateQty(item.id, -1)}
                                        aria-label={`Kurangi jumlah ${getProductLabel(item)}`}
                                        className="flex h-11 w-11 items-center justify-center rounded-lg border border-slate-200 text-slate-600 transition-colors hover:bg-slate-50"
                                    >
                                        <Minus
                                            aria-hidden="true"
                                            className="h-3.5 w-3.5"
                                        />
                                    </button>
                                    <span className="w-8 text-center text-sm font-bold text-slate-950">
                                        {item.qty}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => updateQty(item.id, 1)}
                                        aria-label={`Tambah jumlah ${getProductLabel(item)}`}
                                        className="flex h-11 w-11 items-center justify-center rounded-lg border border-slate-200 text-slate-600 transition-colors hover:bg-slate-50"
                                    >
                                        <Plus
                                            aria-hidden="true"
                                            className="h-3.5 w-3.5"
                                        />
                                    </button>
                                </div>

                                <div className="flex flex-col items-end gap-1">
                                    <div className="text-sm font-bold whitespace-nowrap text-slate-950">
                                        Rp{' '}
                                        {formatRupiah(
                                            price * Number(item.qty || 0),
                                        )}
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => removeItem(item.id)}
                                        aria-label={`Hapus ${getProductLabel(item)} dari keranjang`}
                                        className="flex h-11 w-11 items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-red-50 hover:text-red-500"
                                    >
                                        <Trash2
                                            aria-hidden="true"
                                            className="h-3.5 w-3.5"
                                        />
                                    </button>
                                </div>
                            </div>
                            {index < cart.length - 1 && (
                                <div className="border-b border-slate-100" />
                            )}
                        </div>
                    );
                })}
            </div>

            {/* Footer */}
            <div className="space-y-3 border-t border-slate-200 px-4 py-3">
                {/* Customer Type Toggle */}
                <div className="flex gap-1 rounded-lg bg-slate-100 p-1">
                    <button
                        type="button"
                        onClick={() => onCustomerTypeChange('general')}
                        aria-pressed={!isWorkshop}
                        className={cn(
                            'min-h-11 flex-1 rounded-md py-1.5 text-xs font-semibold transition-colors',
                            !isWorkshop
                                ? 'border border-slate-200 bg-white text-slate-950'
                                : 'text-slate-500',
                        )}
                    >
                        Umum
                    </button>
                    <button
                        type="button"
                        onClick={() => onCustomerTypeChange('workshop')}
                        aria-pressed={isWorkshop}
                        className={cn(
                            'min-h-11 flex-1 rounded-md py-1.5 text-xs font-semibold transition-colors',
                            isWorkshop
                                ? 'border border-slate-200 bg-white text-amber-700'
                                : 'text-slate-500',
                        )}
                    >
                        Bengkel
                    </button>
                </div>

                {/* Summary */}
                <div className="flex items-center justify-between text-xs text-slate-500">
                    <span>
                        {cart.length} item · {totalQty} pcs
                    </span>
                </div>

                {/* Total */}
                <div className="flex items-center justify-between">
                    <span className="text-sm font-semibold text-slate-500">
                        Total
                    </span>
                    <span className="text-2xl font-bold text-slate-950">
                        Rp {formatRupiah(totalAmount)}
                    </span>
                </div>

                {/* CTA */}
                <button
                    type="button"
                    onClick={onSaveDraft}
                    disabled={cart.length === 0}
                    className="min-h-12 w-full rounded-lg bg-indigo-600 px-4 py-3 text-sm font-bold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    Bayar Sekarang
                </button>
            </div>
        </section>
    );
}
