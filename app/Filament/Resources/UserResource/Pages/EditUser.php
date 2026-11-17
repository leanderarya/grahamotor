<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\UserManagementService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_if(($data['role'] ?? null) === 'owner', 403);

        if (! auth()->user()->isOwner()) {
            $data['role'] = 'kasir';
        }

        abort_unless(in_array($data['role'] ?? null, ['admin', 'kasir'], true), 403);

        if (
            $this->record->role === 'kasir'
            && $data['role'] !== 'kasir'
            && $this->record->hasOpenCashierSession()
        ) {
            throw ValidationException::withMessages([
                'data.role' => 'Akun kasir dengan shift terbuka tidak dapat dipindahkan ke jabatan lain.',
            ]);
        }

        if (
            $data['role'] === 'kasir'
            && filled($data['pin'] ?? null)
            && User::cashierPinExists((string) $data['pin'], $this->record->id)
        ) {
            throw ValidationException::withMessages([
                'data.pin' => 'PIN sudah dipakai oleh kasir lain.',
            ]);
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(UserManagementService::class)->update(auth()->user(), $record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
