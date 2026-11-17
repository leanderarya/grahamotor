<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ResolvesMonthlyAnalyticsFilter;
use App\Models\Transaction;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MonthlyAnalyticsStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;
    use ResolvesMonthlyAnalyticsFilter;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $month = $this->getSelectedAnalyticsMonth($this->filters);
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $previousMonthStart = $month->copy()->subMonth()->startOfMonth();
        $previousMonthEnd = $month->copy()->subMonth()->endOfMonth();

        // Query 1: Current month aggregate (paid only)
        $canViewProfit = auth()->user()?->isOwner() ?? false;
        $monthAggQuery = Transaction::query()
            ->where('status', 'paid')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->selectRaw('COALESCE(SUM(total_amount), 0) as revenue')
            ->selectRaw('COUNT(*) as record_count')
            ->selectRaw('COUNT(DISTINCT DATE(created_at)) as active_days');

        if ($canViewProfit) {
            $monthAggQuery->selectRaw('COALESCE(SUM(total_profit), 0) as profit');
        }

        $monthAgg = $monthAggQuery->first();

        // Query 2: Best daily revenue for current month (paid only)
        $bestDaily = Transaction::query()
            ->selectRaw('COALESCE(MAX(daily_rev), 0) as best')
            ->fromSub(
                Transaction::query()
                    ->selectRaw('SUM(total_amount) as daily_rev')
                    ->where('status', 'paid')
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->groupByRaw('DATE(created_at)'),
                'daily'
            )
            ->value('best');

        // Query 3: Previous month revenue for comparison (paid only)
        $previousRevenue = (float) Transaction::where('status', 'paid')->whereBetween('created_at', [$previousMonthStart, $previousMonthEnd])
            ->sum('total_amount');

        $monthlyRevenue = (float) $monthAgg->revenue;
        $recordCount = (int) $monthAgg->record_count;
        $activeDays = (int) $monthAgg->active_days;
        $averageRevenue = $activeDays > 0 ? $monthlyRevenue / $activeDays : 0;
        $bestDailyRevenue = (float) $bestDaily;

        $stats = [
            Stat::make('Omset '.$month->translatedFormat('F Y'), $this->formatCurrency($monthlyRevenue))
                ->description($this->makeComparisonText($monthlyRevenue, $previousRevenue))
                ->descriptionIcon($monthlyRevenue >= $previousRevenue ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($monthlyRevenue >= $previousRevenue ? 'success' : 'danger'),

            Stat::make('Hari Aktif', number_format($activeDays, 0, ',', '.'))
                ->description('Jumlah hari dengan transaksi')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('warning'),

            Stat::make('Rata-rata Omset / Hari', $this->formatCurrency($averageRevenue))
                ->description('Dihitung dari hari yang memiliki penjualan')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('gray'),

            Stat::make('Record Transaksi', number_format($recordCount, 0, ',', '.'))
                ->description('Termasuk hasil impor rekap')
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('info'),

            Stat::make('Puncak Penjualan Harian', $this->formatCurrency($bestDailyRevenue))
                ->description('Omset harian tertinggi pada bulan ini')
                ->descriptionIcon('heroicon-m-fire')
                ->color('success'),
        ];

        if ($canViewProfit) {
            array_splice($stats, 1, 0, [
                Stat::make('Profit Bulanan', $this->formatCurrency((float) $monthAgg->profit))
                    ->description('Akumulasi margin bulan terpilih')
                    ->descriptionIcon('heroicon-m-banknotes')
                    ->color('primary'),
            ]);
        }

        return $stats;
    }

    private function formatCurrency(float $value): string
    {
        return 'Rp '.number_format($value, 0, ',', '.');
    }

    private function makeComparisonText(float $current, float $previous): string
    {
        if ($previous <= 0) {
            return $current > 0 ? 'Data bulan sebelumnya belum tersedia' : 'Belum ada penjualan';
        }

        $difference = (($current - $previous) / $previous) * 100;
        $prefix = $difference >= 0 ? '+' : '';

        return $prefix.number_format($difference, 1, ',', '.').'% vs bulan sebelumnya';
    }
}
