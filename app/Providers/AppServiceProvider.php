<?php

namespace App\Providers;

use App\StatusSources\StatusSourceManager;
use App\Zabbix\ZabbixClient;
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
        //
    }
}
