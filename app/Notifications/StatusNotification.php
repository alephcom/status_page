<?php

namespace App\Notifications;

use App\Models\Setting;
use App\Models\Subscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Generic e-mail sent to subscribers about incidents, maintenance and
 * component changes. Content is rendered up front so the queued job does not
 * depend on the models still existing.
 */
class StatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $lines  Markdown paragraphs
     */
    public function __construct(
        public string $subject,
        public string $heading,
        public array $lines,
        public ?string $actionText = null,
        public ?string $actionUrl = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(Subscriber $notifiable): MailMessage
    {
        $site = Setting::get('site_name');

        $mail = (new MailMessage)
            ->subject("[{$site}] {$this->subject}")
            ->greeting($this->heading);

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        if ($this->actionUrl) {
            $mail->action($this->actionText ?? 'View status page', $this->actionUrl);
        }

        return $mail
            ->salutation(' ')
            ->line('[Manage or cancel your subscription]('.route('subscription.show', $notifiable).')');
    }
}
