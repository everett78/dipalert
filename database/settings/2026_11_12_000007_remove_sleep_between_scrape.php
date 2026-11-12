<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * The fixed pause after every product is replaced by a per-store interval
     * kept by the pacing proxy (config dipalert.default_min_interval_seconds).
     */
    public function up(): void
    {
        if ($this->migrator->exists('app.sleep_seconds_between_scrape')) {
            $this->migrator->delete('app.sleep_seconds_between_scrape');
        }
    }
};
