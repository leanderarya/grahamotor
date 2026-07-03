import { Component, type ErrorInfo, type ReactNode } from 'react';
import { AlertTriangle, RefreshCw } from 'lucide-react';

interface Props {
    children: ReactNode;
    fallback?: ReactNode;
}

interface State {
    hasError: boolean;
    error: Error | null;
}

export class ErrorBoundary extends Component<Props, State> {
    public state: State = {
        hasError: false,
        error: null,
    };

    public static getDerivedStateFromError(error: Error): State {
        return { hasError: true, error };
    }

    public componentDidCatch(error: Error, errorInfo: ErrorInfo) {
        console.error('Uncaught error:', error, errorInfo);
    }

    private handleReload = () => {
        window.location.reload();
    };

    public render() {
        if (this.state.hasError) {
            if (this.props.fallback) {
                return this.props.fallback;
            }

            return (
                <div className="flex h-screen flex-col items-center justify-center bg-white p-8">
                    <div className="max-w-md text-center">
                        <AlertTriangle className="mx-auto h-16 w-16 text-amber-500" />
                        <h1 className="mt-4 text-xl font-bold text-slate-950">
                            Terjadi Kesalahan
                        </h1>
                        <p className="mt-2 text-sm text-slate-600">
                            Aplikasi mengalami error yang tidak terduga.
                            Silakan muat ulang halaman untuk melanjutkan.
                        </p>
                        <button
                            onClick={this.handleReload}
                            className="mt-6 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
                        >
                            <RefreshCw className="h-4 w-4" />
                            Muat Ulang
                        </button>
                        <p className="mt-4 text-xs text-slate-400">
                            Error: {this.state.error?.message || 'Unknown error'}
                        </p>
                    </div>
                </div>
            );
        }

        return this.props.children;
    }
}
