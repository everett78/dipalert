<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Drop settings of features removed in DipAlert: the SearXNG search and
     * notification channels other than e-mail.
     */
    private const REMOVED_CHANNELS = ['pushover', 'gotify', 'apprise', 'telegram', 'discord', 'ntfy'];

    public function up(): void
    {
        if ($this->exists('notification_services')) {
            $this->migrator->update(
                'app.notification_services',
                fn ($services) => is_array($services)
                    ? array_diff_key($services, array_flip(self::REMOVED_CHANNELS))
                    : $services
            );
        }

        if ($this->exists('integrated_services')) {
            $this->migrator->update(
                'app.integrated_services',
                fn ($services) => is_array($services)
                    ? array_diff_key($services, ['searxng' => true])
                    : $services
            );
        }
    }

    public function down(): void
    {
        // Removed settings cannot be restored.
    }

    private function exists(string $name): bool
    {
        return DB::table('settings')->where('group', 'app')->where('name', $name)->exists();
    }
};
