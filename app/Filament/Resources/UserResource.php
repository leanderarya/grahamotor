<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Services\UserManagementService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    // Ganti ikon jadi ikon Orang/Group
    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Pengguna & Pegawai';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isOwner() || auth()->user()?->isAdmin();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->where('role', '!=', 'owner');

        if (! auth()->user()?->isOwner()) {
            $query->where('role', 'kasir');
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('role')
                    ->label('Jabatan')
                    ->options(fn (): array => auth()->user()?->isOwner() ? [
                        'admin' => 'Administrator',
                        'kasir' => 'Kasir (Hanya POS)',
                    ] : [
                        'kasir' => 'Kasir (Hanya POS)',
                    ])
                    ->required()
                    ->default('kasir')
                    ->live(),

                Forms\Components\TextInput::make('name')
                    ->label('Nama Lengkap')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required(fn (Forms\Get $get): bool => $get('role') === 'admin')
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->visible(fn (Forms\Get $get): bool => $get('role') === 'admin'),

                Forms\Components\TextInput::make('pin')
                    ->label('PIN Login')
                    ->password()
                    ->revealable(false)
                    ->numeric()
                    ->length(4)
                    ->required(fn (Forms\Get $get, string $context): bool => $get('role') === 'kasir' && $context === 'create')
                    ->dehydrated(fn ($state): bool => filled($state))
                    ->formatStateUsing(fn (): null => null)
                    ->visible(fn (Forms\Get $get): bool => $get('role') === 'kasir')
                    ->helperText('4 digit angka untuk login kasir'),

                Forms\Components\TextInput::make('password')
                    ->password()
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (Forms\Get $get, string $context): bool => $context === 'create' && $get('role') === 'admin')
                    ->visible(fn (Forms\Get $get): bool => $get('role') === 'admin')
                    ->label('Password'),
            ]);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && $record->role !== 'owner'
            && (auth()->user()?->isOwner() || $record->role === 'kasir');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        'staff' => 'info',
                        'kasir' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'admin' => 'Admin',
                        'staff' => 'Staff',
                        'kasir' => 'Kasir',
                        default => $state,
                    }),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Status akun')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-no-symbol')
                    ->trueColor('success')
                    ->falseColor('danger'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('deactivate')
                    ->label('Nonaktifkan akun')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Akun tidak dapat login lagi. Riwayat transaksi dan shift tetap tersimpan.')
                    ->visible(fn (User $record): bool => $record->is_active && static::canDeactivate($record))
                    ->action(function (User $record, UserManagementService $service): void {
                        $service->deactivate(auth()->user(), $record);
                        Notification::make()->title('Akun dinonaktifkan')->success()->send();
                    }),
                Tables\Actions\Action::make('activate')
                    ->label('Aktifkan akun')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => ! $record->is_active
                        && auth()->user()?->isOwner()
                        && $record->role !== 'owner')
                    ->action(function (User $record, UserManagementService $service): void {
                        $service->activate(auth()->user(), $record);
                        Notification::make()->title('Akun diaktifkan')->success()->send();
                    }),
            ]);
    }

    public static function canDeactivate(User $record): bool
    {
        $actor = auth()->user();

        return $record->role !== 'owner'
            && $record->id !== auth()->id()
            && ($actor?->isOwner() || ($actor?->isAdmin() && $record->role === 'kasir'))
            && ! $record->hasOpenCashierSession();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
