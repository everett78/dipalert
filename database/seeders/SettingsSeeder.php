<?php

namespace Database\Seeders;

use App\Enums\NotificationMethods;
use App\Models\User;
use App\Settings\AppSettings;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $appSettings = AppSettings::new();

        // Enable global email.
        $appSettings->notification_services = [
            // Lando testing of mail via http://mail.price-buddy.lndo.site
            NotificationMethods::Mail->value => [
                'enabled' => true,
                'smtp_host' => 'mailhog',
                'smtp_port' => 1025,
            ],
        ];
        $appSettings->save();

        // Enable for the first user.
        $user = User::first();
        if ($user) {
            $settings = $user->settings;
            data_set($settings, 'notifications.'.NotificationMethods::Mail->value.'.enabled', true);
            $user->update(['settings' => $settings]);
        }
    }
}
