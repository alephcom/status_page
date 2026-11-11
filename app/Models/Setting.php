<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Small key/value store for settings edited in the admin panel and for
 * runtime state such as the last successful poll.
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    use Auditable;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public const DEFAULTS = [
        'site_name' => 'Service status',
        'site_about' => null,
        'external_links' => [],
        'history_days' => 7,
        'auto_incidents' => false,
        'auto_resolve_recovered_drafts' => true,
        'ai_drafts' => true,
        'notify_component_changes' => false,
        'maintenance_reminder_minutes' => 60,
        'notify_maintenance_lifecycle' => true,
        'stale_alert_minutes' => 10,
        // Branding
        'logo_path' => null,
        'brand_color' => null,
        'custom_css' => null,
        'footer_links' => [],
        'default_theme' => 'auto',
        'announcement' => null,
        'announcement_level' => 'info',
        'announcement_until' => null,
        // Languages offered on the public page
        'default_locale' => 'en',
        'enabled_locales' => ['en'],
        // Networks (CIDR) that may see private components without signing in
        'private_ip_ranges' => [],
    ];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    /** Only settings edited by people; runtime state (last poll etc.) is noise. */
    public function shouldAudit(): bool
    {
        return array_key_exists($this->key, self::DEFAULTS);
    }

    public function auditLabel(): string
    {
        return $this->key;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever('settings', fn () => static::query()->pluck('value', 'key')->all());

        return $all[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('settings');
    }

    /** @param array<string, mixed> $values */
    public static function putMany(array $values): void
    {
        foreach ($values as $key => $value) {
            static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget('settings');
    }
}
