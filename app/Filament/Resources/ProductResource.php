<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\View;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube'; // Ikon Kotak

    protected static ?string $navigationLabel = 'Produk';

    protected static ?string $navigationGroup = 'Inventaris';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isOwner() || auth()->user()?->isAdmin();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! (auth()->user()?->isOwner() ?? false)) {
            $query->select([
                'id',
                'sku',
                'name',
                'category',
                'image_path',
                'volume_liter',
                'stock',
                'sell_price',
                'workshop_price',
                'created_at',
                'updated_at',
            ]);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Data Barang')
                    ->schema([
                        View::make('filament.components.sku-scanner')
                            ->columnSpanFull(), // Biar tombolnya lebar

                        // --- 2. INPUT SKU (Tetap Ada) ---
                        // Ini yang akan terisi otomatis saat scan berhasil
                        TextInput::make('sku')
                            ->label('Kode Part (SKU)')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->placeholder('Scan atau ketik manual...'),

                        // Nama Produk
                        TextInput::make('name')
                            ->label('Nama Sparepart')
                            ->placeholder('Contoh: Oli Fastron Techno 10W-30 (1L)')
                            ->required()
                            ->columnSpanFull(),

                        FileUpload::make('image_path')
                            ->label('Gambar Produk')
                            ->image()
                            ->imageEditor()
                            ->directory('products')
                            ->disk('public')
                            ->visibility('public')
                            ->helperText('Opsional. Foto produk akan tampil di admin dan halaman kasir.'),

                        TextInput::make('volume_liter')
                            ->label('Volume (Liter)')
                            ->numeric()
                            ->step('0.01')
                            ->suffix('L')
                            ->helperText('Contoh: 0.8, 1, 4. Kosongkan jika tidak relevan.'),

                        // Keuangan & Stok
                        TextInput::make('stock')
                            ->label('Stok Saat Ini')
                            ->numeric()
                            ->required(),

                        TextInput::make('cost_price')
                            ->label('HPP (Modal Rata-rata)')
                            ->numeric()
                            ->prefix('Rp')
                            // UBAH BARIS INI:
                            // Hanya Read Only kalau lagi mode 'edit'. Kalau 'create', boleh diisi.
                            ->readOnly(fn (string $context): bool => $context === 'edit')
                            ->visible(fn (): bool => auth()->user()?->isOwner() ?? false)
                            ->dehydrated(fn (): bool => auth()->user()?->isOwner() ?? false)

                            // Wajib diisi saat bikin baru, biar HPP nggak nol
                            ->required(fn (string $context): bool => $context === 'create' && (auth()->user()?->isOwner() ?? false))

                            ->helperText(fn (string $context) => $context === 'create'
                                ? 'Masukkan modal awal untuk stok ini.'
                                : 'HPP terkunci. Input via menu Pembelian untuk update harga (Moving Average).'
                            ),

                        TextInput::make('sell_price')
                            ->label('Harga Jual')
                            ->numeric()
                            ->prefix('Rp')
                            ->required(),

                        TextInput::make('workshop_price')
                            ->label('Harga Bengkel (Opsional)')
                            ->numeric()
                            ->prefix('Rp')
                            ->helperText('Harga khusus pelanggan bengkel. Jika kosong, harga umum akan digunakan.'),
                    ])->columns(2),

                // --- FITUR JODOHKAN MOBIL ---
                Section::make('Kompatibilitas Kendaraan')
                    ->description('Sparepart ini bisa dipakai di mobil/motor apa saja?')
                    ->schema([
                        Select::make('vehicles')
                            ->relationship('vehicles', 'model') // Ambil data dari tabel vehicles
                            ->label('Pilih Kendaraan')
                            ->multiple() // Bisa pilih banyak
                            ->preload()
                            // 1. MODIFIKASI TAMPILAN LABEL (Merk + Model + Tahun)
                            ->getOptionLabelFromRecordUsing(fn (Model $record) => "{$record->brand} {$record->model} ({$record->year_generation})")

                            // 2. MODIFIKASI PENCARIAN (Biar bisa ketik "2015" atau "Gen 2")
                            ->searchable(['brand', 'model', 'year_generation'])

                            ->placeholder('Pilih mobil... (Bisa lebih dari satu)')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')
                    ->label('Foto')
                    ->disk('public')
                    ->square()
                    ->defaultImageUrl(asset('images/product-placeholder.svg')),

                // SKU
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                // Nama Barang
                TextColumn::make('display_name')
                    ->label('Produk')
                    ->searchable(query: function ($query, string $search) {
                        return $query->where('name', 'like', "%{$search}%");
                    })
                    ->weight('bold'),

                // Indikator Stok (Merah kalau sedikit)
                TextColumn::make('stock')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        $state <= 5 => 'danger',   // Stok Kritis (Merah)
                        $state <= 10 => 'warning', // Stok Tipis (Kuning)
                        default => 'success',      // Stok Aman (Hijau)
                    }),

                // Harga Jual
                TextColumn::make('sell_price')
                    ->label('Harga Jual')
                    ->money('IDR')
                    ->sortable(),

                TextColumn::make('workshop_price')
                    ->label('Harga Bengkel')
                    ->money('IDR')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // HPP
                TextColumn::make('cost_price')
                    ->label('HPP')
                    ->money('IDR')
                    ->sortable()
                    ->visible(fn (): bool => auth()->user()?->isOwner() ?? false)
                    ->toggleable(),

                // List Mobil yang Cocok (Ditampilkan sebagai Tag)
                TextColumn::make('vehicles.model')
                    ->label('Cocok Untuk')
                    ->badge()
                    ->separator(',')
                    ->limitList(3),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('stock')
                    ->label('Level Stok')
                    ->options([
                        'low' => 'Stok Rendah (≤5)',
                        'out' => 'Habis (0)',
                        'ok' => 'Stok Aman',
                    ])
                    ->query(function ($query, array $data) {
                        if ($data['value'] === 'low') {
                            return $query->where('stock', '<=', 5)->where('stock', '>', 0);
                        } elseif ($data['value'] === 'out') {
                            return $query->where('stock', 0);
                        } elseif ($data['value'] === 'ok') {
                            return $query->where('stock', '>', 5);
                        }
                        return $query;
                    }),
                \Filament\Tables\Filters\SelectFilter::make('category')
                    ->label('Kategori'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (Product $record): void {
                        static::guardProductDeletion($record);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    BulkAction::make('deleteSelectedProducts')
                        ->label('Hapus Produk Terpilih')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Hapus produk terpilih?')
                        ->modalDescription('Produk yang masih punya riwayat pembelian atau transaksi tidak akan dihapus.')
                        ->action(function (Collection $records): void {
                            $deletedCount = 0;
                            $blockedProducts = [];

                            foreach ($records as $product) {
                                if (static::hasDeletionDependencies($product)) {
                                    $blockedProducts[] = $product->display_name;

                                    continue;
                                }

                                $product->delete();
                                $deletedCount++;
                            }

                            if ($deletedCount > 0) {
                                Notification::make()
                                    ->title("{$deletedCount} produk berhasil dihapus")
                                    ->success()
                                    ->send();
                            }

                            if ($blockedProducts !== []) {
                                $blockedCount = count($blockedProducts);
                                $examples = implode(', ', array_slice($blockedProducts, 0, 3));
                                $suffix = $blockedCount > 3 ? ' dan lainnya' : '';

                                Notification::make()
                                    ->title("{$blockedCount} produk tidak bisa dihapus")
                                    ->body("Masih dipakai oleh riwayat pembelian/transaksi: {$examples}{$suffix}.")
                                    ->warning()
                                    ->send();
                            }
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }

    protected static function guardProductDeletion(Product $product): void
    {
        if (! static::hasDeletionDependencies($product)) {
            return;
        }

        Notification::make()
            ->title('Produk tidak bisa dihapus')
            ->body('Produk ini masih dipakai oleh riwayat pembelian, transaksi, atau penyesuaian stok.')
            ->danger()
            ->send();

        abort(422, 'Produk masih memiliki relasi data dan tidak bisa dihapus.');
    }

    protected static function hasDeletionDependencies(Product $product): bool
    {
        return $product->purchases()->exists()
            || $product->transactionItems()->exists()
            || $product->stockAdjustments()->exists();
    }
}
