import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
    appId: 'com.grahamotor.app',
    appName: 'Graha Motor',
    webDir: 'public/build',
    server: {
        url: 'https://grahamotor.cahayaarkana.site',
        cleartext: false,
        androidScheme: 'https',
    },
    android: {
        allowMixedContent: false,
    },
    plugins: {
        SplashScreen: {
            launchShowDuration: 2000,
            backgroundColor: '#1f2937', // dark gray matching POS theme
            showSpinner: false,
            androidSplashResourceName: 'splash',
            androidScaleType: 'CENTER_CROP',
            splashFullScreen: true,
            launchAutoHide: true,
        },
    },
};

export default config;
