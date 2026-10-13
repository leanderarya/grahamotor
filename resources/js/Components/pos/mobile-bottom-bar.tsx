import { formatRupiah } from '@/lib/format';

interface MobileBottomBarProps {
    cartCount: number;
    totalAmount: number;
    hasOpenSession: boolean;
    onToggleCheckout: () => void;
    onSessionButtonClick: () => void;
}

export function MobileBottomBar({
    cartCount,
    totalAmount,
    hasOpenSession,
    onToggleCheckout,
    onSessionButtonClick,
}: MobileBottomBarProps) {
    return (
        <div className="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 p-3 shadow-[0_-12px_32px_rgba(15,23,42,0.08)] backdrop-blur lg:hidden">
            <div className="mx-auto flex max-w-xl items-center gap-3">
                <button
                    type="button"
                    onClick={onToggleCheckout}
                    aria-label={`Buka keranjang, ${cartCount} item, total Rp ${formatRupiah(totalAmount)}`}
                    className="min-h-12 flex-1 rounded-3xl bg-slate-950 px-4 py-3 text-left text-white shadow-sm transition-all duration-200 hover:bg-slate-800 hover:shadow-md"
                >
                    <div className="text-[11px] font-bold tracking-widest text-slate-400 uppercase">
                        Checkout Mobile
                    </div>
                    <div className="mt-1 flex items-center justify-between gap-3">
                        <span className="text-sm font-bold">
                            {cartCount} item di keranjang
                        </span>
                        <span className="text-lg font-bold">
                            Rp {formatRupiah(totalAmount)}
                        </span>
                    </div>
                </button>
                <button
                    type="button"
                    onClick={onSessionButtonClick}
                    aria-label={
                        hasOpenSession ? 'Tutup sesi kasir' : 'Buka sesi kasir'
                    }
                    className="min-h-12 rounded-3xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-700 shadow-sm transition-all duration-200 hover:bg-slate-50 hover:shadow-md"
                >
                    {hasOpenSession ? 'Tutup' : 'Buka'}
                </button>
            </div>
        </div>
    );
}
