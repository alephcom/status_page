<?php

namespace App\Providers;

use App\Ai\ClaudeIncidentWriter;
use App\Ai\IncidentWriter;
use App\Http\Middleware\SetLocale;
use App\Models\Setting;
use App\StatusSources\StatusSourceManager;
use App\Zabbix\ZabbixClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ZabbixClient::class, fn () => ZabbixClient::fromConfig());
        $this->app->singleton(StatusSourceManager::class);
        $this->app->singleton(IncidentWriter::class, fn () => ClaudeIncidentWriter::fromConfig());
    }

    public function boot(): void
    {
        // Telegram allows about 30 messages per second per bot.
        RateLimiter::for('telegram', fn () => Limit::perSecond(25));

        View::composer('layouts.public', function ($view) {
            $until = Setting::get('announcement_until');
            $logo = Setting::get('logo_path');

            $view->with('branding', [
                'logo' => $logo ? Storage::disk('public')->url($logo) : null,
                'color' => preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) Setting::get('brand_color')) ? Setting::get('brand_color') : null,
                'css' => Setting::get('custom_css'),
                'theme' => in_array(Setting::get('default_theme'), ['light', 'dark'], true) ? Setting::get('default_theme') : 'auto',
                'footer_links' => array_values(array_filter((array) Setting::get('footer_links'), fn ($l) => filled($l['name'] ?? null) && filled($l['url'] ?? null))),
                'announcement' => filled(Setting::get('announcement')) && (! $until || now()->lessThan($until)) ? Setting::get('announcement') : null,
                'announcement_level' => in_array(Setting::get('announcement_level'), ['info', 'warning', 'danger'], true) ? Setting::get('announcement_level') : 'info',
                'locales' => array_intersect_key(SetLocale::AVAILABLE, array_flip(SetLocale::enabled())),
            ]);
        });
    }
}
