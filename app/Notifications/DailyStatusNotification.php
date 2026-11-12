<?php

namespace App\Notifications;

use App\Filament\Resources\FetchAttemptResource;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The daily status e-mail. Sent right away, not through the queue, so it
 * arrives also when the queue is stuck.
 */
class DailyStatusNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $report  from DailyStatusReport::build()
     */
    public function __construct(public array $report) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject((string) $this->report['subject'])
            ->markdown('mail.daily-status', [
                'report' => $this->report,
                'problemsUrl' => FetchAttemptResource::getUrl('index'),
            ]);
    }
}
