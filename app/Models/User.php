<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, HasRoles, Notifiable;

    /** Spatie roles and permissions are checked against the web guard, also for API tokens. */
    protected string $guard_name = 'web';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
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
        return $this->isStaff();
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(UserRole::Admin->value);
    }

    /** Admins and editors; users without a role only manage their own subscription. */
    public function isStaff(): bool
    {
        return $this->allows(Permission::AccessAdmin);
    }

    public function allows(Permission $permission): bool
    {
        return $this->hasPermissionTo($permission->value);
    }

    /** The user's role (each user has at most one), or null for public accounts. */
    protected function role(): Attribute
    {
        return Attribute::get(fn (): ?UserRole => $this->roles
            ->map(fn ($role) => UserRole::tryFrom($role->name))
            ->filter()
            ->sortBy(fn (UserRole $r) => array_search($r, UserRole::cases(), true))
            ->first());
    }

    /** Replace the user's role; null makes it a public account. */
    public function setRole(?UserRole $role): static
    {
        $this->syncRoles($role ? [$role->value] : []);
        $this->unsetRelation('roles');

        return $this;
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
