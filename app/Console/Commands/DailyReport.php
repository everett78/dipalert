<?php

namespace App\Console\Commands;

use App\Enums\NotificationMethods;
use App\Enums\Role;
use App\Models\User;
use App\Notifications\DailyStatusNotification;
use App\Services\DailyStatusReport;
use App\Services\Helpers\NotificationsHelper;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class DailyReport extends Command
{
    public const string COMMAND = 'dipalert:daily-report';

    /**
     * Mail server errors: 3 tries, 60 seconds apart.
     */
    public const int TRIES = 3;

    public const int RETRY_SLEEP_MS = 60000;

    protected $signature = self::COMMAND;

    protected $description = 'Send the daily status e-mail to the administrators';

    public function handle(): int
    {
        if (! SettingsHelper::getSetting('daily_report_enabled', true)) {
            $this->info('The daily status e-mail is turned off.');

            return self::SUCCESS;
        }

        if (! NotificationsHelper::isEnabled(NotificationMethods::Mail->value)) {
            Log::error('Daily status e-mail not sent: e-mail is turned off in the settings.');
            $this->error('E-mail is turned off in the settings.');

            return self::FAILURE;
        }

        $emails = User::query()->where('role', Role::Admin->value)->pluck('email')->filter()->unique();

        if ($emails->isEmpty()) {
            Log::error('Daily status e-mail not sent: no administrator.');
            $this->error('No administrator to send the e-mail to.');

            return self::FAILURE;
        }

        $report = (new DailyStatusReport)->build();

        try {
            retry(self::TRIES, function () use ($emails, $report): void {
                foreach ($emails as $email) {
                    Notification::route('mail', $email)->notifyNow(new DailyStatusNotification($report));
                }
            }, self::RETRY_SLEEP_MS);
        } catch (Throwable $e) {
            Log::error('Daily status e-mail not sent: '.$e->getMessage());
            $this->error('Could not send the e-mail: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Sent: '.$report['subject']);

        return self::SUCCESS;
    }
}
