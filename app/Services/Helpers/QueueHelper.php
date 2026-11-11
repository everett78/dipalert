<?php

namespace App\Services\Helpers;

use Illuminate\Support\Facades\Process;

class QueueHelper
{
    public static function isRunning(): bool
    {
        $process = Process::run("pgrep -af 'queue:work'");

        return ! empty($process->output());
    }
}
