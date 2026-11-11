<?php

namespace App\Notifications;

use App\Models\Setting;
use App\Models\Subscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifySubscription extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(Subscriber $notifiable): MailMessage
    {
        $site = Setting::get('site_name');

        if ($notifiable->isVerified()) {
            return (new MailMessage)
                ->subject("[{$site}] Manage your subscription")
                ->line('Someone (hopefully you) asked to subscribe this address to status updates. You are already subscribed.')
                ->action('Manage subscription', route('subscription.show', $notifiable));
        }

        return (new MailMessage)
            ->subject("[{$site}] Confirm your subscription")
            ->line('Please confirm that you want to receive status updates at this address.')
            ->action('Confirm subscription', route('subscription.verify', $notifiable))
            ->line('If you did not ask for this, you can ignore this e-mail.');
    }
}
