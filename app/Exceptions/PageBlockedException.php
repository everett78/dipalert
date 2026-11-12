<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A page fetched for AI repair is a block page, so the repair stops.
 */
class PageBlockedException extends RuntimeException {}
