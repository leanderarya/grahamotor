<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\UserManagementService;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        abort_if(($data['role'] ?? null) === 'owner', 403);

        if (! auth()->user()->isOwner()) {
            $data['role'] = 'kasir';
        }

        abort_unless(in_array($data['role'] ?? null, ['admin', 'kasir'], true), 403);

        if (
            $data['role'] === 'kasir'
            && filled($data['pin'] ?? null)
            && User::cashierPinExists((string) $data['pin'])
        ) {
            throw ValidationException::withMessages([
                'data.pin' => 'PIN sudah dipakai oleh kasir lain.',
            ]);
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(UserManagementService::class)->create(auth()->user(), $data);
    }
}
