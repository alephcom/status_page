<?php

namespace App\Models;

use App\Enums\WebhookEvent;
use App\Enums\WebhookFormat;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'url', 'secret', 'events', 'enabled', 'format'])]
#[Hidden(['secret'])]
class Webhook extends Model
{
    use Auditable;

    protected $attributes = ['format' => 'json'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'enabled' => 'boolean',
            'format' => WebhookFormat::class,
            'secret' => 'encrypted',
            'last_sent_at' => 'datetime',
        ];
    }

    public function auditExclude(): array
    {
        return ['last_status', 'last_sent_at'];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /** @param Builder<Webhook> $query */
    public function scopeListeningTo(Builder $query, WebhookEvent $event): void
    {
        $query->where('enabled', true)->whereJsonContains('events', $event->value);
    }
}
