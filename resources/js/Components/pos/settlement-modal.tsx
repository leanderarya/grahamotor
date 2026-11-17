import { cn } from '@/lib/utils';
import { formatRupiah, sanitizeNumericInput, formatTime, formatDuration } from '@/lib/format';
import { X } from 'lucide-react';
import { ClosingReport } from './closing-report';
import type { CashierSession } from '@/types/pos';
import type { ClosingReportData } from '@/lib/printer';
import { formatSignedCurrency } from '@/lib/format';

interface SettlementModalProps {
    show: boolean;
    onClose: () => void;
    sessionState: CashierSession | null;
    closingCash: string;
    closingNotes: string;
    onClosingCashChange: (value: string) => void;
    onClosingNotesChange: (value: string) => void;
    onSubmit: () => void;
    isClosingSession: boolean;
    expectedCash: number;
    settlementDifference: number;
    settlementStatus: 'balance' | 'minus' | 'over';
    closingData: ClosingReportData | null;
    showClosingReport: boolean;
    onCloseClosingReport: () => void;
}

export function SettlementModal({
    show,
    onClose,
    sessionState,
    closingCash,
    closingNotes,
    onClosingCashChange,
    onClosingNotesChange,
    onSubmit,
    isClosingSession,
    expectedCash,
    settlementDifference,
    settlementStatus,
    closingData,
    showClosingReport,
    onCloseClosingReport,
}: SettlementModalProps) {
    if (!show) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div className="w-full max-w-xl rounded-xl bg-white p-4 shadow-2xl">
                {/* Header — compact */}
                <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <span className="text-sm font-bold text-slate-950">Tutup Kasir</span>
                        {sessionState?.opened_at && (
                            <span className="text-[11px] text-slate-400">
                                {formatTime(sessionState.opened_at)} · {formatDuration(sessionState.opened_at)}
                            </span>
                        )}
                    </div>
                    <button onClick={onClose} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100">
                        <X className="h-4 w-4" />
                    </button>
                </div>

                {/* Stat Grid — 2x2 compact */}
                <div className="mt-3 grid grid-cols-2 gap-2">
                    <div className="rounded-lg bg-slate-50 p-2.5">
                        <div className="text-[10px] font-bold tracking-wider text-slate-400 uppercase">Saldo Awal</div>
                        <div className="mt-1 text-sm font-bold text-slate-950">Rp {formatRupiah(Number(sessionState?.opening_cash || 0))}</div>
                    </div>
                    <div className="rounded-lg bg-slate-50 p-2.5">
                        <div className="text-[10px] font-bold tracking-wider text-slate-400 uppercase">Cash Sales</div>
                        <div className="mt-1 text-sm font-bold text-slate-950">Rp {formatRupiah(Number(sessionState?.cash_sales_total || 0))}</div>
                    </div>
                    <div className="rounded-lg bg-slate-50 p-2.5">
                        <div className="text-[10px] font-bold tracking-wider text-slate-400 uppercase">Non Cash</div>
                        <div className="mt-1 text-sm font-bold text-slate-950">Rp {formatRupiah(Number(sessionState?.non_cash_sales_total || 0))}</div>
                    </div>
                    <div className="rounded-lg bg-slate-900 p-2.5 text-white">
                        <div className="text-[10px] font-bold tracking-wider text-slate-400 uppercase">Expected Cash</div>
                        <div className="mt-1 text-sm font-bold">Rp {formatRupiah(expectedCash)}</div>
                    </div>
                </div>

                {/* Session summary row */}
                <div className="mt-2 flex items-center gap-3 rounded-lg bg-slate-50 px-3 py-1.5 text-[11px] text-slate-500">
                    <span className="font-semibold text-slate-700">{sessionState?.transactions_count || 0} transaksi</span>
                    <span className="text-slate-300">·</span>
                    <span>Total: Rp {formatRupiah((Number(sessionState?.cash_sales_total || 0)) + (Number(sessionState?.non_cash_sales_total || 0)))}</span>
                </div>

                {/* Input + Hasil — side by side compact */}
                <div className="mt-3 grid gap-3 md:grid-cols-2">
                    <div>
                        <label className="text-[10px] font-bold tracking-wider text-slate-400 uppercase">Uang Fisik Di Laci</label>
                        <div className="mt-1 flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-2">
                            <span className="text-xs font-bold text-slate-400">Rp</span>
                            <input
                                type="text"
                                inputMode="numeric"
                                value={closingCash}
                                onChange={(event) => onClosingCashChange(sanitizeNumericInput(event.target.value))}
                                placeholder="0"
                                className="w-full border-0 bg-transparent p-0 text-lg font-bold text-slate-950 focus:ring-0 focus:outline-none"
                            />
                        </div>

                        <label className="mt-2 block text-[10px] font-bold tracking-wider text-slate-400 uppercase">Catatan</label>
                        <textarea
                            rows={2}
                            value={closingNotes}
                            onChange={(event) => onClosingNotesChange(event.target.value)}
                            placeholder="Opsional"
                            className="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs text-slate-700 focus:ring-0 focus:outline-none"
                        />
                    </div>

                    <div className="rounded-lg border border-slate-200 p-3">
                        <div className="text-[10px] font-bold tracking-wider text-slate-400 uppercase">Hasil</div>
                        <div className={cn(
                            'mt-1.5 inline-flex rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase',
                            settlementStatus === 'balance' ? 'bg-emerald-100 text-emerald-700'
                                : settlementStatus === 'minus' ? 'bg-red-100 text-red-700'
                                    : 'bg-amber-100 text-amber-700',
                        )}>
                            {settlementStatus === 'balance' ? 'Balance' : settlementStatus === 'minus' ? 'Minus' : 'Lebih'}
                        </div>
                        <div className={cn(
                            'mt-1.5 text-xl font-bold leading-tight',
                            settlementStatus === 'balance' ? 'text-emerald-700'
                                : settlementStatus === 'minus' ? 'text-red-700'
                                    : 'text-amber-700',
                        )}>
                            {formatSignedCurrency(settlementDifference)}
                        </div>
                        <div className="mt-2 space-y-1 text-[11px] text-slate-500">
                            <div className="flex justify-between">
                                <span>Expected</span>
                                <span className="font-bold text-slate-900">Rp {formatRupiah(expectedCash)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span>Fisik</span>
                                <span className="font-bold text-slate-900">Rp {formatRupiah(Number(closingCash || 0))}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Actions — compact */}
                <div className="mt-3 flex gap-2">
                    <button
                        onClick={onClose}
                        className="flex-1 rounded-lg border border-slate-200 py-2.5 text-xs font-bold text-slate-700 transition-colors hover:bg-slate-50"
                    >
                        Kembali
                    </button>
                    <button
                        onClick={onSubmit}
                        disabled={isClosingSession}
                        className="flex-[1.2] rounded-lg bg-indigo-600 py-2.5 text-xs font-bold text-white transition-colors hover:bg-indigo-700 disabled:opacity-40"
                    >
                        {isClosingSession ? 'Menyimpan...' : 'Simpan & Tutup Kasir'}
                    </button>
                </div>
            </div>

            <ClosingReport
                show={showClosingReport}
                onClose={onCloseClosingReport}
                data={closingData}
            />
        </div>
    );
}
