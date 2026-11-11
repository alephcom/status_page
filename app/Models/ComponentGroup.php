<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'order', 'collapsed'])]
class ComponentGroup extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['collapsed' => 'boolean', 'order' => 'integer'];
    }

    /** @return HasMany<Component, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(Component::class)->orderBy('order')->orderBy('name');
    }
}
