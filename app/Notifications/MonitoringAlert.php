<?php

namespace App\Notifications;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells admins that the status page lost (or regained) its monitoring data. */
class MonitoringAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public bool $recovered, public ?string $lastOk, public ?string $error) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = Setting::get('site_name');

        if ($this->recovered) {
            return (new MailMessage)
                ->subject("[{$site}] Monitoring data is up to date again")
                ->line('The status page is receiving monitoring data again.')
                ->action('Open the admin panel', url('/admin'));
        }

        return (new MailMessage)
            ->error()
            ->subject("[{$site}] Monitoring data is stale")
            ->line($this->lastOk
                ? "The status page has not had a successful poll since {$this->lastOk}. The public page shows a \"may be out of date\" notice."
                : 'The status page has not had a successful poll yet.')
            ->line($this->error ? "Last error: {$this->error}" : 'Check that the scheduler (`php artisan schedule:work`) is running.')
            ->action('Open the admin panel', url('/admin'));
    }
}
