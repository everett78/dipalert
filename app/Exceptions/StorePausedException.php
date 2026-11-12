<?php

namespace App\Exceptions;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The store is paused after a block, so the fetch is skipped without a request.
 */
class StorePausedException extends RuntimeException
{
    public function __construct(public readonly string $host, public readonly Carbon $pausedUntil)
    {
        parent::__construct(__('Store paused until :time', ['time' => $pausedUntil->format('H:i')]));
    }
}
