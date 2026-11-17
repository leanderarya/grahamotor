<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasApiTokens, Notifiable, TwoFactorAuthenticatable;

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'pin',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'pin',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    // Update method ini
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && ($this->isOwner() || $this->isAdmin());
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isCashier(): bool
    {
        return $this->is_active && $this->role === 'kasir';
    }

    public static function findCashierForPin(string $pin): ?self
    {
        $matches = static::query()
            ->where('role', 'kasir')
            ->where('is_active', true)
            ->whereNotNull('pin')
            ->get()
            ->filter(fn (self $user): bool => Hash::check($pin, $user->pin));

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public static function cashierPinExists(string $pin, ?int $ignoreUserId = null): bool
    {
        return static::query()
            ->where('role', 'kasir')
            ->whereNotNull('pin')
            ->when($ignoreUserId, fn ($query) => $query->where('id', '<>', $ignoreUserId))
            ->get(['id', 'pin'])
            ->contains(fn (self $user): bool => Hash::check($pin, $user->pin));
    }

    public function cashierSessions(): HasMany
    {
        return $this->hasMany(CashierSession::class);
    }

    public function openCashierSession(): HasOne
    {
        return $this->hasOne(CashierSession::class)
            ->whereNull('closed_at')
            ->latestOfMany('opened_at');
    }

    public function hasOpenCashierSession(): bool
    {
        return $this->openCashierSession()->exists();
    }

    public function managementAudits(): HasMany
    {
        return $this->hasMany(UserManagementAudit::class, 'target_user_id');
    }
}
