<?php

namespace App\Dto;

use App\Enums\FetchSource;

/**
 * Describes one fetch: where it started, the run (job batch) it belongs to,
 * whether the page cache may be used, and whether a person is waiting for it
 * in the panel (interactive). It is passed through the whole fetch chain.
 */
final class FetchContext
{
    public function __construct(
        public readonly FetchSource $source,
        public readonly ?string $batchId = null,
        public readonly bool $useCache = false,
        public readonly bool $interactive = false,
    ) {}

    /**
     * A scheduled run (FetchAll). Always fetches fresh pages in the background.
     */
    public static function run(?string $batchId = null): self
    {
        return new self(FetchSource::Run, $batchId);
    }

    /**
     * Products with their own interval (FetchDue).
     */
    public static function due(): self
    {
        return new self(FetchSource::Due);
    }

    /**
     * A delayed retry after a network error.
     */
    public static function retry(): self
    {
        return new self(FetchSource::Retry);
    }

    /**
     * Started from the panel but run in the queue.
     */
    public static function panelQueued(): self
    {
        return new self(FetchSource::Panel);
    }

    /**
     * Started from the panel while a person waits. May use the page cache.
     */
    public static function panel(): self
    {
        return new self(FetchSource::Panel, useCache: true, interactive: true);
    }
}
