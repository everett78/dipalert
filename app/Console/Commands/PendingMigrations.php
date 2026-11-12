<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Tells the start script whether a pre-migration backup is needed.
 *
 * Exit codes: 0 = nothing pending, 10 = migrations pending,
 * 20 = fresh install (no migrations table), 1 = error.
 */
class PendingMigrations extends Command
{
    const COMMAND = 'dipalert:pending-migrations';

    const NONE = 0;

    const PENDING = 10;

    const FRESH_INSTALL = 20;

    protected $signature = self::COMMAND;

    protected $description = 'Report whether database or settings migrations are pending';

    public function handle(Migrator $migrator): int
    {
        try {
            if (! Schema::hasTable('migrations')) {
                $this->line('Fresh install: no migrations table.');

                return self::FRESH_INSTALL;
            }

            $paths = array_values(array_unique(array_merge(
                [database_path('migrations')],
                $migrator->paths(),
                (array) config('settings.migrations_paths', []),
            )));

            $files = $migrator->getMigrationFiles($paths);
            $ran = $migrator->getRepository()->getRan();
            $pending = array_diff(array_keys($files), $ran);
        } catch (Throwable $e) {
            $this->error('Could not check migrations: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($pending === []) {
            $this->line('No pending migrations.');

            return self::NONE;
        }

        $this->line(count($pending).' pending migration(s).');

        return self::PENDING;
    }
}
