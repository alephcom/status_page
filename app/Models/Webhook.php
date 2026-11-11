<?php

namespace App\Models;

use App\Enums\WebhookEvent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'url', 'secret', 'events', 'enabled'])]
#[Hidden(['secret'])]
class Webhook extends Model
{
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'enabled' => 'boolean',
            'secret' => 'encrypted',
            'last_sent_at' => 'datetime',
        ];
    }

    /** @param Builder<Webhook> $query */
    public function scopeListeningTo(Builder $query, WebhookEvent $event): void
    {
        $query->where('enabled', true)->whereJsonContains('events', $event->value);
    }
}
