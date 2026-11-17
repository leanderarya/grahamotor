import { Head, Link, usePage } from '@inertiajs/react';
import { route } from 'ziggy-js';
import { useState } from 'react';
import { ArrowLeft, Bluetooth, Printer as PrinterIcon } from 'lucide-react';
import type { SharedData } from '@/types';
import { usePrinter } from '@/hooks/usePrinter';
import { TopBar } from '@/Components/pos/top-bar';

export default function PrinterSettings() {
    const { auth } = usePage<SharedData>().props;
    const { devices, isScanning, scan, connect, disconnect, connectedDevice, isConnected } = usePrinter();
    const [connectingTo, setConnectingTo] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const handleConnect = async (device: Parameters<typeof connect>[0]) => {
        setConnectingTo(device.address);
        setError(null);
        try {
            await connect(device);
        } catch {
            setError(`Gagal terhubung ke ${device.name}`);
        } finally {
            setConnectingTo(null);
        }
    };

    return (
        <div className="flex h-screen flex-col bg-white">
            <Head title="Pengaturan Printer - Graha Motor" />

            <TopBar
                search=""
                onSearchChange={() => {}}
                hasOpenSession={false}
                userName={auth?.user?.name || ''}
                onSettlementClick={() => {}}
            />

            <main className="flex-1 overflow-y-auto p-3 lg:p-4">
                {/* Header */}
                <div className="flex items-center gap-2">
                    <Link
                        href={route('transactions.create')}
                        className="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 text-slate-600 transition-colors hover:bg-slate-50"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Link>
                    <span className="text-base font-bold text-slate-950">Pengaturan Printer</span>
                </div>

                {/* Status */}
                <div className="mt-3 rounded-lg border border-slate-200 bg-white p-3">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <PrinterIcon className="h-4 w-4 text-slate-400" />
                            <span className="text-xs font-bold text-slate-950">Status Printer</span>
                        </div>
                        <span className={`inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase ${
                            isConnected ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'
                        }`}>
                            {isConnected ? '● Terhubung' : '○ Tidak Terhubung'}
                        </span>
                    </div>
                    {connectedDevice && (
                        <div className="mt-2 flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                            <div>
                                <div className="text-xs font-semibold text-slate-950">{connectedDevice.name}</div>
                                <div className="text-[10px] text-slate-400">{connectedDevice.address}</div>
                            </div>
                            <button
                                onClick={disconnect}
                                className="rounded-lg px-2 py-1 text-[10px] font-bold text-red-600 transition-colors hover:bg-red-50"
                            >
                                Putus
                            </button>
                        </div>
                    )}
                </div>

                {/* Scan */}
                <div className="mt-3">
                    <button
                        onClick={async () => {
                            setError(null);
                            try {
                                await scan();
                            } catch (error: unknown) {
                                setError(
                                    error instanceof Error && error.message
                                        ? error.message
                                        : 'Gagal scan Bluetooth. Pastikan Bluetooth aktif.',
                                );
                            }
                        }}
                        disabled={isScanning}
                        className="flex w-full items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-xs font-bold text-slate-700 transition-colors hover:bg-slate-50 disabled:opacity-50"
                    >
                        <Bluetooth className="h-4 w-4" />
                        {isScanning ? 'Mencari...' : 'Scan Perangkat Bluetooth'}
                    </button>
                </div>

                {/* Hint */}
                <div className="mt-2 rounded-lg bg-blue-50 px-3 py-2 text-[11px] text-blue-700">
                    <span className="font-bold">Tips:</span> Pastikan printer sudah dipasangkan (pair) di pengaturan Bluetooth Android terlebih dahulu.
                </div>

                {/* Device List */}
                <div className="mt-3 space-y-1">
                    {devices.length === 0 && !isScanning && (
                        <div className="rounded-lg border border-dashed border-slate-200 py-6 text-center text-xs text-slate-400">
                            Belum ada perangkat. Tekan Scan dan pastikan printer sudah dipasangkan di Android.
                        </div>
                    )}

                    {devices.map((device) => (
                        <div
                            key={device.address}
                            className="flex items-center justify-between rounded-lg border border-slate-200 bg-white px-3 py-2"
                        >
                            <div>
                                <div className="text-xs font-semibold text-slate-950">{device.name || 'Unknown Device'}</div>
                                <div className="text-[10px] text-slate-400">{device.address}</div>
                            </div>
                            <button
                                onClick={() => handleConnect(device)}
                                disabled={connectingTo === device.address}
                                className={`rounded-lg px-2.5 py-1.5 text-[10px] font-bold transition-colors ${
                                    connectedDevice?.address === device.address
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-indigo-600 text-white hover:bg-indigo-700'
                                } disabled:opacity-50`}
                            >
                                {connectingTo === device.address ? '...' : connectedDevice?.address === device.address ? '✓ Terpilih' : 'Pilih'}
                            </button>
                        </div>
                    ))}
                </div>

                {error && (
                    <div className="mt-2 rounded-lg border border-red-200 bg-red-50 p-2 text-[11px] text-red-600">
                        {error}
                    </div>
                )}
            </main>
        </div>
    );
}
