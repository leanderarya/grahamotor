import {
    AppNotifications,
    notifyError,
    notifyWarning,
} from '@/Components/app-notifications';
import { ErrorBoundary } from '@/Components/ErrorBoundary';
import { Head, useForm, usePage } from '@inertiajs/react';
import {
    useCallback,
    useDeferredValue,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { SharedData } from '@/types';
import type { Product, CartItem, CashierSession, ActiveDraft } from '@/types/pos';
import { ProductCard } from '@/Components/pos/product-card';
import { CategoryChips } from '@/Components/pos/category-chips';
import { TopBar } from '@/Components/pos/top-bar';
import { OpenSessionModal } from '@/Components/pos/open-session-modal';
import { SettlementModal } from '@/Components/pos/settlement-modal';
import { getProductLabel } from '@/lib/format';
import { CheckoutPanel } from '@/Components/pos/checkout-panel';
import { PrintReceipt } from '@/Components/pos/print-receipt';
import { STORE_CONFIG } from '@/config/store';
import { useNetwork } from '@/hooks/useNetwork';
import { productCache } from '@/lib/product-cache';
import { isNative } from '@/lib/capacitor';
import { useVersionCheck } from '@/hooks/useVersionCheck';
import * as posService from '@/services/pos';
import type { ClosingReportData } from '@/lib/printer';

export default function TabletPOS({ products, cashierSession, activeDraft }: { products: Product[]; cashierSession: CashierSession | null; activeDraft?: ActiveDraft | null }) {
    const { auth, flash } = usePage<SharedData>().props;
    const [search, setSearch] = useState('');
    const [customerType, setCustomerType] = useState('general');
    const [showOpenSessionModal, setShowOpenSessionModal] =
        useState(!cashierSession);
    const [showSettlementModal, setShowSettlementModal] = useState(false);
    const [openingCash, setOpeningCash] = useState('');
    const [openingNotes, setOpeningNotes] = useState('');
    const [closingCashPhysical, setClosingCashPhysical] = useState('');
    const [closingNotes, setClosingNotes] = useState('');
    const [isOpeningSession, setIsOpeningSession] = useState(false);
    const [isClosingSession, setIsClosingSession] = useState(false);
    const [sessionState, setSessionState] = useState<CashierSession | null>(cashierSession);
    const [selectedCategory, setSelectedCategory] = useState<string | null>(null);
    const [draftId, setDraftId] = useState<number | null>(activeDraft?.id ?? null);
    const { data, setData, reset } = useForm<{ cart: CartItem[] }>({ cart: [] });
    const skipAutoSave = useRef(true); // skip initial render
    const saveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const { isOnline } = useNetwork();
    const [closingData, setClosingData] = useState<ClosingReportData | null>(null);
    const [showClosingReport, setShowClosingReport] = useState(false);
    const [autoSaveFailedCount, setAutoSaveFailedCount] = useState(0);
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
        if (activeDraftData && activeDraftData.transaction_items) {
            const restoredCart = activeDraftData.transaction_items.map(item => ({
                ...item.product,
                qty: item.quantity,
            })) as CartItem[];
            setData('cart', restoredCart);
        }
        // Setelah restore, izinkan auto-save
        const timer = setTimeout(() => { skipAutoSave.current = false; }, 500);
        return () => clearTimeout(timer);
    }, [activeDraftData, setData]);

    // Auto-save draft setiap keranjang berubah (debounce 800ms)
    useEffect(() => {
        if (skipAutoSave.current) return;
        if (!sessionState) return;

        if (saveTimerRef.current) clearTimeout(saveTimerRef.current);

        saveTimerRef.current = setTimeout(async () => {
            if (data.cart.length === 0) {
                // Keranjang kosong → hapus draft dari DB
                try {
                    await posService.clearDraft(draftId);
                    setDraftId(null);
                    setAutoSaveFailedCount(0);
                } catch (_) {}
                return;
            }

            // Ada item → auto-save
            try {
                const result = await posService.autoSaveDraft(
                    data.cart.map(item => ({ id: item.id, qty: item.qty })),
                    customerType,
                    draftId,
                );
                if (result?.draft_id) setDraftId(result.draft_id);
                setAutoSaveFailedCount(0);
            } catch (_) {
                setAutoSaveFailedCount(prev => {
                    const next = prev + 1;
                    // Show toast after 3 consecutive failures
                    if (next >= 3) {
                        notifyError('Auto-save gagal. Pastikan koneksi internet stabil.');
                    }
                    return next;
                });
            }
        }, 800);

        return () => {
            if (saveTimerRef.current) clearTimeout(saveTimerRef.current);
        };
    }, [data.cart, customerType, sessionState, draftId]);

    const hasOpenSession = Boolean(sessionState?.id);
    const isWorkshop = customerType === 'workshop';
    const deferredSearch = useDeferredValue(search);

    const productById = useMemo(() => {
        const map = new Map();
        for (const product of activeProducts) map.set(product.id, product);
        return map;
    }, [activeProducts]);

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

        let base = activeProducts;

        // Filter by category if selected
        if (selectedCategory && !query) {
            base = base.filter(
                (p) => (p.category || 'Lainnya') === selectedCategory,
            );
        }

        // Filter by search
        if (query) {
            base = base.filter(
                (product: Product) =>
                    product.name.toLowerCase().includes(query) ||
                    (product.sku || '').toLowerCase().includes(query) ||
                    (product.vehicles?.some((vehicle: { model?: string }) =>
                        (vehicle.model || '').toLowerCase().includes(query),
                    ) ?? false),
            );
            base = base.slice(0, 40);
        }

        return base;
    }, [activeProducts, deferredSearch, selectedCategory]);

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
        [data.cart, hasOpenSession, setData],
    );

    const updateQty = useCallback(
        (id: number, delta: number) => {
            const item = data.cart.find((i) => i.id === id);
            if (!item) return;

            const currentQty = Number(item.qty || 1);
            const nextQty = currentQty + delta;

            // If qty would go to 0 or below, remove the item
            if (nextQty <= 0) {
                setData('cart', data.cart.filter((i) => i.id !== id));
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
        [data.cart, productById, setData],
    );

    const removeItem = useCallback(
        (id: number) => {
            setData(
                'cart',
                data.cart.filter((item) => item.id !== id),
            );
        },
        [data.cart, setData],
    );

    const clearCart = useCallback(() => {
        setData('cart', []);
        // Langsung hapus draft dari DB, jangan lewat debounce
        posService.clearDraft(draftId)
            .then(() => setDraftId(null))
            .catch(() => {});
    }, [setData, draftId]);

    const handleOpenSession = useCallback(async () => {
        setIsOpeningSession(true);

        try {
            await posService.openSession(Number(openingCash || 0), openingNotes);
            setSessionState({
                id: Date.now(),
                opening_cash: Number(openingCash || 0),
                cash_sales_total: 0,
                non_cash_sales_total: 0,
                transactions_count: 0,
                opened_at: new Date().toISOString(),
            });
            setOpeningCash('');
            setOpeningNotes('');
            setShowOpenSessionModal(false);
        } catch (error: any) {
            notifyError(error?.opening_cash || error?.message || 'Gagal membuka kasir.');
        } finally {
            setIsOpeningSession(false);
        }
    }, [openingCash, openingNotes]);

    const handleCloseSession = useCallback(async () => {
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
                setClosingData(backendClosingData as unknown as ClosingReportData);
            }
            setSessionState(null);
            setClosingCashPhysical('');
            setClosingNotes('');
            setShowSettlementModal(false);
            setShowOpenSessionModal(true);
            setShowClosingReport(true);
            reset();
            setSearch('');
        } catch (error: any) {
            notifyError(error?.closing_cash_physical || error?.message || 'Gagal menutup kasir.');
        } finally {
            setIsClosingSession(false);
        }
    }, [data.cart.length, closingCashPhysical, closingNotes, reset, setSearch]);

    const handleSaveDraft = useCallback(async () => {
        if (data.cart.length === 0) return;

        try {
            await posService.saveDraft(
                data.cart.map(item => ({ id: item.id, qty: item.qty })),
                customerType,
                draftId,
            );
            // posService.saveDraft handles both web (Inertia redirect) and native paths
        } catch (error: any) {
            notifyError(error?.message || 'Gagal menyimpan draft.');
        }
    }, [data.cart, customerType, draftId]);

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
                    hasOpenSession
                        ? setShowSettlementModal(true)
                        : setShowOpenSessionModal(true)
                }
            />

            {/* Two-Panel Layout */}
            <div className="flex flex-1 overflow-hidden">
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
                        <div className="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-5">
                            {displayProducts.map((product) => {
                                const cartItem = data.cart.find((item) => item.id === product.id);
                                return (
                                    <ProductCard
                                        key={product.id}
                                        product={product}
                                        customerType={customerType}
                                        onAddToCart={addToCart}
                                        inCartQty={cartItem?.qty || 0}
                                    />
                                );
                            })}

                            {displayProducts.length === 0 && (
                                <p className="col-span-full py-12 text-center text-sm text-slate-400">
                                    Barang tidak ditemukan.
                                </p>
                            )}
                        </div>
                    </div>
                </main>

                {/* Right Panel: Checkout — always visible */}
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
                    hasOpenSession={hasOpenSession}
                    customerType={customerType}
                    onCustomerTypeChange={setCustomerType}
                    onSaveDraft={handleSaveDraft}
                />
            </div>

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
