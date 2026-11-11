<?php

namespace App\Models;

use App\Models\Concerns\FiltersByComponents;
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
    use FiltersByComponents, HasFactory, Notifiable;

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
