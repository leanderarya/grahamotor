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
                const response = await fetch('/api/version');
                const data = await response.json();

                if (data.version && data.version !== APP_VERSION) {
                    setLatestVersion(data.version);
                    setNeedsUpdate(true);
                }
            } catch {
                // Version check failed, continue without blocking
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
            appStateListener.then(listener => listener.remove());
        };
    }, []);

    return { needsUpdate, latestVersion, currentVersion: APP_VERSION };
}
