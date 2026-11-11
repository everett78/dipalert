<?php

namespace Tests\Unit\Services;

use App\Services\Helpers\SettingsHelper;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SettingsHelperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsHelper::$settings = null;
    }

    public function test_get_settings_returns_cached_settings()
    {
        SettingsHelper::$settings = ['key' => 'value'];
        $this->assertEquals(['key' => 'value'], SettingsHelper::getSettings());
    }

    public function test_get_settings_returns_empty_array_if_no_table()
    {
        SettingsHelper::$settings = [];
        Schema::shouldReceive('hasTable')->with('settings')->andReturn(false);
        $this->assertEquals([], SettingsHelper::getSettings());
    }

    public function test_get_settings_returns_settings_from_database()
    {
        AppSettings::new()->fill(['scrape_cache_ttl' => 60])->save();
        $this->assertEquals(60, SettingsHelper::getSettings()['scrape_cache_ttl']);
    }

    public function test_get_setting_returns_value_if_exists()
    {
        AppSettings::new()->fill(['scrape_cache_ttl' => 61])->save();
        $this->assertEquals(61, SettingsHelper::getSetting('scrape_cache_ttl'));
    }

    public function test_get_setting_returns_default_if_not_exists()
    {
        $this->assertEquals('default', SettingsHelper::getSetting('nonexistent_key', 'default'));
    }
}
