<?php

namespace Tests\Feature\Console;

use App\Console\Commands\PendingMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PendingMigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_nothing_pending_after_migrating()
    {
        $this->artisan(PendingMigrations::COMMAND)->assertExitCode(PendingMigrations::NONE);
    }

    public function test_pending_database_migration_is_reported()
    {
        DB::table('migrations')->where('migration', '2026_11_11_000002_add_indexes_to_prices_table')->delete();

        $this->artisan(PendingMigrations::COMMAND)->assertExitCode(PendingMigrations::PENDING);
    }

    public function test_pending_settings_migration_is_reported()
    {
        DB::table('migrations')->where('migration', '2026_11_11_000001_dipalert_base_settings')->delete();

        $this->artisan(PendingMigrations::COMMAND)->assertExitCode(PendingMigrations::PENDING);
    }

    public function test_fresh_install_without_migrations_table()
    {
        // Simulate a fresh install. Dropping the real table would leak into
        // later tests: DDL is not rolled back by the test transaction.
        Schema::shouldReceive('hasTable')->with('migrations')->andReturn(false);

        $this->artisan(PendingMigrations::COMMAND)->assertExitCode(PendingMigrations::FRESH_INSTALL);
    }
}
