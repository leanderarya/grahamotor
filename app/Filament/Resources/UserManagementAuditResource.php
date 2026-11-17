<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserManagementAuditResource\Pages;
use App\Models\UserManagementAudit;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserManagementAuditResource extends Resource
{
    protected static ?string $model = UserManagementAudit::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Audit Akun Kasir';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 2;

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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['actor', 'target']);

        if (! (auth()->user()?->isOwner() ?? false)) {
            $query->whereHas('target', fn (Builder $target) => $target->where('role', 'kasir'));
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('actor.name')
                    ->label('Dilakukan oleh')
                    ->placeholder('Akun dihapus')
                    ->searchable(),
                Tables\Columns\TextColumn::make('target.name')
                    ->label('Akun Kasir')
                    ->placeholder('Akun dihapus')
                    ->searchable(),
                Tables\Columns\TextColumn::make('action')
                    ->label('Perubahan')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'created' => 'Akun dibuat',
                        'pin_reset' => 'PIN direset',
                        'deactivated' => 'Akun dinonaktifkan',
                        'activated' => 'Akun diaktifkan',
                        'role_changed' => 'Jabatan diubah',
                        'updated' => 'Data akun diubah',
                        'owner_provisioned' => 'Owner dibuat via CLI',
                        default => $state,
                    })
                    ->badge(),
                Tables\Columns\TextColumn::make('metadata')
                    ->label('Rincian aman')
                    ->state(fn (UserManagementAudit $record): string => collect($record->metadata ?? [])
                        ->map(fn ($value, $key): string => is_array($value)
                            ? $key.': '.implode(', ', $value)
                            : $key.': '.$value)
                        ->implode(' · '))
                    ->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada perubahan akun')
            ->emptyStateDescription('Pembuatan, reset PIN, dan deaktivasi tercatat di sini.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUserManagementAudits::route('/'),
        ];
    }
}
