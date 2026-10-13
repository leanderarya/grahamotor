import { notifyWarning } from '@/Components/app-notifications';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import {
    BarChart3,
    Calculator,
    ChevronDown,
    History,
    LogOut,
    Printer,
    Search,
    Settings,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { route } from 'ziggy-js';

interface TopBarProps {
    search: string;
    onSearchChange: (value: string) => void;
    hasOpenSession: boolean;
    userName: string;
    onSettlementClick: () => void;
}

export function TopBar({
    search,
    onSearchChange,
    hasOpenSession,
    userName,
    onSettlementClick,
}: TopBarProps) {
    const [dropdownOpen, setDropdownOpen] = useState(false);
    const [hasSavedPrinter] = useState(
        () =>
            typeof window !== 'undefined' &&
            !!window.localStorage.getItem('printer_device'),
    );
    const dropdownRef = useRef<HTMLDivElement>(null);
    const prefetch = (name: string) =>
        router.prefetch(route(name), {}, { cacheFor: '3s' });

    useEffect(() => {
        function handleClickOutside(e: MouseEvent) {
            if (
                dropdownRef.current &&
                !dropdownRef.current.contains(e.target as Node)
            ) {
                setDropdownOpen(false);
            }
        }
        document.addEventListener('mousedown', handleClickOutside);
        return () =>
            document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    return (
        <header className="flex h-14 shrink-0 items-center justify-between border-b border-slate-200 bg-white px-4">
            {/* Left: Logo + Brand */}
            <div className="flex items-center gap-2.5">
                <img
                    src="/Grahamotor-light.png"
                    alt="Graha Motor"
                    className="h-7 w-7 object-contain"
                />
                <span className="text-base font-bold text-slate-950">
                    Graha Motor
                </span>
            </div>

            {/* Center: Search */}
            <div className="mx-6 max-w-md flex-1">
                <div className="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <Search className="h-4 w-4 text-slate-400" />
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => onSearchChange(e.target.value)}
                        placeholder="Cari barang, SKU, model..."
                        className="w-full border-0 bg-transparent p-0 text-sm text-slate-950 placeholder:text-slate-400 focus:ring-0 focus:outline-none"
                    />
                </div>
            </div>

            {/* Right: Status + Avatar */}
            <div className="flex items-center gap-3">
                {/* Status Dot */}
                <button
                    onClick={onSettlementClick}
                    className={cn(
                        'h-3 w-3 rounded-full transition-colors',
                        hasOpenSession
                            ? 'bg-emerald-500 hover:bg-emerald-600'
                            : 'bg-red-500 hover:bg-red-600',
                    )}
                    title={
                        hasOpenSession
                            ? 'Sesi aktif — tap untuk settlement'
                            : 'Belum buka kasir'
                    }
                />

                {/* Printer Status */}
                <Link
                    href={route('settings.printer')}
                    className="relative"
                    title={
                        hasSavedPrinter
                            ? 'Printer terkonfigurasi'
                            : 'Printer belum dikonfigurasi'
                    }
                >
                    <Printer className="h-4 w-4 text-slate-400" />
                    <span
                        className={`absolute -top-0.5 -right-0.5 h-2 w-2 rounded-full ${hasSavedPrinter ? 'bg-emerald-500' : 'bg-slate-300'}`}
                    />
                </Link>

                {/* Avatar Dropdown */}
                <div className="relative" ref={dropdownRef}>
                    <button
                        onClick={() => setDropdownOpen(!dropdownOpen)}
                        className="flex items-center gap-2 rounded-lg px-2 py-1.5 transition-colors hover:bg-slate-100"
                    >
                        <div className="flex h-7 w-7 items-center justify-center rounded-md bg-slate-200 text-xs font-bold text-slate-600">
                            {userName.charAt(0).toUpperCase()}
                        </div>
                        <ChevronDown className="h-3.5 w-3.5 text-slate-400" />
                    </button>

                    {dropdownOpen && (
                        <div className="absolute top-full right-0 z-50 mt-1 w-52 rounded-xl border border-slate-200 bg-white py-1">
                            <div className="px-4 py-2.5">
                                <div className="text-sm font-semibold text-slate-950">
                                    {userName}
                                </div>
                                <div className="text-xs text-slate-500">
                                    Kasir aktif
                                </div>
                            </div>
                            <div className="border-t border-slate-100" />
                            <button
                                onPointerEnter={() =>
                                    prefetch('transactions.recap')
                                }
                                onFocus={() => prefetch('transactions.recap')}
                                onTouchStart={() =>
                                    prefetch('transactions.recap')
                                }
                                onClick={() => {
                                    setDropdownOpen(false);
                                    router.visit(route('transactions.recap'));
                                }}
                                className="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-slate-950 hover:bg-slate-50"
                            >
                                <BarChart3 className="h-4 w-4 text-slate-400" />
                                Rekap Penjualan
                            </button>
                            <button
                                onPointerEnter={() =>
                                    prefetch('transactions.history')
                                }
                                onFocus={() => prefetch('transactions.history')}
                                onTouchStart={() =>
                                    prefetch('transactions.history')
                                }
                                onClick={() => {
                                    setDropdownOpen(false);
                                    router.visit(route('transactions.history'));
                                }}
                                className="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-slate-950 hover:bg-slate-50"
                            >
                                <History className="h-4 w-4 text-slate-400" />
                                Riwayat Penjualan
                            </button>
                            <button
                                onPointerEnter={() =>
                                    prefetch('settings.printer')
                                }
                                onFocus={() => prefetch('settings.printer')}
                                onTouchStart={() =>
                                    prefetch('settings.printer')
                                }
                                onClick={() => {
                                    setDropdownOpen(false);
                                    router.visit(route('settings.printer'));
                                }}
                                className="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-slate-950 hover:bg-slate-50"
                            >
                                <Settings className="h-4 w-4 text-slate-400" />
                                Pengaturan Printer
                            </button>
                            <button
                                onClick={() => {
                                    setDropdownOpen(false);
                                    onSettlementClick();
                                }}
                                className="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-slate-950 hover:bg-slate-50"
                            >
                                <Calculator className="h-4 w-4 text-slate-400" />
                                Settlement
                            </button>
                            <div className="border-t border-slate-100" />
                            <button
                                onClick={() => {
                                    setDropdownOpen(false);
                                    if (hasOpenSession) {
                                        notifyWarning(
                                            'Kasir masih terbuka. Selesaikan settlement terlebih dahulu.',
                                            'Logout diblokir',
                                        );
                                        return;
                                    }
                                    router.post(route('logout'));
                                }}
                                className="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50"
                            >
                                <LogOut className="h-4 w-4" />
                                Keluar
                            </button>
                            <button
                                onClick={() => {
                                    setDropdownOpen(false);
                                    router.post(route('switch-user'));
                                }}
                                className="flex w-full items-center gap-3 px-4 py-2.5 text-xs text-slate-500 hover:bg-slate-50"
                            >
                                Ganti Akun
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </header>
    );
}
