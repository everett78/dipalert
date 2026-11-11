<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Channels removed in DipAlert. Only "mail" (and the in-app history) stay.
     */
    private const REMOVED = ['pushover', 'gotify', 'apprise', 'telegram', 'discord', 'ntfy'];

    public function up(): void
    {
        DB::table('users')->select(['id', 'settings'])->orderBy('id')->each(function ($user) {
            $settings = json_decode((string) $user->settings, true);

            if (! is_array($settings) || ! is_array($settings['notifications'] ?? null)) {
                return;
            }

            $notifications = array_diff_key($settings['notifications'], array_flip(self::REMOVED));

            if ($notifications === $settings['notifications']) {
                return;
            }

            $settings['notifications'] = $notifications;

            DB::table('users')->where('id', $user->id)->update(['settings' => json_encode($settings)]);
        });
    }

    public function down(): void
    {
        // Removed settings cannot be restored.
    }
};
