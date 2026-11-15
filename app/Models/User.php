<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    protected static function booted(): void
    {
        // Sanctum tokens are polymorphic, so nothing cascades them.
        static::deleting(fn (User $user) => $user->tokens()->delete());
    }

    public function auditExclude(): array
    {
        return ['email_verified_at'];
    }

    public function auditLabel(): string
    {
        return $this->email;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role instanceof UserRole;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** Admins and editors; users without a role only manage their own subscription. */
    public function isStaff(): bool
    {
        return $this->role instanceof UserRole;
    }

    /**
     * The e-mail subscription for this account's address. Only trusted once
     * the address is verified, since anyone can register with any address.
     */
    public function subscription(): ?Subscriber
    {
        return Subscriber::query()->where('email', mb_strtolower($this->email))->first();
    }
}
