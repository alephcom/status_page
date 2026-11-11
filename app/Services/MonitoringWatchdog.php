<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\MonitoringAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/** E-mails admins when monitoring data has been stale for a while, and when it recovers. */
class MonitoringWatchdog
{
    public function __construct(private readonly StatusPageData $data) {}

    public function check(): void
    {
        $minutes = (int) Setting::get('stale_alert_minutes');
        $alertedAt = Setting::get('stale_alerted_at');
        $stale = $this->data->staleness();

        if ($stale === null) {
            if ($alertedAt) {
                Setting::put('stale_alerted_at', null);
                $this->notify(new MonitoringAlert(true, null, null));
            }

            return;
        }

        if ($minutes <= 0 || $alertedAt) {
            return;
        }

        // Without a single good poll, count from the first poll attempt.
        $since = $stale['last_ok'] ?? (($first = Setting::get('first_poll_at')) ? CarbonImmutable::parse($first) : null);

        if ($since === null || $since->greaterThan(now()->subMinutes($minutes))) {
            return;
        }

        Setting::put('stale_alerted_at', now()->toIso8601String());
        $this->notify(new MonitoringAlert(false, $stale['last_ok']?->toDayDateTimeString(), Setting::get('zabbix_last_error')));
    }

    private function notify(MonitoringAlert $alert): void
    {
        Notification::send(User::query()->where('role', UserRole::Admin->value)->get(), $alert);
    }
}
