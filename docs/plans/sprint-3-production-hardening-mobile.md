# Sprint 3: Production Hardening & Mobile

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve mobile (Capacitor) experience with offline product caching, secure token storage, version checking, and security headers.

**Architecture:** Cache products in IndexedDB for offline access, use Capacitor Secure Storage for sensitive data, implement version checking for app updates, and add Content-Security-Policy headers.

**Tech Stack:** Laravel 12, React 19, Capacitor 8, IndexedDB

## Global Constraints

- Offline product cache must sync when connection is restored
- Secure storage must fall back to localStorage on web
- Version checking must not block app functionality
- CSP headers must not break existing functionality

---

## Task 1: Offline Product Cache

**Files:**
- Create: `resources/js/lib/product-cache.ts`
- Modify: `resources/js/services/pos.ts`
- Modify: `resources/js/Pages/Transactions/Create.tsx`

**Interfaces:**
- Produces: `ProductCache.save(products)`
- Produces: `ProductCache.load(): Product[]`
- Produces: `ProductCache.getLastSync(): Date`

### Step 1: Create product-cache.ts

```typescript
import { openDB, DBSchema, IDBPDatabase } from 'idb';

interface ProductDB extends DBSchema {
    products: {
        key: number;
        value: Product;
        indexes: { 'by-sku': string };
    };
    meta: {
        key: string;
        value: { lastSync: string };
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
```

### Step 2: Install idb package

```bash
npm install idb
```

### Step 3: Update posService to cache products

In `resources/js/services/pos.ts`, add:

```typescript
import { productCache } from '@/lib/product-cache';

// Add new function
export async function fetchAndCacheProducts(): Promise<Product[]> {
    if (isNative()) {
        const data = await apiClient.get('/products');
        const products = data.products || [];

        // Cache for offline use
        await productCache.save(products);

        return products;
    }

    // Web mode: return empty array (products come from Inertia props)
    return [];
}

export async function getOfflineProducts(): Promise<Product[]> {
    return productCache.load();
}
```

### Step 4: Update Create.tsx to use cache

In `resources/js/Pages/Transactions/Create.tsx`, add offline fallback:

```tsx
import { productCache } from '@/lib/product-cache';
import * as posService from '@/services/pos';

// Add state for offline products
const [offlineProducts, setOfflineProducts] = useState<Product[]>([]);

// Load products with offline fallback
useEffect(() => {
    if (isNative()) {
        const loadProducts = async () => {
            try {
                const products = await posService.fetchAndCacheProducts();
                setOfflineProducts(products);
            } catch (error) {
                // Network failed, load from cache
                console.warn('Network failed, loading cached products');
                const cached = await productCache.load();
                setOfflineProducts(cached);
            }
        };

        loadProducts();
    }
}, []);

// Use offline products when online products are empty
const activeProducts = products.length > 0 ? products : offlineProducts;
```

### Step 5: Commit

```bash
git add resources/js/lib/product-cache.ts resources/js/services/pos.ts resources/js/Pages/Transactions/Create.tsx package.json package-lock.json
git commit -m "feat(mobile): add offline product cache with IndexedDB"
```

---

## Task 2: Secure Token Storage

**Files:**
- Modify: `resources/js/api/client.ts`
- Create: `resources/js/lib/secure-storage.ts`

**Interfaces:**
- Produces: `SecureStorage.get(key)`
- Produces: `SecureStorage.set(key, value)`
- Produces: `SecureStorage.remove(key)`
- Falls back to localStorage on web

### Step 1: Create secure-storage.ts

```typescript
import { isNative } from '@/lib/capacitor';

class SecureStorage {
    private capacitorStorage: any = null;
    private initialized = false;

    async init(): Promise<void> {
        if (!isNative() || this.initialized) return;

        try {
            const { SecureStorage: CapacitorSecureStorage } = await import('@capacitor/secure-storage');
            this.capacitorStorage = CapacitorSecureStorage;
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
        } catch (error) {
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
        } catch (error) {
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
        } catch (error) {
            localStorage.removeItem(key);
        }
    }
}

export const secureStorage = new SecureStorage();
```

