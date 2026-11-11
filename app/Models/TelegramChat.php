<?php

namespace App\Models;

use App\Models\Concerns\FiltersByComponents;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A Telegram chat that receives the same notifications as e-mail
 * subscribers: a private chat or group that sent /start to the bot, or a
 * channel/group an admin added.
 */
#[Fillable(['chat_id', 'type', 'title', 'added_by_admin', 'last_error'])]
class TelegramChat extends Model
{
    use FiltersByComponents;

    protected $attributes = ['added_by_admin' => false];

    protected function casts(): array
    {
        return ['added_by_admin' => 'boolean'];
    }

    /** @return BelongsToMany<Component, $this> */
    public function components(): BelongsToMany
    {
        return $this->belongsToMany(Component::class);
    }

    public function label(): string
    {
        return $this->title ?: $this->chat_id;
    }
}
