import { type DBSchema, type IDBPDatabase, openDB } from 'idb';

interface ProductDB extends DBSchema {
    products: {
        key: number;
        value: Product;
        indexes: { 'by-sku': string };
    };
    meta: {
        key: string;
        value: { key: string; lastSync: string };
    };
}

interface Product {
    id: number;
    sku: string;
    name: string;
    category: string | null;
    image_url: string | null;
    volume_liter: number | null;
    stock: number;
    sell_price: number;
    workshop_price: number | null;
    display_name: string;
    min_stock?: number;
}

class ProductCache {
    private db: IDBPDatabase<ProductDB> | null = null;
    private DB_NAME = 'grahamesran-products';
    private DB_VERSION = 1;

    async init(): Promise<void> {
        this.db = await openDB<ProductDB>(this.DB_NAME, this.DB_VERSION, {
            upgrade(db) {
                const store = db.createObjectStore('products', { keyPath: 'id' });
                store.createIndex('by-sku', 'sku');
                db.createObjectStore('meta', { keyPath: 'key' });
            },
        });
    }

    async save(products: Product[]): Promise<void> {
        if (!this.db) await this.init();

        const tx = this.db!.transaction(['products', 'meta'], 'readwrite');
        const productStore = tx.objectStore('products');
        const metaStore = tx.objectStore('meta');

        // Clear and repopulate
        await productStore.clear();
        for (const product of products) {
            await productStore.put(product);
        }

        // Update last sync time
        await metaStore.put({ key: 'lastSync', lastSync: new Date().toISOString() });

        await tx.done;
    }

    async load(): Promise<Product[]> {
        if (!this.db) await this.init();
        const products = await this.db!.getAll('products');
        return products.length > 0 ? products : [];
    }

    async getLastSync(): Promise<Date | null> {
        if (!this.db) await this.init();
        const meta = await this.db!.get('meta', 'lastSync');
        return meta?.lastSync ? new Date(meta.lastSync) : null;
    }

    async clear(): Promise<void> {
        if (!this.db) await this.init();
        const tx = this.db!.transaction(['products', 'meta'], 'readwrite');
        await tx.objectStore('products').clear();
        await tx.objectStore('meta').clear();
        await tx.done;
    }
}

export const productCache = new ProductCache();