### Step 2: Update api/client.ts

```typescript
import { isNative } from '@/lib/capacitor';
import { secureStorage } from '@/lib/secure-storage';

const API_BASE = '/api';
const TOKEN_KEY = 'kasir_token';

async function getToken(): Promise<string | null> {
    if (isNative()) {
        await secureStorage.init();
        return secureStorage.get(TOKEN_KEY);
    }
    return localStorage.getItem(TOKEN_KEY);
}

export async function setToken(token: string): Promise<void> {
    if (isNative()) {
        await secureStorage.init();
        await secureStorage.set(TOKEN_KEY, token);
    } else {
        localStorage.setItem(TOKEN_KEY, token);
    }
}

export async function clearToken(): Promise<void> {
    if (isNative()) {
        await secureStorage.init();
        await secureStorage.remove(TOKEN_KEY);
    } else {
        localStorage.removeItem(TOKEN_KEY);
    }
}

export function hasToken(): boolean {
    // For sync checks, use localStorage
    return localStorage.getItem(TOKEN_KEY) !== null;
}

// Update request function to use async getToken
async function request<T = any>(
    endpoint: string,
    options: ApiOptions = {},
): Promise<T> {
    const { method = 'GET', body, headers = {} } = options;
    const token = await getToken();

    const fetchHeaders: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...headers,
    };

    if (token) {
        fetchHeaders['Authorization'] = `Bearer ${token}`;
    }

    // ... rest of request function
}
```

### Step 3: Install @capacitor/secure-storage

```bash
npm install @capacitor/secure-storage
npx cap sync android
```

### Step 4: Commit

```bash
git add resources/js/api/client.ts resources/js/lib/secure-storage.ts package.json package-lock.json
git commit -m "feat(mobile): use Capacitor SecureStorage for token storage"
```

---

## Task 3: App Version Checking

**Files:**
- Create: `resources/js/hooks/useVersionCheck.ts`
- Modify: `resources/js/Pages/Transactions/Create.tsx`

**Interfaces:**
- Produces: `useVersionCheck()` hook
- Produces: Shows update prompt when new version available

### Step 1: Create version check hook

```typescript
import { useState, useEffect } from 'react';
import { App } from '@capacitor/app';
import { isNative } from '@/lib/capacitor';

const APP_VERSION = '1.0.0'; // Update this on each release
const VERSION_CHECK_INTERVAL = 60 * 60 * 1000; // 1 hour

export function useVersionCheck() {
    const [needsUpdate, setNeedsUpdate] = useState(false);
    const [latestVersion, setLatestVersion] = useState<string | null>(null);

    useEffect(() => {
        if (!isNative()) return;

        const checkVersion = async () => {
            try {
                // In a real app, this would call an API endpoint
                // For now, we'll just check against a hardcoded version
                // that you update when releasing a new build

                const response = await fetch('/api/version');
                const data = await response.json();

                if (data.version && data.version !== APP_VERSION) {
                    setLatestVersion(data.version);
                    setNeedsUpdate(true);
                }
            } catch (error) {
                // Version check failed, continue without blocking
                console.warn('Version check failed:', error);
            }
        };

        // Check on app launch
        checkVersion();

        // Check periodically
        const interval = setInterval(checkVersion, VERSION_CHECK_INTERVAL);

        // Check when app comes to foreground
        const appStateListener = App.addListener('appStateChange', ({ isActive }) => {
            if (isActive) {
                checkVersion();
            }
        });

        return () => {
            clearInterval(interval);
            appStateListener.remove();
        };
    }, []);

    return { needsUpdate, latestVersion, currentVersion: APP_VERSION };
}
```

### Step 2: Add version endpoint to Laravel

