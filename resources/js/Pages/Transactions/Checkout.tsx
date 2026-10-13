import {
    AppNotifications,
    notifyError,
    notifyWarning,
} from '@/Components/app-notifications';
import { useNetwork } from '@/hooks/useNetwork';
import { usePrintReceipt } from '@/hooks/usePrintReceipt';
import { isNative } from '@/lib/capacitor';
import {
    formatRupiah,
    formatSignedCurrency,
    sanitizeNumericInput,
} from '@/lib/format';
import { offlineQueue } from '@/lib/offline-queue';
import { cn } from '@/lib/utils';
import * as posService from '@/services/pos';
import type { SharedData } from '@/types';
import type { CashierSession, CheckoutDraft } from '@/types/pos';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Banknote, Building2, QrCode } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { route } from 'ziggy-js';

interface CheckoutProps {
    draft: CheckoutDraft;
    cashierSession: CashierSession | null;
}

const formSurface =
    'border border-slate-200 bg-white transition-all duration-200 ease-out';

const PAYMENT_METHODS = [
    {
        id: 'cash',
        label: 'Tunai',
        icon: Banknote,
        color: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    },
    {
        id: 'qris',
        label: 'QRIS',
        icon: QrCode,
        color: 'bg-blue-50 text-blue-700 border-blue-200',
    },
    {
        id: 'bank',
        label: 'Transfer',
        icon: Building2,
        color: 'bg-purple-50 text-purple-700 border-purple-200',
    },
];

