<?php

namespace Tests\Unit\Services;

use App\Enums\NotificationMethods;
use App\Models\User;
use App\Services\Helpers\NotificationsHelper;
use App\Services\Helpers\SettingsHelper;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationsHelperTest extends TestCase
{
    use RefreshDatabase;

    protected array $testSettings = [
        NotificationMethods::Mail->value => [
            'enabled' => true,
            'smtp_host' => 'my.smtp.com',
            'smtp_port' => '25',
            'smtp_user' => 'mailuser',
            'smtp_password' => 'mailpass',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setGlobalSettings($this->testSettings);
    }

    public function test_get_services_returns_correct_collection()
    {
        $services = NotificationsHelper::getServices();

        $this->assertCount(1, $services);
        $this->assertArrayHasKey(NotificationMethods::Mail->value, $services);
        $this->assertEquals('mail', $services['mail']['channel']);
    }

    public function test_removed_services_in_old_settings_are_ignored()
    {
        // Settings saved by PriceBuddy may still contain removed channels.
        $this->setGlobalSettings(array_merge($this->testSettings, [
            'pushover' => ['enabled' => true, 'token' => 'old'],
            'ntfy' => ['enabled' => true, 'topic' => 'old'],
        ]));

        $services = NotificationsHelper::getServices();

        $this->assertSame(['mail'], $services->keys()->toArray());
    }

    public function test_get_all_notification_services()
    {
        $services = NotificationsHelper::getServices();
        $this->assertIsArray($services->toArray());
        $this->assertSame('my.smtp.com', $services->get('mail')['smtp_host']);

        foreach ($services->keys() as $service) {
            $this->assertArrayHasKey('enabled', $services[$service]);
        }
    }

    public function test_get_all_enabled_notification_services()
    {
        $this->assertSame(['mail'], NotificationsHelper::getEnabled()->keys()->toArray());
    }

    public function test_get_notification_service_setting()
    {
        $this->assertSame('my.smtp.com', NotificationsHelper::getSetting('mail', 'smtp_host'));
    }

    public function test_get_user_services_returns_correct_collection()
    {
        $user = User::factory()->create([
            'settings' => ['notifications' => [NotificationMethods::Mail->value => ['enabled' => true]]],
        ]);

        $userServices = NotificationsHelper::getUserServices($user);

        $this->assertCount(1, $userServices);
        $this->assertArrayHasKey('mail', $userServices);
        $this->assertTrue($userServices['mail']['enabled']);
    }

    public function test_get_user_enabled_returns_true_for_enabled_service()
    {
        $user = User::factory()->create([
            'settings' => ['notifications' => [NotificationMethods::Mail->value => ['enabled' => true]]],
        ]);

        $this->assertTrue(NotificationsHelper::getUserEnabled($user, NotificationMethods::Mail->value));
        $this->assertTrue(NotificationsHelper::getUserEnabled($user, NotificationMethods::Database->value));
    }

    public function test_is_enabled_returns_false_for_disabled_service()
    {
        $this->setGlobalSettings([NotificationMethods::Mail->value => ['enabled' => false]]);

        $this->assertFalse(NotificationsHelper::isEnabled(NotificationMethods::Mail->value));
    }

    public function test_get_enabled_channels_respects_user_setting()
    {
        $enabledUser = User::factory()->create([
            'settings' => ['notifications' => [NotificationMethods::Mail->value => ['enabled' => true]]],
        ]);
        $disabledUser = User::factory()->create([
            'settings' => ['notifications' => [NotificationMethods::Mail->value => ['enabled' => false]]],
        ]);

        $this->assertSame(['mail'], NotificationsHelper::getEnabledChannels($enabledUser)->values()->toArray());
        $this->assertSame([], NotificationsHelper::getEnabledChannels($disabledUser)->values()->toArray());
    }

    public function test_get_settings_returns_correct_settings()
    {
        $this->setGlobalSettings([
            NotificationMethods::Mail->value => ['enabled' => true, 'host' => 'mail_host'],
        ]);

        $settings = NotificationsHelper::getSettings(NotificationMethods::Mail->value);

        $this->assertTrue($settings['enabled']);
        $this->assertEquals('mail_host', $settings['host']);
        $this->assertArrayHasKey('channel', $settings);
    }

    public function test_get_setting_returns_default_value_if_not_set()
    {
        $value = NotificationsHelper::getSetting(NotificationMethods::Mail->value, 'nonexistent', 'default_value');

        $this->assertEquals('default_value', $value);
    }

    protected function setGlobalSettings(array $settings): void
    {
        AppSettings::new()->fill(['notification_services' => $settings])->save();
        SettingsHelper::$settings = null; // Force reload on next access
    }
}