Create `app/Http/Controllers/Api/VersionController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class VersionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'version' => config('app.version', '1.0.0'),
            'update_url' => 'https://your-domain.com/downloads/latest.apk',
        ]);
    }
}
```

Add to `routes/api.php`:

```php
Route::get('/version', [\App\Http\Controllers\Api\VersionController::class, 'index']);
```

Add to `config/app.php`:

```php
'version' => env('APP_VERSION', '1.0.0'),
```

### Step 3: Use hook in Create.tsx

```tsx
import { useVersionCheck } from '@/hooks/useVersionCheck';

export default function TabletPOS(...) {
    const { needsUpdate, latestVersion } = useVersionCheck();

    return (
        <ErrorBoundary>
            <div className="flex h-screen flex-col bg-white">
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

                {/* ... rest of app */}
            </div>
        </ErrorBoundary>
    );
}
```

### Step 4: Commit

```bash
git add resources/js/hooks/useVersionCheck.ts app/Http/Controllers/Api/VersionController.php resources/js/Pages/Transactions/Create.tsx config/app.php
git commit -m "feat(mobile): add app version checking with update prompt"
```

---

## Task 4: Content-Security-Policy Headers

**Files:**
- Create: `app/Http/Middleware/SecurityHeaders.php`
- Modify: `bootstrap/app.php`

**Interfaces:**
- Produces: CSP headers on all responses
- Produces: X-Frame-Options, X-Content-Type-Options headers

### Step 1: Create SecurityHeaders middleware

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Content Security Policy
        $csp = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self' http://192.168.1.16:8000",
            "frame-ancestors 'none'",
        ];

        $response->headers->set('Content-Security-Policy', implode('; ', $csp));
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
```

### Step 2: Register middleware

In `bootstrap/app.php`, add to the middleware stack:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
})
```

### Step 3: Commit

```bash
git add app/Http/Middleware/SecurityHeaders.php bootstrap/app.php
git commit -m "security: add Content-Security-Policy and security headers"
```

---

## Task 5: Remove Stale Staff Role

**Files:**
- Create: `database/migrations/2026_07_05_000002_clean_stale_staff_role.php`

**Interfaces:**
- Produces: Removes unused 'staff' value from role ENUM

### Step 1: Create migration

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // First, update any 'staff' users to 'kasir' (if any exist)
        DB::table('users')
            ->where('role', 'staff')
            ->update(['role' => 'kasir']);

        // Then modify the ENUM to remove 'staff'
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'kasir') NOT NULL DEFAULT 'kasir'");
    }

    public function down(): void
    {
        // Re-add 'staff' to ENUM
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'staff', 'kasir') NOT NULL DEFAULT 'kasir'");
    }
};
```

### Step 2: Commit

```bash
git add database/migrations/2026_07_05_000002_clean_stale_staff_role.php
git commit -m "chore: remove unused 'staff' role from users ENUM"
```

---

## Summary

| Task | Feature | Effort |
|------|---------|--------|
| 1 | Offline product cache | 4 hours |
| 2 | Secure token storage | 2 hours |
| 3 | App version checking | 3 hours |
| 4 | CSP headers | 2 hours |
| 5 | Remove stale staff role | 15 min |
| **Total** | | **~11 hours** |

---

## Verification

After completing all tasks:

```bash
# Install idb package
npm install

# Build frontend
npm run build

# Sync Capacitor
npx cap sync android

# Test offline cache:
# 1. Open app with internet
# 2. Turn off internet
# 3. Verify products still load from cache

# Test secure storage:
# 1. Login on Android
# 2. Verify token is stored securely (not in localStorage)

# Test version check:
# 1. Update APP_VERSION in useVersionCheck.ts
# 2. Rebuild and deploy
# 3. Verify update banner appears on old app

# Test CSP headers:
# 1. Check browser console for CSP violations
# 2. Verify no broken functionality
```
