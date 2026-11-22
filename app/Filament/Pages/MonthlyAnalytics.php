<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ResolvesMonthlyAnalyticsFilter;
use App\Filament\Widgets\MonthlyAnalyticsDailySalesChart;
use App\Filament\Widgets\MonthlyAnalyticsDailyTable;
use App\Filament\Widgets\MonthlyAnalyticsStatsOverview;
use App\Filament\Widgets\MonthlyAnalyticsTopProducts;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

class MonthlyAnalytics extends Dashboard
{
    use HasFiltersForm;
    use ResolvesMonthlyAnalyticsFilter;

    protected static string $routePath = '/monthly-analytics';

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Analitik Bulanan';

    protected static ?string $navigationGroup = 'Pelaporan';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Analitik Bulanan';

    public static function canAccess(): bool
    {
        return auth()->user()?->isOwner() || auth()->user()?->isAdmin();
    }

    protected ?string $heading = 'Analitik Bulanan';

    protected ?string $subheading = 'Analisis bulanan native Filament untuk data transaksi dan impor Excel.';

    public function mount(): void
    {
        if (! filled($this->filters['month'] ?? null)) {
            $this->filters = [
                'month' => array_key_first($this->getMonthlyAnalyticsMonthOptions()) ?? now()->format('Y-m'),
            ];
        }
    }

    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            Select::make('month')
                ->label('Pilih Bulan')
                ->options($this->getMonthlyAnalyticsMonthOptions())
                ->native(false)
                ->searchable()
                ->required(),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            MonthlyAnalyticsStatsOverview::class,
            MonthlyAnalyticsDailySalesChart::class,
            MonthlyAnalyticsTopProducts::class,
            MonthlyAnalyticsDailyTable::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 2;
    }
}
