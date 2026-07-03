import { isNative } from '@/lib/capacitor';

class SecureStorage {
    private capacitorStorage: any = null;
    private initialized = false;

    async init(): Promise<void> {
        if (!isNative() || this.initialized) return;

        try {
            const { SecureStoragePlugin } = await import('capacitor-secure-storage-plugin');
            this.capacitorStorage = SecureStoragePlugin;
            this.initialized = true;
        } catch (error) {
            console.warn('SecureStorage not available, falling back to localStorage');
            this.initialized = true;
        }
    }

    async get(key: string): Promise<string | null> {
        if (!isNative() || !this.capacitorStorage) {
            return localStorage.getItem(key);
        }

        try {
            const result = await this.capacitorStorage.get({ key });
            return result.value;
        } catch {
            return localStorage.getItem(key);
        }
    }

    async set(key: string, value: string): Promise<void> {
        if (!isNative() || !this.capacitorStorage) {
            localStorage.setItem(key, value);
            return;
        }

        try {
            await this.capacitorStorage.set({ key, value });
        } catch {
            localStorage.setItem(key, value);
        }
    }

    async remove(key: string): Promise<void> {
        if (!isNative() || !this.capacitorStorage) {
            localStorage.removeItem(key);
            return;
        }

        try {
            await this.capacitorStorage.remove({ key });
        } catch {
            localStorage.removeItem(key);
        }
    }
}

export const secureStorage = new SecureStorage();
