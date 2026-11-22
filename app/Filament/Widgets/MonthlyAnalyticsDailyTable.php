<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ResolvesMonthlyAnalyticsFilter;
use App\Models\Transaction;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Model;

class MonthlyAnalyticsDailyTable extends TableWidget
{
    use InteractsWithPageFilters;
    use ResolvesMonthlyAnalyticsFilter;

    protected int|string|array $columnSpan = 'full';

    public function getTableRecordKey(Model $record): string
    {
        return (string) $record->sales_date;
    }

    public function table(Table $table): Table
    {
        [$monthStart, $monthEnd] = [
            $this->getSelectedAnalyticsMonth($this->filters)->copy()->startOfMonth(),
            $this->getSelectedAnalyticsMonth($this->filters)->copy()->endOfMonth(),
        ];
        $canViewProfit = auth()->user()?->isOwner() ?? false;
        $query = Transaction::query()
            ->selectRaw('DATE(created_at) as sales_date')
            ->selectRaw('SUM(total_amount) as total_revenue')
            ->selectRaw('COUNT(*) as transaction_count')
            ->where('status', 'paid')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->groupBy('sales_date')
            ->orderBy('sales_date');

        if ($canViewProfit) {
            $query->selectRaw('SUM(total_profit) as total_profit_amount');
        }

        return $table
            ->query($query)
            ->heading('Rekap Penjualan Harian')
            ->defaultPaginationPageOption(31)
            ->columns([
                Tables\Columns\TextColumn::make('sales_date')
                    ->label('Tanggal')
                    ->date('d F Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_revenue')
                    ->label('Omset')
                    ->money('IDR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_profit_amount')
                    ->label('Profit')
                    ->money('IDR')
                    ->color('success')
                    ->sortable()
                    ->visible($canViewProfit),

                Tables\Columns\TextColumn::make('transaction_count')
                    ->label('Record')
                    ->badge()
                    ->alignCenter()
                    ->sortable(),
            ]);
    }
}
