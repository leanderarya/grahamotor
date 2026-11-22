import { useEffect } from 'react';
import { isNative } from '@/lib/capacitor';

export function useStatusBar() {
    useEffect(() => {
        if (!isNative()) return;

        import('@capacitor/status-bar').then(({ StatusBar, Style }) => {
            StatusBar.setBackgroundColor({ color: '#f1f5f9' }); // slate-100
            StatusBar.setStyle({ style: Style.Light });
        });
    }, []);
}
