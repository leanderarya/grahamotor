<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransactionResource\Pages;
use App\Models\Transaction;
use App\Services\MonthlyReportService;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    // Ganti icon jadi 'uang' biar lebih relevan, atau biarkan stack
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Laporan Transaksi';

    protected static ?string $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Informasi Transaksi')
                    ->columns(2)
                    ->schema([
                        TextInput::make('invoice_number')
                            ->label('No. Invoice')
                            ->required()
                            ->disabled(fn (?Transaction $record, string $operation): bool => $operation === 'view' || static::isLocked($record)),

                        DateTimePicker::make('created_at')
                            ->label('Waktu Transaksi')
                            ->required()
                            ->seconds(false)
                            ->disabled(fn (?Transaction $record, string $operation): bool => $operation === 'view' || static::isLocked($record)),

                        Select::make('user_id')
                            ->relationship('user', 'name')
                            ->label('Kasir')
                            ->required()
                            ->disabled(fn (?Transaction $record, string $operation): bool => $operation === 'view' || static::isLocked($record)),

                        Select::make('payment_method')
                            ->label('Metode Bayar')
                            ->options([
                                'cash' => 'Cash',
                                'qris' => 'QRIS',
                                'bca' => 'BCA',
                                'mandiri' => 'Mandiri',
                                'import_excel' => 'Import Excel',
                            ])
                            ->required()
                            ->disabled(fn (?Transaction $record, string $operation): bool => $operation === 'view' || static::isLocked($record)),
                    ]),

                Section::make('Detail Barang Belanjaan')
                    ->schema([
                        Repeater::make('transactionItems')
                            ->relationship()
                            ->disabled(fn (?Transaction $record, string $operation): bool => $operation === 'view' || static::isLocked($record))
                            ->schema([
                                Select::make('product_id')
                                    ->label('Nama Produk')
                                    ->relationship('product', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                TextInput::make('quantity')
                                    ->label('Qty')
                                    ->numeric()
                                    ->required()
                                    ->minValue(1),

                                TextInput::make('cost_at_time')
                                    ->label('Modal')
                                    ->prefix('Rp')
                                    ->numeric()
                                    ->required(),

                                TextInput::make('price_at_time')
                                    ->label('Harga Satuan')
                                    ->prefix('Rp')
                                    ->numeric()
                                    ->required(),

                                TextInput::make('total_calculated')
                                    ->label('Subtotal')
                                    ->prefix('Rp')
                                    ->formatStateUsing(function ($record) {
                                        $total = ($record->quantity ?? 0) * ($record->price_at_time ?? 0);

                                        return number_format($total, 0, ',', '.');
                                    })
                                    ->disabled()
                                    ->dehydrated(false),
                            ])
                            ->columns(4)
                            ->addable(fn (?Transaction $record, string $operation): bool => $operation !== 'view' && ! static::isLocked($record))
                            ->deletable(fn (?Transaction $record, string $operation): bool => $operation !== 'view' && ! static::isLocked($record))
                            ->reorderable(false),
                    ]),

                Section::make('Ringkasan Keuangan')
                    ->columns(3)
                    ->schema([
                        TextInput::make('amount_paid')
                            ->label('Uang Diterima')
                            ->prefix('Rp')
                            ->numeric()
                            ->disabled(fn (?Transaction $record, string $operation): bool => $operation === 'view' || static::isLocked($record)),

                        TextInput::make('total_amount')
                            ->label('Total Belanja')
                            ->prefix('Rp')
                            ->numeric()
                            ->disabled()
                            ->extraInputAttributes(['class' => 'text-xl font-bold']),

                        TextInput::make('total_profit')
                            ->label('Total Profit')
                            ->prefix('Rp')
                            ->numeric()
                            ->disabled()
                            ->extraInputAttributes(['class' => 'text-green-600 font-bold']),

                        TextInput::make('change_amount')
                            ->label('Kembalian')
                            ->prefix('Rp')
                            ->numeric()
                            ->disabled()
                            ->extraInputAttributes(['class' => 'text-green-600 font-bold']),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // 1. Nomor Invoice
                TextColumn::make('invoice_number')
                    ->label('No. Nota')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                // 2. Status
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'voided' => 'danger',
                        'draft' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'paid' => 'LUNAS',
                        'voided' => 'VOID',
                        'draft' => 'DRAFT',
                        default => strtoupper($state),
                    })
                    ->sortable(),

                // 3. Siapa Kasirnya?
                TextColumn::make('user.name')
                    ->label('Kasir')
                    ->sortable()
                    ->color('gray'),

                // 3. Waktu Transaksi
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                // 4. METODE BAYAR
                TextColumn::make('payment_method')
                    ->label('Metode')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'cash' => 'success',    // Hijau
                        'qris' => 'info',       // Biru
                        'bca' => 'primary',     // Biru Tua
                        'mandiri' => 'warning', // Kuning
                        'import_excel' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'cash' => 'Tunai',
                        'qris' => 'QRIS',
                        'bca' => 'BCA',
                        'mandiri' => 'Mandiri',
                        'import_excel' => 'Impor Excel',
                        default => strtoupper($state),
                    })
                    ->sortable(),

                // 5. TOTAL OMSET
                TextColumn::make('total_amount')
                    ->label('Total Omset')
                    ->money('IDR')
                    ->sortable()
                    ->alignRight()
                    // Summarize: Menjumlahkan apa yang tampil di layar (setelah filter)
                    ->summarize(Sum::make()->label('Total Pendapatan')),

                // 6. MARGIN / PROFIT
                TextColumn::make('total_profit')
                    ->label('Margin (Cuan)')
                    ->money('IDR')
                    ->color('success')
                    ->weight('bold')
                    ->alignRight()
                    ->sortable()
                    ->summarize(Sum::make()->label('Total Bersih')),
            ])
            ->defaultSort('created_at', 'desc')

            // --- BAGIAN BARU: FILTERS ---
            ->filters([
                SelectFilter::make('month')
                    ->label('Bulan')
                    ->options(function (): array {
                        return Transaction::query()
                            ->orderByDesc('created_at')
                            ->pluck('created_at')
                            ->map(fn ($date): string => Carbon::parse($date)->format('Y-m'))
                            ->filter()
                            ->unique()
                            ->mapWithKeys(function (string $monthKey): array {
                                $label = Carbon::createFromFormat('Y-m', $monthKey)
                                    ->locale('id')
                                    ->translatedFormat('F Y');

                                return [$monthKey => ucfirst($label)];
                            })
                            ->all();
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        $month = Carbon::createFromFormat('Y-m', $data['value']);

                        return $query
                            ->where('created_at', '>=', $month->copy()->startOfMonth())
                            ->where('created_at', '<', $month->copy()->addMonth()->startOfMonth());
                    }),

                // A. Filter Rentang Tanggal (Wajib buat Laporan Bulanan)
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('created_from')->label('Dari Tanggal'),
                        DatePicker::make('created_until')->label('Sampai Tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date),
                            );
                    }),

                // B. Filter Metode Bayar (Buat Cek Uang Cash vs Transfer)
                SelectFilter::make('payment_method')
                    ->label('Metode Bayar')
                    ->options([
                        'cash' => 'Tunai',
                        'qris' => 'QRIS',
                        'bca' => 'BCA',
                        'mandiri' => 'Mandiri',
                        'import_excel' => 'Impor Excel',
                    ]),

                // C. Filter Status (Paid / Voided)
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'paid' => 'Lunas',
                        'voided' => 'Void',
                    ]),
            ])

            // --- BAGIAN BARU: ACTIONS ---
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (Transaction $record): bool => ! $record->isInFinalizedMonth()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    BulkAction::make('deleteAll')
                        ->label('Hapus Terpilih')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Hapus semua transaksi terpilih?')
                        ->modalDescription('Semua transaksi yang dipilih akan dihapus permanen beserta item detailnya.')
                        ->action(function (Collection $records, MonthlyReportService $monthlyReportService): void {
                            $lockedCount = $records
                                ->filter(fn (Transaction $transaction): bool => $monthlyReportService->isFinalized($transaction->reportMonth()))
                                ->count();

                            if ($lockedCount > 0) {
                                Notification::make()
                                    ->title('Ada transaksi pada bulan yang sudah difinalisasi')
                                    ->body("{$lockedCount} transaksi tidak bisa dihapus karena bulannya sudah final.")
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $records->each->delete();

                            Notification::make()
                                ->title('Transaksi terpilih berhasil dihapus')
                                ->success()
                                ->send();
                        }),

                    ExportBulkAction::make()->label('Download Excel'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // Nanti kita isi ini agar saat klik "View",
            // muncul daftar barang apa saja yang dibeli.
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),
            'edit' => Pages\EditTransaction::route('/{record}/edit'),
        ];
    }

    protected static function isLocked(?Transaction $record): bool
    {
        return $record?->isInFinalizedMonth() ?? false;
    }
}
