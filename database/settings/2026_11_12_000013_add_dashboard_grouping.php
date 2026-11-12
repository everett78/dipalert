<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Dashboard grouping: by collections (DipAlert default) or by tags.
     */
    public function up(): void
    {
        if (! $this->migrator->exists('app.dashboard_grouping')) {
            $this->migrator->add('app.dashboard_grouping', 'collections');
        }
    }
};
