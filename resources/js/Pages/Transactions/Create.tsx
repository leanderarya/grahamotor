import {
    AppNotifications,
    notifyError,
    notifySuccess,
    notifyWarning,
} from '@/Components/app-notifications';
import { ErrorBoundary } from '@/Components/ErrorBoundary';
import { CategoryChips } from '@/Components/pos/category-chips';
import { CheckoutPanel } from '@/Components/pos/checkout-panel';
import { MobileBottomBar } from '@/Components/pos/mobile-bottom-bar';
import { OpenSessionModal } from '@/Components/pos/open-session-modal';
import { PrintReceipt } from '@/Components/pos/print-receipt';
import { ProductCard } from '@/Components/pos/product-card';
import { SettlementModal } from '@/Components/pos/settlement-modal';
import { TopBar } from '@/Components/pos/top-bar';
import { STORE_CONFIG } from '@/config/store';
import { useVersionCheck } from '@/hooks/useVersionCheck';
import { isNative } from '@/lib/capacitor';
import { getProductLabel } from '@/lib/format';
import { offlineQueue } from '@/lib/offline-queue';
import type { ClosingReportData } from '@/lib/printer';
import { productCache } from '@/lib/product-cache';
import * as posService from '@/services/pos';
import type { SharedData } from '@/types';
import type {
    ActiveDraft,
    CartItem,
    CashierSession,
    Product,
} from '@/types/pos';
import { Head, useForm, usePage } from '@inertiajs/react';
import {
    useCallback,
    useDeferredValue,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';

function getErrorMessage(
    error: unknown,
    field: string,
    fallback: string,
): string {
    if (typeof error !== 'object' || error === null) {
        return error instanceof Error && error.message ? error.message : fallback;
    }

    const details = error as Record<string, unknown>;
    const fieldMessage = details[field];
    if (typeof fieldMessage === 'string') return fieldMessage;
    if (Array.isArray(fieldMessage) && typeof fieldMessage[0] === 'string') {
        return fieldMessage[0];
    }

    return typeof details.message === 'string' && details.message
        ? details.message
        : fallback;
}

export default function TabletPOS({
    products,
    cashierSession,
    activeDraft,
}: {
    products: Product[];
    cashierSession: CashierSession | null;
    activeDraft?: ActiveDraft | null;
}) {
    const { auth, flash } = usePage<SharedData>().props;
    const [search, setSearch] = useState('');
    const [customerType, setCustomerType] = useState('general');
    const [showOpenSessionModal, setShowOpenSessionModal] =
        useState(!cashierSession);
    const [showSettlementModal, setShowSettlementModal] = useState(false);
    const [showMobileCheckout, setShowMobileCheckout] = useState(false);
    const [openingCash, setOpeningCash] = useState('');
    const [openingNotes, setOpeningNotes] = useState('');
    const [closingCashPhysical, setClosingCashPhysical] = useState('');
    const [closingNotes, setClosingNotes] = useState('');
    const [isOpeningSession, setIsOpeningSession] = useState(false);
    const [isClosingSession, setIsClosingSession] = useState(false);
    const [sessionState, setSessionState] = useState<CashierSession | null>(
        cashierSession,
    );
    const [selectedCategory, setSelectedCategory] = useState<string | null>(
        null,
    );
    const [draftId, setDraftId] = useState<number | null>(
        activeDraft?.id ?? null,
    );
    const { data, setData, reset } = useForm<{ cart: CartItem[] }>({
        cart: [],
    });
    const skipAutoSave = useRef(true); // skip initial render
    const saveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const [closingData, setClosingData] = useState<ClosingReportData | null>(
        null,
    );
    const [showClosingReport, setShowClosingReport] = useState(false);
    const autoSaveFailedCount = useRef(0);
    const [offlineProducts, setOfflineProducts] = useState<Product[]>([]);
    const { needsUpdate, latestVersion } = useVersionCheck();

    // Data sources
    const activeProducts = products.length > 0 ? products : offlineProducts;
    const activeDraftData = activeDraft;

    // Offline product cache (Capacitor only)
    useEffect(() => {
        if (!isNative()) return;

        const loadProducts = async () => {
            try {
                const result = await posService.fetchAndCacheProducts();
                if (result.length > 0) {
                    setOfflineProducts(result as Product[]);
                }
            } catch {
                // Network failed, load from cache
                const cached = await productCache.load();
                setOfflineProducts(cached as Product[]);
            }
        };

        loadProducts();
    }, []);

    useEffect(() => {
        setSessionState(cashierSession ?? null);
        setShowOpenSessionModal(!cashierSession);
    }, [cashierSession]);

    useEffect(() => {
        const cutoffAt = sessionState?.overdue_at
            ? Date.parse(sessionState.overdue_at)
            : Number.NaN;
        if (
            !Number.isFinite(cutoffAt) ||
            sessionState?.is_overdue ||
            cutoffAt > 2_147_483_647 + Date.now()
        ) {
            return;
        }

        const lockSession = () => {
            setSessionState((current) =>
                current ? { ...current, is_overdue: true } : current,
            );
        };
        const delay = cutoffAt - Date.now();
        if (delay <= 0) {
            lockSession();
            return;
        }

        const timer = window.setTimeout(lockSession, delay);
        return () => window.clearTimeout(timer);
    }, [sessionState?.is_overdue, sessionState?.overdue_at]);

    useEffect(() => {
        return offlineQueue.autoSyncOnNetworkRestore(
            (payload) => posService.processPayment(payload),
            {
                userId: auth.user.id,
                sessionId: sessionState?.id ?? null,
                isOverdue: Boolean(sessionState?.is_overdue),
            },
            ({ sent, failed }) => {
                if (sent > 0) {
                    notifySuccess(
                        `${sent} transaksi offline berhasil dikirim.`,
                        'Sinkronisasi selesai',
                    );
                }

                if (failed > 0) {
                    notifyError(
                        `${failed} transaksi offline tidak dapat dikonfirmasi. Jangan input ulang sebelum Owner memeriksa riwayat transaksi.`,
                        'Periksa transaksi offline',
                    );
                }
            },
        );
    }, [auth.user.id, sessionState?.id, sessionState?.is_overdue]);

    useEffect(() => {
        if (activeDraftData && activeDraftData.transaction_items) {
            const restoredCart = activeDraftData.transaction_items.map(
                (item) => ({
                    ...item.product,
                    qty: item.quantity,
                }),
            ) as CartItem[];
            setData('cart', restoredCart);
        }
        // Setelah restore, izinkan auto-save
        const timer = setTimeout(() => {
            skipAutoSave.current = false;
        }, 500);
        return () => clearTimeout(timer);
    }, [activeDraftData, setData]);

    // Auto-save draft setiap keranjang berubah (debounce 800ms)
    useEffect(() => {
        if (skipAutoSave.current) return;
        if (!sessionState) return;
        if (sessionState.is_overdue) return;

        if (saveTimerRef.current) clearTimeout(saveTimerRef.current);

        saveTimerRef.current = setTimeout(async () => {
            if (data.cart.length === 0) {
                // Keranjang kosong → hapus draft dari DB
                try {
                    await posService.clearDraft(draftId, sessionState.id ?? null);
                    setDraftId(null);
                    autoSaveFailedCount.current = 0;
                } catch {
                    notifyError(
                        'Draft belum dapat dihapus. Coba lagi setelah koneksi pulih.',
                    );
                }
                return;
            }

            // Ada item → auto-save
            try {
                const result = await posService.autoSaveDraft(
                    data.cart.map((item) => ({ id: item.id, qty: item.qty })),
                    customerType,
                    draftId,
                    sessionState.id ?? null,
                );
                if (result?.draft_id) setDraftId(result.draft_id);
                autoSaveFailedCount.current = 0;
            } catch {
                autoSaveFailedCount.current += 1;
                if (autoSaveFailedCount.current >= 3) {
                    notifyError(
                        'Auto-save gagal. Pastikan koneksi internet stabil.',
                    );
                }
            }
        }, 800);

        return () => {
            if (saveTimerRef.current) clearTimeout(saveTimerRef.current);
        };
    }, [data.cart, customerType, sessionState, draftId]);

    const hasOpenSession = Boolean(sessionState?.id);
    const isShiftOverdue = Boolean(sessionState?.is_overdue);
    const sessionCanTransact = hasOpenSession && !isShiftOverdue;
    const isWorkshop = customerType === 'workshop';
    const deferredSearch = useDeferredValue(search);

    const productById = useMemo(() => {
        const map = new Map();
        for (const product of activeProducts) map.set(product.id, product);
        return map;
    }, [activeProducts]);

    const cartQtyById = useMemo(
        () =>
            new Map(data.cart.map((item) => [item.id, Number(item.qty) || 0])),
        [data.cart],
    );

    const searchableProducts = useMemo(
        () =>
            activeProducts.map((product) => ({
                product,
                searchText: [
                    product.name,
                    product.sku,
                    ...(product.vehicles?.map((vehicle) => vehicle.model) ??
                        []),
                ]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase(),
            })),
        [activeProducts],
    );

    const categoryGroups = useMemo(() => {
        const groups: Record<string, number> = {};
        activeProducts.forEach((p) => {
            const cat = p.category || 'Lainnya';
            groups[cat] = (groups[cat] || 0) + 1;
        });
        return Object.entries(groups)
            .sort(([a], [b]) => a.localeCompare(b))
            .map(([name, count]) => ({ name, count }));
    }, [activeProducts]);

    const displayProducts = useMemo(() => {
        const query = deferredSearch.trim().toLowerCase();

        let base = searchableProducts;

        // Filter by category if selected
        if (selectedCategory && !query) {
            base = base.filter(
                ({ product }) =>
                    (product.category || 'Lainnya') === selectedCategory,
            );
        }

        // Filter by search
        if (query) {
            base = base.filter(({ searchText }) => searchText.includes(query));
            base = base.slice(0, 40);
        }

        return base.map(({ product }) => product);
    }, [searchableProducts, deferredSearch, selectedCategory]);

    useEffect(() => {
        if (deferredSearch.trim()) {
            setSelectedCategory(null);
        }
    }, [deferredSearch]);

    const getProductPrice = useCallback(
        (product: Product | CartItem | null | undefined) => {
            const workshopPrice = Number(product?.workshop_price) || 0;
            const sellPrice = Number(product?.sell_price) || 0;
            return customerType === 'workshop' && workshopPrice > 0
                ? workshopPrice
                : sellPrice;
        },
        [customerType],
    );

    const totalAmount = useMemo(
        () =>
            data.cart.reduce((sum, item) => {
                const product = productById.get(item.id);
                return (
                    sum +
                    getProductPrice(product || item) * (Number(item.qty) || 0)
                );
            }, 0),
        [data.cart, getProductPrice, productById],
    );

    const totalQty = useMemo(
        () => data.cart.reduce((sum, item) => sum + (Number(item.qty) || 0), 0),
        [data.cart],
    );

    const expectedCash =
        Number(sessionState?.opening_cash || 0) +
        Number(sessionState?.cash_sales_total || 0);
    const settlementDifference =
        Number(closingCashPhysical || 0) - expectedCash;
    const settlementStatus =
        settlementDifference === 0
            ? 'balance'
            : settlementDifference < 0
              ? 'minus'
              : 'over';

    const addToCart = useCallback(
        (product: Product) => {
            if (isShiftOverdue) {
                notifyWarning(
                    'Shift melewati batas 00.00 WIB. Hubungi Admin atau Owner untuk menutup shift.',
                    'Transaksi dihentikan',
                );
                return;
            }

            if (!hasOpenSession) {
                setShowOpenSessionModal(true);
                return;
            }

            const existing = data.cart.find((item) => item.id === product.id);
            const stock = Number(product.stock) || 0;

            if (existing && Number(existing.qty || 0) + 1 > stock) {
                notifyWarning(
                    `Stok ${getProductLabel(product)} tinggal ${stock}.`,
                    'Stok terbatas',
                );
                return;
            }

            if (existing) {
                setData(
                    'cart',
                    data.cart.map((item) =>
                        item.id === product.id
                            ? { ...item, qty: Number(item.qty || 0) + 1 }
                            : item,
                    ),
                );
                return;
            }

            setData('cart', [...data.cart, { ...product, qty: 1 } as CartItem]);
        },
        [data.cart, hasOpenSession, isShiftOverdue, setData],
    );

    const updateQty = useCallback(
        (id: number, delta: number) => {
            if (isShiftOverdue) return;

            const item = data.cart.find((i) => i.id === id);
            if (!item) return;

            const currentQty = Number(item.qty || 1);
            const nextQty = currentQty + delta;

            // If qty would go to 0 or below, remove the item
            if (nextQty <= 0) {
                setData(
                    'cart',
                    data.cart.filter((i) => i.id !== id),
                );
                return;
            }

            const stock = Number(productById.get(id)?.stock || 0);
            if (nextQty > stock) {
                notifyWarning(
                    `Jumlah maksimal untuk item ini adalah ${stock}.`,
                    'Melebihi stok',
                );
                return;
            }

            setData(
                'cart',
                data.cart.map((i) =>
                    i.id === id ? { ...i, qty: nextQty } : i,
                ),
            );
        },
        [data.cart, isShiftOverdue, productById, setData],
    );

    const removeItem = useCallback(
        (id: number) => {
            if (isShiftOverdue) return;

            setData(
                'cart',
                data.cart.filter((item) => item.id !== id),
            );
        },
        [data.cart, isShiftOverdue, setData],
    );

    const clearCart = useCallback(() => {
        if (isShiftOverdue) return;

        setData('cart', []);
        // Langsung hapus draft dari DB, jangan lewat debounce
        posService
            .clearDraft(draftId, sessionState?.id ?? null)
            .then(() => setDraftId(null))
            .catch(() => {});
    }, [setData, draftId, isShiftOverdue, sessionState?.id]);

    const handleOpenSession = useCallback(async () => {
        setIsOpeningSession(true);

        try {
            const openedSession = await posService.openSession(
                Number(openingCash || 0),
                openingNotes,
            );
            if (!openedSession) {
                throw new Error('Server tidak mengembalikan sesi kasir yang baru dibuka.');
            }

            setSessionState(openedSession);
            setOpeningCash('');
            setOpeningNotes('');
            setShowOpenSessionModal(false);
        } catch (error: unknown) {
            notifyError(
                getErrorMessage(
                    error,
                    'opening_cash',
                    'Gagal membuka kasir.',
                ),
            );
        } finally {
            setIsOpeningSession(false);
        }
    }, [openingCash, openingNotes]);

    const handleCloseSession = useCallback(async () => {
        if (isShiftOverdue) {
            notifyWarning(
                'Shift melewati batas 00.00 WIB. Minta Admin atau Owner menutup shift.',
                'Override diperlukan',
            );
            return;
        }

        if (data.cart.length > 0) {
            notifyWarning(
                'Kosongkan keranjang sebelum tutup kasir.',
                'Keranjang masih terisi',
            );
            return;
        }

        setIsClosingSession(true);

        try {
            const backendClosingData = await posService.closeSession(
                Number(closingCashPhysical || 0),
                closingNotes,
            );
            if (backendClosingData) {
                setClosingData(
                    backendClosingData as unknown as ClosingReportData,
                );
            }
            setSessionState(null);
            setClosingCashPhysical('');
            setClosingNotes('');
            setShowSettlementModal(false);
            setShowOpenSessionModal(true);
            setShowClosingReport(true);
            reset();
            setSearch('');
        } catch (error: unknown) {
            notifyError(
                getErrorMessage(
                    error,
                    'closing_cash_physical',
                    'Gagal menutup kasir.',
                ),
            );
        } finally {
            setIsClosingSession(false);
        }
    }, [data.cart.length, closingCashPhysical, closingNotes, isShiftOverdue, reset, setSearch]);

    const handleSaveDraft = useCallback(async () => {
        if (isShiftOverdue) {
            notifyWarning(
                'Shift melewati batas 00.00 WIB. Draft baru tidak dapat disimpan.',
                'Transaksi dihentikan',
            );
            return;
        }

        if (data.cart.length === 0) return;

        try {
            await posService.saveDraft(
                data.cart.map((item) => ({ id: item.id, qty: item.qty })),
                customerType,
                draftId,
                sessionState?.id ?? null,
            );
            // posService.saveDraft handles both web (Inertia redirect) and native paths
        } catch (error: unknown) {
            notifyError(
                getErrorMessage(error, 'message', 'Gagal menyimpan draft.'),
            );
        }
    }, [data.cart, customerType, draftId, isShiftOverdue, sessionState?.id]);

    return (
        <ErrorBoundary>
            <div className="flex h-screen flex-col bg-white">
                <Head title="Kasir - Graha Motor" />
                <AppNotifications flash={flash} />

                {/* Update banner */}
                {needsUpdate && (
                    <div className="bg-amber-500 px-4 py-2 text-center text-sm font-semibold text-white">
                        Versi baru tersedia (v{latestVersion}).
                        <button
                            onClick={() => window.location.reload()}
                            className="ml-2 underline"
                        >
                            Muat Ulang
                        </button>
                    </div>
                )}

                {/* Top Bar */}
                <TopBar
                    search={search}
                    onSearchChange={setSearch}
                    hasOpenSession={hasOpenSession}
                    userName={auth?.user?.name || ''}
                    onSettlementClick={() =>
                        isShiftOverdue
                            ? notifyWarning(
                                  'Shift melewati batas 00.00 WIB. Minta Admin atau Owner menutup shift.',
                                  'Override diperlukan',
                              )
                            : hasOpenSession
                            ? setShowSettlementModal(true)
                            : setShowOpenSessionModal(true)
                    }
                />

                {isShiftOverdue && (
                    <div
                        role="status"
                        className="border-b border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-950"
                    >
                        Shift melewati batas 00.00 WIB. Penjualan dan draft
                        dihentikan. Hubungi Admin atau Owner untuk menutup
                        shift.
                    </div>
                )}

                {/* Product list and desktop checkout */}
                <div className="flex min-h-0 flex-1 overflow-hidden pb-24 lg:pb-0">
                    {/* Left Panel: Category + Products */}
                    <main className="flex flex-1 flex-col overflow-hidden">
                        {/* Category Chips */}
                        <div className="shrink-0 border-b border-slate-200 px-4 py-2.5">
                            <CategoryChips
                                groups={categoryGroups}
                                selected={selectedCategory}
                                onSelect={setSelectedCategory}
                            />
                        </div>

                        {/* Product Grid */}
                        <div className="flex-1 overflow-y-auto p-4">
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                                {displayProducts.map((product) => (
                                    <ProductCard
                                        key={product.id}
                                        product={product}
                                        customerType={customerType}
                                        onAddToCart={addToCart}
                                        inCartQty={
                                            cartQtyById.get(product.id) || 0
                                        }
                                    />
                                ))}

                                {displayProducts.length === 0 && (
                                    <p className="col-span-full py-12 text-center text-sm text-slate-400">
                                        Barang tidak ditemukan.
                                    </p>
                                )}
                            </div>
                        </div>
                    </main>

                    {/* Desktop checkout */}
                    <div className="hidden lg:flex lg:w-[30%] lg:min-w-[300px]">
                        <CheckoutPanel
                            cart={data.cart}
                            productById={productById}
                            getProductPrice={getProductPrice}
                            clearCart={clearCart}
                            removeItem={removeItem}
                            updateQty={updateQty}
                            totalAmount={totalAmount}
                            totalQty={totalQty}
                            isWorkshop={isWorkshop}
                            hasOpenSession={sessionCanTransact}
                            customerType={customerType}
                            onCustomerTypeChange={setCustomerType}
                            onSaveDraft={handleSaveDraft}
                        />
                    </div>
                </div>

                <MobileBottomBar
                    cartCount={data.cart.length}
                    totalAmount={totalAmount}
                    hasOpenSession={hasOpenSession}
                    onToggleCheckout={() => setShowMobileCheckout(true)}
                    onSessionButtonClick={() =>
                        isShiftOverdue
                            ? notifyWarning(
                                  'Shift melewati batas 00.00 WIB. Minta Admin atau Owner menutup shift.',
                                  'Override diperlukan',
                              )
                            : hasOpenSession
                            ? setShowSettlementModal(true)
                            : setShowOpenSessionModal(true)
                    }
                />

                {showMobileCheckout && (
                    <div
                        className="fixed inset-0 z-40 flex items-end bg-black/40 lg:hidden"
                        onClick={() => setShowMobileCheckout(false)}
                    >
                        <div
                            className="w-full"
                            onClick={(event) => event.stopPropagation()}
                        >
                            <CheckoutPanel
                                mobile
                                onClose={() => setShowMobileCheckout(false)}
                                cart={data.cart}
                                productById={productById}
                                getProductPrice={getProductPrice}
                                clearCart={clearCart}
                                removeItem={removeItem}
                                updateQty={updateQty}
                                totalAmount={totalAmount}
                                totalQty={totalQty}
                                isWorkshop={isWorkshop}
                                hasOpenSession={sessionCanTransact}
                                customerType={customerType}
                                onCustomerTypeChange={setCustomerType}
                                onSaveDraft={handleSaveDraft}
                            />
                        </div>
                    </div>
                )}

                {/* Modals */}
                <OpenSessionModal
                    show={!hasOpenSession && showOpenSessionModal}
                    onClose={() => setShowOpenSessionModal(false)}
                    openingCash={openingCash}
                    openingNotes={openingNotes}
                    onOpeningCashChange={setOpeningCash}
                    onOpeningNotesChange={setOpeningNotes}
                    onSubmit={handleOpenSession}
                    isOpeningSession={isOpeningSession}
                />

                <SettlementModal
                    show={showSettlementModal && hasOpenSession}
                    onClose={() => setShowSettlementModal(false)}
                    sessionState={sessionState}
                    closingCash={closingCashPhysical}
                    closingNotes={closingNotes}
                    onClosingCashChange={setClosingCashPhysical}
                    onClosingNotesChange={setClosingNotes}
                    onSubmit={handleCloseSession}
                    isClosingSession={isClosingSession}
                    expectedCash={expectedCash}
                    settlementDifference={settlementDifference}
                    settlementStatus={settlementStatus}
                    closingData={closingData}
                    showClosingReport={showClosingReport}
                    onCloseClosingReport={() => setShowClosingReport(false)}
                />

                <PrintReceipt
                    receiptData={null}
                    storeName={STORE_CONFIG.name}
                    storeAddress={STORE_CONFIG.address}
                    storePhone={STORE_CONFIG.phone}
                />
            </div>
        </ErrorBoundary>
    );
}
