<?php

namespace App\Notifications;

use Filament\Notifications\Notification as DatabaseNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class CreateProductFailedNotification extends Notification
{
    use Queueable;

    public function __construct(public string $url) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['url' => $this->url];
    }

    public function toDatabase($notifiable): array
    {
        return DatabaseNotification::make()
            ->title(__('Unable to create a product'))
            ->body(__(':url has no price, or no store matches it.', ['url' => Str::limit($this->url, 200)]))
            ->status('warning')
            ->getDatabaseMessage();
    }
}
