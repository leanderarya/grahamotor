import { isNative } from './capacitor';

const DB_NAME = 'kasir_offline';
const STORE_NAME = 'transactions';
const DB_VERSION = 1;

interface QueuedTransaction {
    id: string; // UUID
    user_id: number;
    cashier_session_id: number;
    cart: Array<{ id: number; qty: number }>;
    payment_method: string;
    amount_paid: number;
    change_amount: number;
    customer_type: string;
    draft_id: number | null;
    timestamp: number;
    status: 'pending' | 'sent' | 'failed';
    error?: string;
}

interface SyncContext {
    userId: number;
    sessionId: number | null;
    isOverdue: boolean;
}

let syncInProgress = false;

function openDB(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains(STORE_NAME)) {
                db.createObjectStore(STORE_NAME, { keyPath: 'id' });
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function generateId(): string {
    return Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
}

export const offlineQueue = {
    async add(
        transaction: Omit<QueuedTransaction, 'id' | 'timestamp' | 'status'>,
    ): Promise<string> {
        const db = await openDB();
        const id = generateId();

        const item: QueuedTransaction = {
            ...transaction,
            id,
            timestamp: Date.now(),
            status: 'pending',
        };

        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            tx.objectStore(STORE_NAME).add(item);
            tx.oncomplete = () => resolve(id);
            tx.onerror = () => reject(tx.error);
        });
    },

    async getPending(): Promise<QueuedTransaction[]> {
        const db = await openDB();

        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readonly');
            const store = tx.objectStore(STORE_NAME);
            const request = store.getAll();

            request.onsuccess = () => {
                const all = request.result as QueuedTransaction[];
                resolve(all.filter((item) => item.status === 'pending'));
            };
            request.onerror = () => reject(request.error);
        });
    },

    async markSent(id: string): Promise<void> {
        const db = await openDB();

        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            const request = store.get(id);

            request.onsuccess = () => {
                const item = request.result as QueuedTransaction;
                if (item) {
                    item.status = 'sent';
                    store.put(item);
                }
            };
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    },

    async markFailed(id: string, error: string): Promise<void> {
        const db = await openDB();

        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            const request = store.get(id);

            request.onsuccess = () => {
                const item = request.result as QueuedTransaction;
                if (item) {
                    item.status = 'failed';
                    item.error = error;
                    store.put(item);
                }
            };
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    },

    async clearSent(): Promise<void> {
        const db = await openDB();

        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            const request = store.getAll();

            request.onsuccess = () => {
                const all = request.result as QueuedTransaction[];
                for (const item of all) {
                    if (item.status === 'sent') {
                        store.delete(item.id);
                    }
                }
            };
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    },

    async sync(
        postFn: (data: {
            cart: Array<{ id: number; qty: number }>;
            payment_method: string;
            amount_paid: number;
            change_amount: number;
            customer_type: string;
            draft_id: number | null;
            cashier_session_id: number;
        }) => Promise<unknown>,
        context: SyncContext,
    ): Promise<{ sent: number; failed: number; skipped: number }> {
        if (syncInProgress) {
            return { sent: 0, failed: 0, skipped: 0 };
        }

        syncInProgress = true;
        let sent = 0;
        let failed = 0;
        let skipped = 0;

        try {
            const pending = await this.getPending();

            for (const item of pending) {
                if (
                    !Number.isInteger(item.user_id) ||
                    !Number.isInteger(item.cashier_session_id)
                ) {
                    await this.markFailed(
                        item.id,
                        'Identitas kasir atau shift tidak tersimpan. Transaksi tidak dialihkan.',
                    );
                    failed++;
                    continue;
                }

                if (item.user_id !== context.userId) {
                    skipped++;
                    continue;
                }

                if (context.isOverdue) {
                    await this.markFailed(
                        item.id,
                        'Shift melewati batas 00.00 WIB. Transaksi offline tidak dialihkan ke shift berikutnya.',
                    );
                    failed++;
                    continue;
                }

                if (
                    context.sessionId === null ||
                    item.cashier_session_id !== context.sessionId
                ) {
                    await this.markFailed(
                        item.id,
                        'Shift asal sudah tidak aktif. Transaksi offline tidak dialihkan.',
                    );
                    failed++;
                    continue;
                }

                try {
                    await postFn({
                        cart: item.cart,
                        payment_method: item.payment_method,
                        amount_paid: item.amount_paid,
                        change_amount: item.change_amount,
                        customer_type: item.customer_type,
                        draft_id: item.draft_id,
                        cashier_session_id: item.cashier_session_id,
                    });
                    await this.markSent(item.id);
                    sent++;
                } catch (error: unknown) {
                    const message =
                        error instanceof Error
                            ? error.message
                            : typeof error === 'object' &&
                                error !== null &&
                                'message' in error &&
                                typeof error.message === 'string'
                              ? error.message
                              : 'Sync failed';
                    await this.markFailed(item.id, message);
                    failed++;
                }
            }

            if (sent > 0) {
                await this.clearSent();
            }

            return { sent, failed, skipped };
        } finally {
            syncInProgress = false;
        }
    },

    /**
     * Auto-sync pending transactions when device comes back online.
     * Call this once on app startup.
     */
    autoSyncOnNetworkRestore(
        postFn: (data: {
            cart: Array<{ id: number; qty: number }>;
            payment_method: string;
            amount_paid: number;
            change_amount: number;
            customer_type: string;
            draft_id: number | null;
            cashier_session_id: number;
        }) => Promise<unknown>,
        context: SyncContext,
        onResult?: (result: { sent: number; failed: number; skipped: number }) => void,
    ): () => void {
        if (!isNative()) return () => {};

        let running = false;
        const syncPending = async () => {
            if (running) return;
            running = true;
            try {
                const result = await this.sync(postFn, context);
                onResult?.(result);
            } catch (err) {
                console.error('[offlineQueue] Auto-sync failed:', err);
            } finally {
                running = false;
            }
        };

        window.addEventListener('online', syncPending);
        if (navigator.onLine) void syncPending();

        return () => window.removeEventListener('online', syncPending);
    },
};
