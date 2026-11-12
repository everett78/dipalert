<?php

namespace Tests\Feature\Console;

use App\Console\Commands\BackupDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class BackupDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/backups-'.uniqid());
        config(['dipalert.backup_path' => $this->dir, 'dipalert.backup_keep' => 14]);
        Carbon::setTestNow('2026-11-11 03:00:00');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Fake a successful dump: the fake "writes" the gzip file the real pipe would.
     */
    protected function fakeSuccessfulDump(): void
    {
        Process::fake(function (PendingProcess $process) {
            preg_match("/gzip > '([^']+)'/", implode(' ', (array) $process->command), $m);
            File::put($m[1], 'dump');

            return Process::result();
        });
    }

    public function test_dump_uses_password_from_environment_and_names_the_file()
    {
        $this->fakeSuccessfulDump();

        $this->artisan(BackupDatabase::COMMAND, ['--reason' => 'pre-migration'])->assertExitCode(0);

        $expected = $this->dir.'/dipalert-20261111-0300-pre-migration.sql.gz';
        $this->assertFileExists($expected);

        Process::assertRan(function (PendingProcess $process) {
            $command = implode(' ', (array) $process->command);

            return str_contains($command, 'mariadb-dump')
                && str_contains($command, '--single-transaction')
                && ! str_contains($command, 'password')
                && array_key_exists('MYSQL_PWD', $process->environment);
        });
    }

    public function test_keeps_only_the_newest_fourteen_backups()
    {
        File::ensureDirectoryExists($this->dir);
        foreach (range(1, 15) as $day) {
            File::put($this->dir.sprintf('/dipalert-202610%02d-0300-nightly.sql.gz', $day), 'old');
        }

        $this->fakeSuccessfulDump();

        $this->artisan(BackupDatabase::COMMAND)->assertExitCode(0);

        $files = File::glob($this->dir.'/dipalert-*.sql.gz');
        $this->assertCount(14, $files);
        $this->assertFileExists($this->dir.'/dipalert-20261111-0300-nightly.sql.gz');
        $this->assertFileDoesNotExist($this->dir.'/dipalert-20261001-0300-nightly.sql.gz');
    }

    public function test_failed_dump_returns_error_and_leaves_no_file()
    {
        Process::fake(fn () => Process::result(errorOutput: 'Access denied', exitCode: 2));

        $this->artisan(BackupDatabase::COMMAND)->assertExitCode(1);

        $this->assertSame([], File::glob($this->dir.'/dipalert-*.sql.gz'));
    }

    public function test_backup_works_when_settings_are_missing_in_the_database()
    {
        // Before migrations run, new settings may not exist yet. The backup
        // must not read or write settings, or the start script would stop.
        DB::table('settings')->delete();

        $this->fakeSuccessfulDump();

        $this->artisan(BackupDatabase::COMMAND, ['--reason' => 'pre-migration'])->assertExitCode(0);
    }
}
