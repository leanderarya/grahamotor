import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { ErrorBoundary } from '@/Components/ErrorBoundary';
import { initializeTheme } from './hooks/use-appearance';
import { useAndroidBackButton } from './hooks/useAndroidBackButton';
import { useStatusBar } from './hooks/useStatusBar';
import { useAppLifecycle } from './hooks/useAppLifecycle';
import { Ziggy } from './ziggy'; // <--- Import file hasil generate
globalThis.Ziggy = Ziggy;

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        function AppWithAndroidOptimizations() {
            useAndroidBackButton();
            useStatusBar();
            useAppLifecycle();
            return <App {...props} />;
        }

        root.render(
            <StrictMode>
                <ErrorBoundary>
                    <AppWithAndroidOptimizations />
                </ErrorBoundary>
            </StrictMode>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
