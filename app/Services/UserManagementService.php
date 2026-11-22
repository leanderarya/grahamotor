<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserManagementAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    public function create(User $actor, array $attributes): User
    {
        return DB::transaction(function () use ($actor, $attributes): User {
            $actor = $this->lockActor($actor->id);
            $role = $attributes['role'] ?? null;

            if ($role === 'owner' || ! in_array($role, ['admin', 'kasir'], true)) {
                abort(403);
            }

            if ($actor->isAdmin() && $role !== 'kasir') {
                abort(403);
            }

            if (! $actor->isAdmin() && ! $actor->isOwner()) {
                abort(403);
            }

            if ($role === 'kasir') {
                $this->assertValidPin($attributes['pin'] ?? null);
                $this->assertPinAvailable((string) $attributes['pin']);
            }

            if ($role === 'admin' && (! filled($attributes['email'] ?? null) || ! filled($attributes['password'] ?? null))) {
                throw ValidationException::withMessages([
                    'data.email' => 'Email dan password wajib diisi untuk akun Admin.',
                ]);
            }

            $user = User::create($attributes);

            $this->audit($actor, $user, 'created', ['role' => $user->role]);

            return $user;
        }, 3);
    }

    public function update(User $actor, User $target, array $attributes): User
    {
        return DB::transaction(function () use ($actor, $target, $attributes): User {
            $actor = $this->lockActor($actor->id);
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            $this->authorizeTarget($actor, $target);

            $newRole = $attributes['role'] ?? $target->role;
            if ($newRole === 'owner' || ! in_array($newRole, ['admin', 'kasir'], true)) {
                abort(403);
            }

            if ($actor->isAdmin() && ($target->role !== 'kasir' || $newRole !== 'kasir')) {
                abort(403);
            }

            if (
                $target->role === 'kasir'
                && $newRole !== 'kasir'
                && $target->hasOpenCashierSession()
            ) {
                throw ValidationException::withMessages([
                    'data.role' => 'Akun kasir dengan shift terbuka tidak dapat dipindahkan ke jabatan lain.',
                ]);
            }

            $pinReset = filled($attributes['pin'] ?? null);
            if ($newRole === 'kasir') {
                if ($pinReset) {
                    $this->assertValidPin($attributes['pin']);
                    $this->assertPinAvailable((string) $attributes['pin'], $target->id);
                } elseif ($target->role !== 'kasir' || ! filled($target->pin)) {
                    throw ValidationException::withMessages([
                        'data.pin' => 'PIN 4 digit wajib diisi untuk akun Kasir.',
                    ]);
                }
            }

            $previousRole = $target->role;
            $changedFields = array_values(array_filter(
                ['name', 'email', 'role'],
                fn (string $field): bool => array_key_exists($field, $attributes)
                    && $target->getAttribute($field) !== $attributes[$field],
            ));

            $target->update($attributes);

            if ($pinReset) {
                $this->audit($actor, $target, 'pin_reset');
            }

            if ($previousRole !== $target->role) {
                $this->audit($actor, $target, 'role_changed', [
                    'from' => $previousRole,
                    'to' => $target->role,
                ]);
            } elseif ($changedFields !== [] && ! $pinReset) {
                $this->audit($actor, $target, 'updated', ['fields' => $changedFields]);
            }

            return $target;
        }, 3);
    }

    public function deactivate(User $actor, User $target): void
    {
        $this->changeActiveState($actor, $target, false);
    }

    public function activate(User $actor, User $target): void
    {
        $this->changeActiveState($actor, $target, true);
    }

    private function changeActiveState(User $actor, User $target, bool $isActive): void
    {
        DB::transaction(function () use ($actor, $target, $isActive): void {
            $actor = $this->lockActor($actor->id);

            if ($isActive && ! $actor->isOwner()) {
                abort(403);
            }

            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            $this->authorizeTarget($actor, $target);

            if ($target->is_active === $isActive) {
                return;
            }

            if (! $isActive && $target->role === 'kasir' && $target->hasOpenCashierSession()) {
                throw ValidationException::withMessages([
                    'user' => 'Tutup shift kasir sebelum menonaktifkan akunnya.',
                ]);
            }

            $target->forceFill(['is_active' => $isActive])->save();
            $this->audit($actor, $target, $isActive ? 'activated' : 'deactivated');
        }, 3);
    }

    private function authorizeTarget(User $actor, User $target): void
    {
        if ((! $actor->isOwner() && ! $actor->isAdmin()) || ! $actor->is_active) {
            abort(403);
        }

        if ($target->role === 'owner' || $target->id === $actor->id) {
            abort(403);
        }

        if ($actor->isAdmin() && $target->role !== 'kasir') {
            abort(403);
        }
    }

    private function lockActor(int $actorId): User
    {
        $actor = User::query()->whereKey($actorId)->lockForUpdate()->firstOrFail();

        if (! $actor->is_active || (! $actor->isOwner() && ! $actor->isAdmin())) {
            abort(403);
        }

        return $actor;
    }

    private function audit(User $actor, User $target, string $action, array $metadata = []): void
    {
        UserManagementAudit::create([
            'actor_id' => $actor->id,
            'target_user_id' => $target->id,
            'action' => $action,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);
    }

    private function assertValidPin(mixed $pin): void
    {
        if (! is_string($pin) || ! preg_match('/^\d{4}$/', $pin)) {
            throw ValidationException::withMessages([
                'data.pin' => 'PIN Kasir harus terdiri dari 4 digit angka.',
            ]);
        }
    }

    private function assertPinAvailable(string $pin, ?int $ignoreUserId = null): void
    {
        if (User::cashierPinExists($pin, $ignoreUserId)) {
            throw ValidationException::withMessages([
                'data.pin' => 'PIN sudah dipakai oleh kasir lain.',
            ]);
        }
    }
}
