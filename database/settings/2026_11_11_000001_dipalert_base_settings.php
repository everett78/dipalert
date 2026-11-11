<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * DipAlert reads every scheduled run fresh (stage 2), so pages cached from
     * the panel only need to live a short while: 60 minutes instead of 12 hours.
     */
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'app')->where('name', 'scrape_cache_ttl')->exists()) {
            $this->migrator->update('app.scrape_cache_ttl', fn () => 60);
        }
    }

    public function down(): void
    {
        if (DB::table('settings')->where('group', 'app')->where('name', 'scrape_cache_ttl')->exists()) {
            $this->migrator->update('app.scrape_cache_ttl', fn () => 720);
        }
    }
};
