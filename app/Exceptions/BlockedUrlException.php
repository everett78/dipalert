<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The address points to the home network, a reserved range or a scheme other
 * than http(s), so the app refuses to connect to it.
 */
class BlockedUrlException extends RuntimeException
{
    public function __construct(public readonly string $url, public readonly string $reason)
    {
        parent::__construct("Refused URL {$url}: {$reason}");
    }
}
