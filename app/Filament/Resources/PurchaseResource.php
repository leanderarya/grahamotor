<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseResource\Pages;
use App\Models\Purchase;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class PurchaseResource extends Resource
{
    protected static ?string $model = Purchase::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Pembelian';

    protected static ?string $navigationGroup = 'Inventaris';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isOwner() || auth()->user()?->isAdmin();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\DatePicker::make('date')
                    ->label('Tanggal Belanja')
                    ->required()
                    ->default(now()),

                \Filament\Forms\Components\TextInput::make('supplier_name')
                    ->label('Supplier / Toko')
                    ->placeholder('Contoh: Toko Bintang Mas'),

                \Filament\Forms\Components\Select::make('product_id')
                    ->relationship('product', 'name')
                    ->label('Produk')
                    ->searchable()
                    ->required(),

                // --- PERBAIKAN DI SINI (Quantity) ---
                \Filament\Forms\Components\TextInput::make('quantity')
                    ->label('Jumlah (Qty)')
                    ->numeric()
                    ->required()
                    ->live(debounce: 500) // Tunda 500ms agar tidak lag saat ketik cepat
                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                        $price = (float) $get('buy_price_per_unit');
                        $qty = (int) $state;
                        $set('total_spend', $price * $qty);
                    }),

                // --- PERBAIKAN DI SINI (Harga) ---
                \Filament\Forms\Components\TextInput::make('buy_price_per_unit')
                    ->label('Harga Beli Satuan')
                    ->numeric()
                    ->required()
                    ->live(debounce: 500) // Tunda 500ms
                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                        $qty = (int) $get('quantity');
                        $price = (float) $state;
                        $set('total_spend', $price * $qty);
                    }),

                \Filament\Forms\Components\TextInput::make('total_spend')
                    ->label('Total Modal Keluar')
                    ->numeric()
                    ->readOnly()
                    ->prefix('Rp')
                    // Tambahkan 'dehydrated' agar nilai readOnly tetap terkirim ke database saat save
                    ->dehydrated(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('date')->label('Tanggal')->date('d M Y')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('product.name')->label('Barang'),
                \Filament\Tables\Columns\TextColumn::make('quantity')->label('Qty'),
                \Filament\Tables\Columns\TextColumn::make('buy_price_per_unit')
                    ->label('Harga Satuan')
                    ->money('IDR'),
                \Filament\Tables\Columns\TextColumn::make('total_spend')
                    ->label('Modal Keluar')
                    ->money('IDR')
                    ->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()->label('Total')),
            ])
            ->defaultSort('date', 'desc');
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
            'index' => Pages\ListPurchases::route('/'),
            'create' => Pages\CreatePurchase::route('/create'),
            'edit' => Pages\EditPurchase::route('/{record}/edit'),
        ];
    }
}
