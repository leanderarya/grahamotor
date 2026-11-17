<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MonthlyReportResource\Pages;
use App\Models\MonthlyReport;
use App\Services\MonthlyReportService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MonthlyReportResource extends Resource
{
    protected static ?string $model = MonthlyReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Finalisasi Bulan';

    protected static ?string $navigationGroup = 'Pelaporan';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Ringkasan Bulan')
                    ->columns(2)
                    ->schema([
                        Placeholder::make('month_label')
                            ->label('Bulan')
                            ->content(fn (?MonthlyReport $record): string => $record?->getMonthLabel() ?? '-'),
                        Placeholder::make('status')
                            ->label('Status')
                            ->content(fn (?MonthlyReport $record): string => $record?->isFinalized() ? 'Final' : 'Draft'),
                        Placeholder::make('transaction_count')
                            ->label('Jumlah transaksi')
                            ->content(fn (?MonthlyReport $record): string => number_format($record?->transaction_count ?? 0)),
                        Placeholder::make('total_amount')
                            ->label('Total omset')
                            ->content(fn (?MonthlyReport $record): string => 'Rp '.number_format((float) ($record?->total_amount ?? 0), 0, ',', '.')),
                        Placeholder::make('total_profit')
                            ->label('Total profit')
                            ->content(fn (?MonthlyReport $record): string => 'Rp '.number_format((float) ($record?->total_profit ?? 0), 0, ',', '.')),
                        Placeholder::make('finalized_by')
                            ->label('Difinalisasi oleh')
                            ->content(fn (?MonthlyReport $record): string => $record?->finalizedBy?->name ?? '-'),
                        Placeholder::make('validation_status')
                            ->label('Validasi impor terakhir')
                            ->content(fn (?MonthlyReport $record): string => $record?->latestImportHistory?->getValidationStatusLabel() ?? 'Belum ada impor'),
                    ]),
                Section::make('Validasi Sebelum Finalisasi')
                    ->schema([
                        Placeholder::make('validation_summary')
                            ->label('Ringkasan selisih')
                            ->content(fn (?MonthlyReport $record): string => $record?->getLatestValidationSummary() ?? '-'),
                    ]),
                Section::make('Catatan Bulan')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Catatan admin')
                            ->rows(5),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['finalizedBy', 'latestImportHistory'])->withCount('importHistories'))
            ->columns([
                TextColumn::make('month_date')
                    ->label('Bulan')
                    ->formatStateUsing(fn ($state) => ucfirst($state->locale('id')->translatedFormat('F Y')))
                    ->sortable(),
                TextColumn::make('finalized_at')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? 'Final' : 'Draft')
                    ->color(fn (?string $state): string => $state ? 'success' : 'warning'),
                TextColumn::make('transaction_count')
                    ->label('Transaksi')
                    ->badge(),
                TextColumn::make('total_amount')
                    ->label('Omset')
                    ->money('IDR'),
                TextColumn::make('total_profit')
                    ->label('Profit')
                    ->money('IDR')
                    ->color('success'),
                TextColumn::make('import_histories_count')
                    ->counts('importHistories')
                    ->label('Riwayat Impor'),
                TextColumn::make('latestImportHistory.validation_status')
                    ->label('Validasi')
                    ->badge()
                    ->state(fn (MonthlyReport $record): string => $record->latestImportHistory?->getValidationStatusLabel() ?? 'Belum ada impor')
                    ->color(fn (MonthlyReport $record): string => $record->latestImportHistory?->getValidationStatusColor() ?? 'gray'),
                TextColumn::make('validation_summary')
                    ->label('Ringkasan Selisih')
                    ->state(fn (MonthlyReport $record): string => $record->getLatestValidationSummary())
                    ->wrap(),
                TextColumn::make('notes')
                    ->label('Catatan')
                    ->limit(40)
                    ->toggleable(),
            ])
            ->defaultSort('month_date', 'desc')
            ->actions([
                Tables\Actions\Action::make('transactions')
                    ->label('Transaksi Bulan Ini')
                    ->icon('heroicon-o-banknotes')
                    ->url(fn (MonthlyReport $record): string => TransactionResource::getUrl('index', [
                        'tableFilters' => [
                            'month' => [
                                'value' => $record->getMonthKey(),
                            ],
                        ],
                    ])),
                Tables\Actions\Action::make('finalize')
                    ->label('Finalisasi')
                    ->icon('heroicon-o-lock-closed')
                    ->color('success')
                    ->visible(fn (MonthlyReport $record): bool => ! $record->isFinalized())
                    ->requiresConfirmation()
                    ->modalDescription(fn (MonthlyReport $record): string => $record->getLatestValidationSummary())
                    ->action(function (MonthlyReport $record, MonthlyReportService $monthlyReportService): void {
                        $record = $monthlyReportService->syncMonth($record->month_date);
                        $record->update([
                            'finalized_at' => now(),
                            'finalized_by' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title("Bulan {$record->getMonthLabel()} sudah difinalisasi")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('reopen')
                    ->label('Buka Finalisasi')
                    ->icon('heroicon-o-lock-open')
                    ->color('warning')
                    ->visible(fn (MonthlyReport $record): bool => $record->isFinalized())
                    ->requiresConfirmation()
                    ->action(function (MonthlyReport $record): void {
                        $record->update([
                            'finalized_at' => null,
                            'finalized_by' => null,
                        ]);

                        Notification::make()
                            ->title("Bulan {$record->getMonthLabel()} dibuka kembali")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMonthlyReports::route('/'),
            'edit' => Pages\EditMonthlyReport::route('/{record}/edit'),
        ];
    }
}
