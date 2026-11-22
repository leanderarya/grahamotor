<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShiftOverrideAuditResource\Pages;
use App\Models\ShiftOverrideAudit;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ShiftOverrideAuditResource extends Resource
{
    protected static ?string $model = ShiftOverrideAudit::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Audit Override Shift';

    protected static ?string $navigationGroup = 'Operasional';

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

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['actor', 'cashierSession.user']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu override')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('actor.name')
                    ->label('Admin / Owner')
                    ->placeholder('Akun dihapus')
                    ->searchable(),
                Tables\Columns\TextColumn::make('cashierSession.user.name')
                    ->label('Kasir')
                    ->placeholder('Akun dihapus')
                    ->searchable(),
                Tables\Columns\TextColumn::make('cashierSession.opened_at')
                    ->label('Shift dibuka')
                    ->dateTime('d M Y, H:i'),
                Tables\Columns\TextColumn::make('closing_cash_physical')
                    ->label('Uang fisik')
                    ->money('IDR'),
                Tables\Columns\TextColumn::make('expected_cash')
                    ->label('Kas yang diharapkan')
                    ->money('IDR'),
                Tables\Columns\TextColumn::make('cash_difference')
                    ->label('Selisih')
                    ->money('IDR'),
                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->wrap()
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada override shift')
            ->emptyStateDescription('Override yang dilakukan akan tersimpan di audit ini.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShiftOverrideAudits::route('/'),
        ];
    }
}
