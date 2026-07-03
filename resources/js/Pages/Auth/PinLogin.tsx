import InputError from '@/Components/input-error';
import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import { route } from 'ziggy-js';
import { isNative } from '@/lib/capacitor';
import { apiClient, setToken } from '@/api/client';

export default function PinLogin() {
    const [pin, setPin] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [shake, setShake] = useState(false);
    const submitting = useRef(false);

    const greeting = useMemo(() => {
        const hour = new Date().getHours();
        if (hour < 11) return 'Selamat Pagi';
        if (hour < 15) return 'Selamat Siang';
        if (hour < 19) return 'Selamat Sore';
        return 'Selamat Malam';
    }, []);

    const submit = (value: string) => {
        if (submitting.current) return;
        submitting.current = true;
        setProcessing(true);
        setErrors({});

        if (isNative()) {
            // Capacitor: use API with token auth, then create web session
            apiClient
                .post('/login', { pin: value })
                .then(async (data) => {
                    await setToken(data.token);
                    // Also create web session so Capacitor can load web pages
                    try {
                        // Get CSRF token first
                        await fetch('/sanctum/csrf-cookie', {
                            credentials: 'same-origin',
                        });
                        const csrfToken =
                            document.cookie
                                .split('; ')
                                .find((row) => row.startsWith('XSRF-TOKEN='))
                                ?.split('=')[1] || '';
                        await fetch('/pin-login', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'text/html',
                                'X-XSRF-TOKEN': decodeURIComponent(csrfToken),
                            },
                            body: JSON.stringify({ pin: value }),
                            credentials: 'same-origin',
                        });
                    } catch {
                        // Ignore — API token is primary auth
                    }
                    window.location.href = '/pos';
                })
                .catch((err) => {
                    setErrors({ pin: err.message || 'PIN salah.' });
                    setShake(true);
                    setTimeout(() => {
                        setShake(false);
                        setPin('');
                        submitting.current = false;
                        setProcessing(false);
                    }, 500);
                });
        } else {
            // Web: use Inertia (existing behavior)
            router.post(
                route('pin.login.store'),
                { pin: value },
                {
                    onError: (errs) => {
                        setErrors(errs);
                        setShake(true);
                        setTimeout(() => {
                            setShake(false);
                            setPin('');
                            submitting.current = false;
                            setProcessing(false);
                        }, 500);
                    },
                    onFinish: () => {
                        if (!submitting.current) setProcessing(false);
                    },
                },
            );
        }
    };

    const handleKey = (digit: string) => {
        if (processing) return;
        setErrors({});
        if (pin.length < 4) {
            const next = pin + digit;
            setPin(next);
            if (next.length === 4) {
                submit(next);
            }
        }
    };

    const handleDelete = () => {
        if (processing) return;
        setErrors({});
        setPin(pin.slice(0, -1));
    };

    return (
        <div className="relative min-h-screen overflow-hidden bg-slate-100 text-slate-950">
            <Head title="Masuk Kasir - Graha Motor" />

            <div className="pointer-events-none absolute inset-0 overflow-hidden">
                <div className="absolute top-[-10rem] left-[-6rem] h-80 w-80 rounded-full bg-blue-200/40 blur-3xl" />
                <div className="absolute right-[-7rem] bottom-[-8rem] h-96 w-96 rounded-full bg-emerald-200/30 blur-3xl" />
                <div className="absolute inset-x-0 top-0 h-72 bg-gradient-to-b from-white/90 via-white/40 to-transparent" />
            </div>

            <main className="relative mx-auto flex min-h-screen w-full max-w-xl items-center justify-center px-4 py-8 sm:px-6">
                <section className="mx-auto w-full max-w-sm">
                    <div className="rounded-2xl border border-white/80 bg-white/95 p-6 shadow-xl backdrop-blur sm:p-8">
                        <div className="mt-4 text-center">
                            <div className="font-sans text-3xl font-extrabold tracking-tight text-slate-950">
                                {greeting}
                            </div>
                            <p className="mt-2 text-sm font-medium text-slate-500">
                                Masukkan PIN kasir untuk melanjutkan.
                            </p>
                        </div>

                        {/* PIN Dots */}
                        <div
                            className={cn(
                                'mt-8 flex items-center justify-center gap-4',
                                shake && 'animate-[shake_0.5s_ease-in-out]',
                            )}
                        >
                            {[0, 1, 2, 3].map((i) => (
                                <div
                                    key={i}
                                    className={cn(
                                        'flex h-14 w-14 items-center justify-center rounded-xl border-2 text-2xl font-bold transition-all duration-200',
                                        pin.length > i
                                            ? 'border-slate-950 bg-slate-950 text-white scale-105'
                                            : 'border-slate-200 bg-slate-50 text-slate-300',
                                        errors.pin &&
                                            'border-red-400 bg-red-50',
                                    )}
                                >
                                    {pin.length > i ? '•' : ''}
                                </div>
                            ))}
                        </div>

                        {errors.pin && (
                            <InputError
                                message={errors.pin}
                                className="mt-3 text-center"
                            />
                        )}

                        {/* Numpad */}
                        <div className="mt-8 grid grid-cols-3 gap-3">
                            {[1, 2, 3, 4, 5, 6, 7, 8, 9].map((digit) => (
                                <button
                                    key={digit}
                                    type="button"
                                    disabled={processing}
                                    onClick={() => handleKey(String(digit))}
                                    className="flex h-16 items-center justify-center rounded-xl bg-slate-100 text-2xl font-bold text-slate-900 transition-all duration-100 hover:bg-slate-200 active:scale-95 disabled:opacity-50"
                                >
                                    {digit}
                                </button>
                            ))}
                            <div /> {/* empty space */}
                            <button
                                type="button"
                                disabled={processing}
                                onClick={() => handleKey('0')}
                                className="flex h-16 items-center justify-center rounded-xl bg-slate-100 text-2xl font-bold text-slate-900 transition-all duration-100 hover:bg-slate-200 active:scale-95 disabled:opacity-50"
                            >
                                0
                            </button>
                            <button
                                type="button"
                                disabled={processing}
                                onClick={handleDelete}
                                className="flex h-16 items-center justify-center rounded-xl bg-slate-100 text-lg font-bold text-slate-500 transition-all duration-100 hover:bg-slate-200 active:scale-95 disabled:opacity-50"
                            >
                                ←
                            </button>
                        </div>

                        {processing && (
                            <div className="mt-4 text-center text-sm font-semibold text-slate-500">
                                Memproses...
                            </div>
                        )}

                        <div className="mt-8 border-t border-slate-200 pt-5 text-center">
                            <a
                                href="/login"
                                className="text-xs font-bold text-slate-400 transition hover:text-slate-600"
                            >
                                Login Admin →
                            </a>
                        </div>
                    </div>

                    <footer className="mt-8 text-center">
                        <p className="text-xs font-medium text-slate-400">
                            © 2026 Graha Motor
                        </p>
                    </footer>
                </section>
            </main>

            <style>{`
                @keyframes shake {
                    0%, 100% { transform: translateX(0); }
                    10%, 30%, 50%, 70%, 90% { transform: translateX(-4px); }
                    20%, 40%, 60%, 80% { transform: translateX(4px); }
                }
            `}</style>
        </div>
    );
}
