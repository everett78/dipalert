<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A panel fetch would have to wait too long for the store's interval.
 */
class StoreBusyException extends RuntimeException
{
    public function __construct(public readonly string $host, public readonly int $seconds)
    {
        parent::__construct(__('Store busy, try again in :seconds s', ['seconds' => $seconds]));
    }
}
