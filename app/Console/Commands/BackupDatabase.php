<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Dump the database to a compressed file in the backup folder.
 *
 * Runs before migrations (from the start script), so it must not read or
 * write application settings: new settings may not exist in the database yet.
 */
class BackupDatabase extends Command
{
    const COMMAND = 'dipalert:backup';

    protected $signature = self::COMMAND.' {--reason=nightly : Label added to the file name}';

    protected $description = 'Back up the database to a compressed SQL file';

    public function handle(): int
    {
        $reason = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $this->option('reason'))) ?: 'manual';
        $dir = rtrim((string) config('dipalert.backup_path'), '/');
        $connection = (array) config('database.connections.'.config('database.default'));

        File::ensureDirectoryExists($dir);

        $file = $dir.'/dipalert-'.now()->format('Ymd-Hi').'-'.$reason.'.sql.gz';

        $dump = implode(' ', array_map('escapeshellarg', [
            'mariadb-dump',
            '--single-transaction',
            '--routines',
            '--host='.($connection['host'] ?? 'localhost'),
            '--port='.($connection['port'] ?? 3306),
            '--user='.($connection['username'] ?? 'root'),
            (string) ($connection['database'] ?? ''),
        ]));

        $result = Process::env(['MYSQL_PWD' => (string) ($connection['password'] ?? '')])
            ->timeout(3600)
            ->run(['bash', '-c', 'set -o pipefail; '.$dump.' | gzip > '.escapeshellarg($file)]);

        if ($result->failed() || ! File::exists($file) || File::size($file) === 0) {
            File::delete($file);
            $this->error('Backup failed: '.trim($result->errorOutput() ?: $result->output()));

            return self::FAILURE;
        }

        $this->info('Backup written: '.$file.' ('.File::size($file).' bytes)');

        $this->pruneOldBackups($dir);

        return self::SUCCESS;
    }

    /**
     * Keep the newest `dipalert.backup_keep` files, counting all reasons together.
     */
    protected function pruneOldBackups(string $dir): void
    {
        $keep = max(1, (int) config('dipalert.backup_keep', 14));

        collect(File::glob($dir.'/dipalert-*.sql.gz'))
            ->sortDesc()
            ->slice($keep)
            ->each(fn (string $old) => File::delete($old));
    }
}
