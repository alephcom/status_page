<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['email', 'verified_at'])]
class Subscriber extends Model
{
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Subscriber $subscriber) {
            $subscriber->token ??= Str::random(48);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    /** @param Builder<Subscriber> $query */
    public function scopeVerified(Builder $query): void
    {
        $query->whereNotNull('verified_at');
    }

    /**
     * Subscribers with no component selection get everything; otherwise only
     * notifications touching one of their components (or none at all, i.e.
     * site-wide notices).
     *
     * @param  Builder<Subscriber>  $query
     * @param  array<int>  $componentIds
     */
    public function scopeInterestedIn(Builder $query, array $componentIds): void
    {
        if ($componentIds === []) {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->whereDoesntHave('components')
            ->orWhereHas('components', fn (Builder $c) => $c->whereIn('components.id', $componentIds)));
    }

    /** @return BelongsToMany<Component, $this> */
    public function components(): BelongsToMany
    {
        return $this->belongsToMany(Component::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
