<?php

namespace App\Enums;

/**
 * Where a page fetch was started from.
 */
enum FetchSource: string
{
    case Run = 'run';
    case Due = 'due';
    case Retry = 'retry';
    case Panel = 'panel';
}
