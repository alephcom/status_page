<?php

namespace App\Providers;

use App\StatusSources\StatusSourceManager;
use App\Zabbix\ZabbixClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ZabbixClient::class, fn () => ZabbixClient::fromConfig());
        $this->app->singleton(StatusSourceManager::class);
    }

    public function boot(): void
    {
        // Telegram allows about 30 messages per second per bot.
        RateLimiter::for('telegram', fn () => Limit::perSecond(25));
    }
}
