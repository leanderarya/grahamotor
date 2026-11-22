<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use App\Models\Product;
use App\Models\Transaction;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatsOverview extends BaseWidget
{
    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    protected function getStats(): array
    {
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();
        $startOfLastMonth = $now->copy()->subMonth()->startOfMonth();
        $endOfLastMonth = $now->copy()->subMonth()->endOfMonth();

        // --- SINGLE QUERY: All transaction aggregates at once ---
        $agg = Transaction::query()
            ->where('status', 'paid')
            ->selectRaw('
                COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN total_amount ELSE 0 END), 0) as omset_this_month,
                COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN total_amount ELSE 0 END), 0) as omset_last_month,
                COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN total_profit ELSE 0 END), 0) as gross_profit,
                COALESCE(SUM(CASE WHEN DATE(created_at) = ? THEN total_amount ELSE 0 END), 0) as today_sales,
                COUNT(CASE WHEN DATE(created_at) = ? THEN 1 END) as today_count
            ', [
                $startOfMonth, $endOfMonth,
                $startOfLastMonth, $endOfLastMonth,
                $startOfMonth, $endOfMonth,
                $now->toDateString(),
                $now->toDateString(),
            ])
            ->first();

        $omsetThisMonth = (float) $agg->omset_this_month;
        $omsetLastMonth = (float) $agg->omset_last_month;
        $grossProfit = (float) $agg->gross_profit;
        $todaySales = (float) $agg->today_sales;
        $todayCount = (int) $agg->today_count;

        // Expenses still separate (different table)
        $expenses = (float) Expense::whereBetween('date_expense', [$startOfMonth, $endOfMonth])->sum('amount');
        $netProfit = $grossProfit - $expenses;

        // Omset comparison
        $omsetColor = $omsetThisMonth >= $omsetLastMonth ? 'success' : 'danger';
        $omsetIcon = $omsetThisMonth >= $omsetLastMonth ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down';
        $omsetDesc = $omsetThisMonth >= $omsetLastMonth ? 'Naik dari bulan lalu' : 'Turun dari bulan lalu';

        // Chart: 7-day daily totals (single GROUP BY query)
        $dailyTotals = Transaction::query()
            ->selectRaw('DATE(created_at) as sale_date')
            ->selectRaw('SUM(total_amount) as total')
            ->where('status', 'paid')
            ->whereBetween('created_at', [$now->copy()->subDays(6)->startOfDay(), $now->endOfDay()])
            ->groupBy('sale_date')
            ->get()
            ->keyBy('sale_date');

        $chartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $now->copy()->subDays($i)->format('Y-m-d');
            $chartData[] = (float) ($dailyTotals[$date]->total ?? 0);
        }

        // Asset value (cached 5 min)
        $totalAset = Cache::remember('dashboard_asset_value', 300, fn () => Product::query()->sum(DB::raw('stock * cost_price')));

        return [
            Stat::make('Penjualan Hari Ini', 'Rp '.number_format($todaySales, 0, ',', '.'))
                ->description($todayCount.' transaksi hari ini')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('warning'),

            Stat::make('Omset Bulan Ini', 'Rp '.number_format($omsetThisMonth, 0, ',', '.'))
                ->description($omsetDesc)
                ->descriptionIcon($omsetIcon)
                ->chart($chartData)
                ->color($omsetColor),

            Stat::make('Profit Bersih (Bulan Ini)', 'Rp '.number_format($netProfit, 0, ',', '.'))
                ->description('Margin - Biaya Ops (Bulan Ini)')
                ->descriptionIcon($netProfit > 0 ? 'heroicon-m-face-smile' : 'heroicon-m-face-frown')
                ->color($netProfit >= 0 ? 'success' : 'danger'),

            Stat::make('Nilai Aset Gudang', 'Rp '.number_format($totalAset, 0, ',', '.'))
                ->description('Total modal mandek di barang')
                ->descriptionIcon('heroicon-m-archive-box')
                ->color('info'),
        ];
    }
}