export default function Checkout({ draft, cashierSession }: CheckoutProps) {
    const { flash } = usePage<SharedData>().props;
    const [paymentMethod, setPaymentMethod] = useState('cash');
    const [cashReceived, setCashReceived] = useState('');
    const [isProcessing, setIsProcessing] = useState(false);
    const { isOnline } = useNetwork();
    const { print: printReceipt } = usePrintReceipt();

    const hasOpenSession = Boolean(cashierSession?.id);

    const totalAmount = draft.total_amount;

    const change = useMemo(() => {
        if (paymentMethod !== 'cash') return 0;
        return (Number(cashReceived || 0) || 0) - totalAmount;
    }, [cashReceived, paymentMethod, totalAmount]);

    const cashShortcutAmounts = useMemo(() => {
        const roundUpToNearest = (amount: number, nearest: number) =>
            Math.ceil((amount || 0) / nearest) * nearest;

        const candidates = [
            totalAmount,
            50000,
            100000,
            200000,
            500000,
            roundUpToNearest(totalAmount, 5000),
            roundUpToNearest(totalAmount, 10000),
            roundUpToNearest(totalAmount, 50000),
        ];

        return [
            ...new Set(
                candidates.filter(
                    (amount) => amount >= totalAmount && amount > 0,
                ),
            ),
        ]
            .sort((a, b) => a - b)
            .slice(0, 6);
    }, [totalAmount]);

    const processPayment = useCallback(async () => {
        if (!hasOpenSession) {
            notifyWarning(
                'Buka kasir terlebih dahulu.',
                'Sesi kasir tidak aktif',
            );
            return;
        }

        const finalPaid =
            paymentMethod === 'cash' ? Number(cashReceived || 0) : totalAmount;

        if (paymentMethod === 'cash' && finalPaid < totalAmount) {
            notifyWarning('Uang pembayaran kurang.', 'Pembayaran belum cukup');
            return;
        }

        setIsProcessing(true);

        const finalChange =
            paymentMethod === 'cash' ? finalPaid - totalAmount : 0;

        const transactionData = {
            draft_id: draft.id,
            cart: draft.items.map((item) => ({
                id: item.product_id,
                qty: item.quantity,
            })),
            payment_method: paymentMethod,
            amount_paid: finalPaid,
            change_amount: finalChange,
            customer_type: draft.customer_type,
        };

        if (isNative()) {
            // Capacitor mode
            if (!isOnline) {
                // Offline: queue transaction
                await offlineQueue.add(transactionData);
                notifyWarning(
                    'Transaksi disimpan offline.',
                    'Akan dikirim saat online.',
                );
                router.visit(route('transactions.create'));
                return;
            }

            // Online: submit via posService
            try {
                const result = await posService.processPayment(transactionData);

                // Print receipt
                await printReceipt({
                    invoice:
                        result?.transaction?.invoice_number ??
                        draft.invoice_number,
                    date: new Date().toLocaleDateString('id-ID'),
                    items: draft.items.map((item) => ({
                        name: item.product_name,
                        sell_price: item.price_at_time,
                        qty: item.quantity,
                    })),
                    total: totalAmount,
                    payAmount: finalPaid,
                    change: finalChange,
                    paymentMethod,
                    customerType: draft.customer_type,
                });

                router.visit(route('transactions.create'));
            } catch (error: any) {
                notifyError(error.message || 'Gagal memproses transaksi.');
            } finally {
                setIsProcessing(false);
            }
        } else {
            // Web mode: existing Inertia behavior
            router.post(route('transactions.store'), transactionData, {
                onSuccess: () => {
                    router.visit(route('transactions.create'));
                },
                onError: (errors: Record<string, string>) => {
                    const message =
                        errors?.cart ||
                        errors?.error ||
                        errors?.payment_method ||
                        errors?.amount_paid ||
                        'Gagal memproses transaksi.';
                    notifyError(message);
                },
                onFinish: () => setIsProcessing(false),
            });
        }
    }, [
        cashReceived,
        draft,
        hasOpenSession,
        isOnline,
        paymentMethod,
        printReceipt,
        totalAmount,
    ]);

    return (
        <div className="min-h-screen bg-white text-slate-900">
            <Head title={`Checkout ${draft.invoice_number} - Graha Motor`} />
            <AppNotifications flash={flash} />

            <div className="mx-auto min-h-screen max-w-[1400px] px-3 py-3 sm:px-4 lg:grid lg:grid-cols-[55%_45%] lg:gap-4 lg:px-6">
                {/* Left Column: Order Summary */}
                <section className="space-y-3">
                    {/* Header */}
                    <div className="flex items-center justify-between gap-3">
                        <Link
                            href={route('transactions.create')}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition-all duration-200 hover:bg-slate-50"
                        >
                            <ArrowLeft className="h-3.5 w-3.5" />
                            Kembali
                        </Link>
                        <div className="flex items-center gap-2">
                            <span className="text-xs font-bold text-slate-400">
                                {draft.invoice_number}
                            </span>
                            {hasOpenSession && (
                                <span
                                    className="inline-block h-2 w-2 rounded-full bg-emerald-500"
                                    title="Sesi kasir aktif"
                                />
                            )}
                        </div>
                    </div>

                    {/* Order Summary Card */}
                    <div className="rounded-xl border border-slate-200 bg-white p-3">
                        <div className="flex items-center justify-between gap-3">
                            <div className="flex items-center gap-3">
                                <div className="text-xs font-bold tracking-wider text-slate-400 uppercase">
                                    Ringkasan
                                </div>
                                <span className="text-sm font-bold text-slate-950">
                                    {draft.items.length} item
                                </span>
                            </div>
                            <span
                                className={cn(
                                    'rounded-md px-2 py-1 text-xs font-bold',
                                    draft.customer_type === 'workshop'
                                        ? 'bg-amber-50 text-amber-700'
                                        : 'bg-slate-100 text-slate-600',
                                )}
                            >
                                {draft.customer_type === 'workshop'
                                    ? 'Bengkel'
                                    : 'Umum'}
                            </span>
                        </div>

                        {/* Items List */}
                        <div className="mt-2.5 space-y-1">
                            {draft.items.map((item) => (
                                <div
                                    key={item.id}
                                    className="flex items-center justify-between gap-2 rounded-md bg-slate-50 px-2.5 py-1.5"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="line-clamp-1 text-xs font-bold text-slate-900">
                                            {item.product_name}
                                        </div>
                                        <div className="text-[10px] font-semibold text-slate-500">
                                            {item.quantity} × Rp
                                            {formatRupiah(item.price_at_time)}
                                        </div>
                                    </div>
                                    <div className="text-xs font-bold whitespace-nowrap text-slate-950">
                                        Rp{formatRupiah(item.subtotal)}
                                    </div>
                                </div>
                            ))}
                        </div>

                        {/* Total */}
                        <div className="mt-3 rounded-lg bg-indigo-600 p-3 text-white">
                            <div className="text-xs font-semibold text-indigo-200">
                                Total Tagihan
                            </div>
                            <div className="text-xl font-bold">
                                Rp{formatRupiah(totalAmount)}
                            </div>
                        </div>
                    </div>
                </section>

                {/* Right Column: Payment */}
                <section className="space-y-3 lg:sticky lg:top-3">
                    <div className="rounded-xl border border-slate-200 bg-white p-3">
                        <div className="text-xs font-bold tracking-wider text-slate-400 uppercase">
                            Pembayaran
                        </div>

                        {/* Payment Method Selection */}
                        <div className="mt-2.5 grid grid-cols-3 gap-2">
                            {PAYMENT_METHODS.map((method) => {
                                const Icon = method.icon;
                                return (
                                    <button
                                        key={method.id}
                                        onClick={() =>
                                            setPaymentMethod(method.id)
                                        }
                                        className={cn(
                                            'flex flex-col items-center gap-1.5 rounded-lg border-2 px-2 py-2 text-xs font-bold transition-all duration-200',
                                            paymentMethod === method.id
                                                ? 'border-indigo-600 bg-indigo-600 text-white'
                                                : 'border-slate-200 bg-white text-slate-600',
                                        )}
                                    >
                                        <Icon className="h-4 w-4" />
                                        {method.label}
                                    </button>
                                );
                            })}
                        </div>

                        {/* Cash Payment UI */}
                        {paymentMethod === 'cash' && (
                            <div className="mt-3">
                                <label className="text-[10px] font-bold tracking-widest text-slate-400 uppercase">
                                    Uang Diterima
                                </label>
                                <div
                                    className={cn(
                                        'mt-1.5 flex items-center rounded-lg px-3 py-2',
                                        formSurface,
                                    )}
                                >
                                    <span className="text-sm font-bold text-slate-500">
                                        Rp
                                    </span>
                                    <input
                                        type="text"
                                        inputMode="numeric"
                                        value={cashReceived}
                                        onChange={(event) =>
                                            setCashReceived(
                                                sanitizeNumericInput(
                                                    event.target.value,
                                                ),
                                            )
                                        }
                                        placeholder="0"
                                        className="ml-2 w-full border-0 bg-transparent p-0 text-lg font-bold text-slate-950 focus:ring-0 focus:outline-none"
                                    />
                                </div>

                                {/* Quick Buttons */}
                                <div className="mt-2 grid grid-cols-3 gap-1.5">
                                    {cashShortcutAmounts.map(
                                        (amount, index) => (
                                            <button
                                                key={`${amount}-${index}`}
                                                type="button"
                                                onClick={() =>
                                                    setCashReceived(
                                                        String(amount),
                                                    )
                                                }
                                                className={cn(
                                                    'rounded-md border px-2 py-1.5 text-[11px] font-bold transition-all duration-200',
                                                    Number(
                                                        cashReceived || 0,
                                                    ) === amount
                                                        ? 'border-indigo-600 bg-indigo-600 text-white'
                                                        : amount === totalAmount
                                                          ? 'border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100'
                                                          : 'border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100',
                                                )}
                                            >
                                                {amount === totalAmount
                                                    ? 'Uang Pas'
                                                    : `Rp${formatRupiah(amount)}`}
                                            </button>
                                        ),
                                    )}
                                </div>

                                {/* Change Display */}
                                <div
                                    className={cn(
                                        'mt-2 rounded-md p-2.5 text-xs font-bold',
                                        change < 0
                                            ? 'bg-red-50 text-red-600'
                                            : 'bg-emerald-50 text-emerald-600',
                                    )}
                                >
                                    {change < 0
                                        ? `Kurang ${formatSignedCurrency(change)}`
                                        : `Kembali Rp${formatRupiah(change)}`}
                                </div>
                            </div>
                        )}

                        {/* QRIS / Transfer Info */}
                        {paymentMethod !== 'cash' && (
                            <div className="mt-3 rounded-md bg-slate-50 p-2.5 text-center">
                                <div className="text-xs font-semibold text-slate-600">
                                    Non-tunai dianggap lunas sesuai total.
                                </div>
                                <div className="text-[10px] font-bold text-slate-400">
                                    Konfirmasi manual oleh kasir.
                                </div>
                            </div>
                        )}

                        {/* Pay Button */}
                        <div className="mt-3">
                            <button
                                onClick={processPayment}
                                disabled={
                                    !hasOpenSession ||
                                    isProcessing ||
                                    (paymentMethod === 'cash' && change < 0)
                                }
                                className="w-full rounded-lg bg-indigo-600 px-4 py-3 text-sm font-bold text-white transition-all duration-200 hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-40"
                            >
                                {isProcessing
                                    ? 'Memproses...'
                                    : 'Bayar Sekarang'}
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    );
}
