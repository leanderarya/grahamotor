<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ImportHistoryResource\Pages;
use App\Models\ImportHistory;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ImportHistoryResource extends Resource
{
    protected static ?string $model = ImportHistory::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationLabel = 'Riwayat Impor';

    protected static ?string $navigationGroup = 'Pelaporan';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Informasi Impor')
                    ->columns(2)
                    ->schema([
                        Placeholder::make('import_month')
                            ->label('Bulan')
                            ->content(fn (?ImportHistory $record): string => $record ? ucfirst($record->import_month->locale('id')->translatedFormat('F Y')) : '-'),
                        Placeholder::make('original_file_name')
                            ->label('Nama file')
                            ->content(fn (?ImportHistory $record): string => $record?->original_file_name ?? '-'),
                        Placeholder::make('user')
                            ->label('Diimpor oleh')
                            ->content(fn (?ImportHistory $record): string => $record?->user?->name ?? '-'),
                        Placeholder::make('status')
                            ->label('Status')
                            ->content(fn (?ImportHistory $record): string => match ($record?->status) {
                                'failed' => 'Gagal',
                                'processing' => 'Diproses',
                                default => 'Sukses',
                            }),
                        Placeholder::make('validation_status')
                            ->label('Validasi bulan')
                            ->content(fn (?ImportHistory $record): string => $record?->getValidationStatusLabel() ?? '-'),
                    ]),
                Section::make('Ringkasan')
                    ->columns(3)
                    ->schema([
                        Placeholder::make('imported_days')
                            ->label('Hari diimpor')
                            ->content(fn (?ImportHistory $record): string => (string) ($record?->imported_days ?? 0)),
                        Placeholder::make('created_transactions')
                            ->label('Transaksi baru')
                            ->content(fn (?ImportHistory $record): string => (string) ($record?->created_transactions ?? 0)),
                        Placeholder::make('updated_transactions')
                            ->label('Transaksi diperbarui')
                            ->content(fn (?ImportHistory $record): string => (string) ($record?->updated_transactions ?? 0)),
                        Placeholder::make('created_products')
                            ->label('Produk baru')
                            ->content(fn (?ImportHistory $record): string => (string) ($record?->created_products ?? 0)),
                        Placeholder::make('imported_items')
                            ->label('Item tersimpan')
                            ->content(fn (?ImportHistory $record): string => (string) ($record?->imported_items ?? 0)),
                        Placeholder::make('skipped_days')
                            ->label('Hari kosong')
                            ->content(fn (?ImportHistory $record): string => (string) ($record?->skipped_days ?? 0)),
                        Placeholder::make('out_of_month_days')
                            ->label('Hari di luar bulan')
                            ->content(fn (?ImportHistory $record): string => (string) ($record?->out_of_month_days ?? 0)),
                        Placeholder::make('matched_days')
                            ->label('Hari yang cocok')
                            ->content(fn (?ImportHistory $record): string => (string) ($record?->matched_days ?? 0)),
                    ]),
                Section::make('Validasi Impor')
                    ->schema([
                        Placeholder::make('detected_months')
                            ->label('Bulan yang terdeteksi')
                            ->content(fn (?ImportHistory $record): string => filled($record?->detected_months)
                                ? implode(', ', $record->detected_months)
                                : '-'),
                        Placeholder::make('validation_notes')
                            ->label('Ringkasan selisih')
                            ->content(fn (?ImportHistory $record): string => $record?->validation_notes ?? '-'),
                    ]),
                Section::make('Error')
                    ->schema([
                        Textarea::make('error_message')
                            ->label('Pesan error')
                            ->rows(5),
                    ])
                    ->visible(fn (?ImportHistory $record): bool => filled($record?->error_message)),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('import_month')
                    ->label('Bulan')
                    ->formatStateUsing(fn ($state) => ucfirst($state->locale('id')->translatedFormat('F Y')))
                    ->sortable(),
                TextColumn::make('original_file_name')
                    ->label('File')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('user.name')
                    ->label('Admin')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'failed' => 'Gagal',
                        'processing' => 'Diproses',
                        default => 'Sukses',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'failed' => 'danger',
                        'processing' => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('validation_status')
                    ->label('Validasi')
                    ->badge()
                    ->state(fn (ImportHistory $record): string => $record->getValidationStatusLabel())
                    ->color(fn (ImportHistory $record): string => $record->getValidationStatusColor()),
                TextColumn::make('created_transactions')
                    ->label('Baru')
                    ->alignCenter(),
                TextColumn::make('updated_transactions')
                    ->label('Update')
                    ->alignCenter(),
                TextColumn::make('validation_notes')
                    ->label('Ringkasan Selisih')
                    ->limit(60),
                TextColumn::make('created_products')
                    ->label('Produk Baru')
                    ->alignCenter(),
                TextColumn::make('created_at')
                    ->label('Waktu Impor')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImportHistories::route('/'),
            'view' => Pages\ViewImportHistory::route('/{record}'),
        ];
    }
}
