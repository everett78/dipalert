<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Daily status e-mail, and per-product failure e-mails (off: the daily
     * e-mail reports failures).
     */
    public function up(): void
    {
        $settings = [
            'app.daily_report_enabled' => true,
            'app.daily_report_time' => '07:30',
            'app.per_product_failure_alerts' => false,
        ];

        foreach ($settings as $name => $value) {
            if (! $this->migrator->exists($name)) {
                $this->migrator->add($name, $value);
            }
        }
    }
};
