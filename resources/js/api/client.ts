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

interface ApiOptions {
    method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
    body?: unknown;
    headers?: Record<string, string>;
}

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

    const response = await fetch(`${API_BASE}${endpoint}`, {
        method,
        headers: fetchHeaders,
        body: body ? JSON.stringify(body) : undefined,
    });

    if (!response.ok) {
        if (response.status === 401) {
            await clearToken();
            window.location.href = '/pin-login';
            throw new Error('Unauthorized');
        }

        const errorData = await response.json().catch(() => ({}));
        throw {
            status: response.status,
            message: errorData.message || 'Terjadi kesalahan.',
            errors: errorData.errors || {},
        };
    }

    return response.json();
}

export const apiClient = {
    get: <T = any>(endpoint: string) => request<T>(endpoint),

    post: <T = any>(endpoint: string, body?: unknown) =>
        request<T>(endpoint, { method: 'POST', body }),

    put: <T = any>(endpoint: string, body?: unknown) =>
        request<T>(endpoint, { method: 'PUT', body }),

    delete: <T = any>(endpoint: string) =>
        request<T>(endpoint, { method: 'DELETE' }),
};
