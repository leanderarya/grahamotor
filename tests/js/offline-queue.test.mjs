import assert from 'node:assert/strict';
import path from 'node:path';
import { after, before, beforeEach, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

const projectRoot = fileURLToPath(new URL('../..', import.meta.url));
const records = new Map();

class FakeTransaction {
    pending = 0;
    oncomplete = null;
    onerror = null;

    objectStore() {
        return {
            add: (item) =>
                this.enqueue(() => records.set(item.id, structuredClone(item))),
            delete: (id) => this.enqueue(() => records.delete(id)),
            get: (id) => this.request(() => records.get(id)),
            getAll: () => this.request(() => [...records.values()]),
            put: (item) =>
                this.enqueue(() => records.set(item.id, structuredClone(item))),
        };
    }

    request(read) {
        const request = {
            result: undefined,
            onsuccess: null,
        };

        this.enqueue(() => {
            request.result = read();
            request.onsuccess?.({ target: request });
        });

        return request;
    }

    enqueue(operation) {
        this.pending += 1;

        queueMicrotask(() => {
            try {
                operation();
            } catch (error) {
                this.onerror?.({ target: this, error });
            } finally {
                this.pending -= 1;
                if (this.pending === 0) {
                    queueMicrotask(() => {
                        if (this.pending === 0)
                            this.oncomplete?.({ target: this });
                    });
                }
            }
        });
    }
}

const database = {
    objectStoreNames: { contains: () => true },
    transaction: () => new FakeTransaction(),
};

globalThis.indexedDB = {
    open() {
        const request = {
            result: database,
            onupgradeneeded: null,
            onsuccess: null,
        };

        queueMicrotask(() => {
            request.onupgradeneeded?.({ target: request });
            queueMicrotask(() => request.onsuccess?.({ target: request }));
        });

        return request;
    },
};

let vite;
let offlineQueue;

before(async () => {
    vite = await createServer({
        appType: 'custom',
        configFile: false,
        logLevel: 'silent',
        plugins: [],
        root: projectRoot,
        server: { middlewareMode: true, ws: false },
    });

    ({ offlineQueue } = await vite.ssrLoadModule(
        path.join(projectRoot, 'resources/js/lib/offline-queue.ts'),
    ));
});

after(async () => {
    await vite?.close();
});

beforeEach(() => records.clear());

function queuedTransaction(overrides = {}) {
    return {
        user_id: 7,
        cashier_session_id: 21,
        cart: [{ id: 3, qty: 1 }],
        payment_method: 'cash',
        amount_paid: 80000,
        change_amount: 0,
        customer_type: 'general',
        draft_id: null,
        ...overrides,
    };
}

test('overdue transactions are failed without being sent', async () => {
    const id = await offlineQueue.add(queuedTransaction());
    const post = async () =>
        assert.fail('Overdue transaction must not be posted');

    const result = await offlineQueue.sync(post, {
        userId: 7,
        sessionId: 21,
        isOverdue: true,
    });

    assert.deepEqual(result, { sent: 0, failed: 1, skipped: 0 });
    assert.equal(records.get(id).status, 'failed');
    assert.match(records.get(id).error, /melewati batas 00\.00 WIB/);
});

test('transactions from a different session are failed without being sent', async () => {
    const id = await offlineQueue.add(queuedTransaction());
    const post = async () =>
        assert.fail('Old-session transaction must not be posted');

    const result = await offlineQueue.sync(post, {
        userId: 7,
        sessionId: 22,
        isOverdue: false,
    });

    assert.deepEqual(result, { sent: 0, failed: 1, skipped: 0 });
    assert.equal(records.get(id).status, 'failed');
    assert.match(records.get(id).error, /Shift asal sudah tidak aktif/);
});

test('transactions from another user are skipped and remain pending', async () => {
    const id = await offlineQueue.add(queuedTransaction({ user_id: 8 }));
    const post = async () =>
        assert.fail('Another user transaction must not be posted');

    const result = await offlineQueue.sync(post, {
        userId: 7,
        sessionId: 21,
        isOverdue: false,
    });

    assert.deepEqual(result, { sent: 0, failed: 0, skipped: 1 });
    assert.equal(records.get(id).status, 'pending');
});

test('a transaction for the active user and session is posted then cleared', async () => {
    await offlineQueue.add(queuedTransaction());
    const posted = [];

    const result = await offlineQueue.sync(async (data) => posted.push(data), {
        userId: 7,
        sessionId: 21,
        isOverdue: false,
    });

    assert.deepEqual(result, { sent: 1, failed: 0, skipped: 0 });
    assert.equal(posted.length, 1);
    assert.equal(posted[0].cashier_session_id, 21);
    assert.equal(records.size, 0);
});
