<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CashierSessionResource\Pages;
use App\Models\CashierSession;
use App\Services\CashierSessionService;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CashierSessionResource extends Resource
{
    protected static ?string $model = CashierSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Shift Kasir';

    protected static ?string $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isOwner() || auth()->user()?->isAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Kasir')
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('opened_at')
                    ->label('Dibuka')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('closed_at')
                    ->label('Ditutup')
                    ->dateTime('d M Y, H:i')
                    ->placeholder('Masih terbuka')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (CashierSession $record): string => match (true) {
                        $record->closed_at !== null => 'Ditutup',
                        app(CashierSessionService::class)->isOverdue($record) => 'Lewat batas',
                        default => 'Berjalan',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Lewat batas' => 'danger',
                        'Berjalan' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('transactions_count')
                    ->label('Transaksi')
                    ->sortable(),
                Tables\Columns\TextColumn::make('cash_sales_total')
                    ->label('Penjualan tunai')
                    ->money('IDR'),
                Tables\Columns\TextColumn::make('non_cash_sales_total')
                    ->label('Non-tunai')
                    ->money('IDR'),
            ])
            ->defaultSort('opened_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('closeOverdue')
                    ->label('Tutup shift')
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->visible(fn (CashierSession $record): bool => $record->closed_at === null
                        && app(CashierSessionService::class)->isOverdue($record))
                    ->form([
                        TextInput::make('closing_cash_physical')
                            ->label('Uang fisik saat ini')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Rp')
                            ->required(),
                        Textarea::make('reason')
                            ->label('Alasan override')
                            ->required()
                            ->minLength(5)
                            ->maxLength(1000)
                            ->rows(3),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Tutup shift yang melewati batas')
                    ->modalDescription('Masukkan hitungan uang fisik dan alasan. Aksi ini dicatat di audit shift.')
                    ->action(function (CashierSession $record, array $data, CashierSessionService $service): void {
                        $service->closeOverdueSession(
                            $record->id,
                            auth()->id(),
                            (float) $data['closing_cash_physical'],
                            $data['reason'],
                        );

                        Notification::make()
                            ->title('Shift kasir ditutup')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Belum ada shift kasir')
            ->emptyStateDescription('Shift kasir yang dibuka akan tercatat di sini.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCashierSessions::route('/'),
        ];
    }
}
