<?php

/*
 * DipAlert settings that live in code or environment variables (not in the
 * settings table). Keys used by later stages are added in those stages.
 */
return [

    /*
     * FlareSolverr (browser-based fetching for protected stores).
     */
    'flaresolverr_url' => env('FLARESOLVERR_URL'),
    'flaresolverr_timeout_ms' => 60000,
    'flaresolverr_interactive_timeout_ms' => 45000,
    'flaresolverr_client_timeout_s' => 70,

    /*
     * Hosts in the home network the app may connect to as services
     * (FlareSolverr, Ollama). Never allowed as pages to fetch.
     */
    'allowed_internal_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DIPALERT_ALLOWED_INTERNAL_HOSTS', ''))
    ))),

    /*
     * Database backups.
     */
    'backup_path' => env('DIPALERT_BACKUP_PATH', '/backups'),
    'backup_keep' => 14,

    /*
     * Failed fetch pages kept for the "Problems" page.
     */
    'failed_pages_retention_days' => 30,
    'failed_page_max_bytes' => 2097152,

    /*
     * Fetch outcome and pacing.
     */
    'block_markers' => [
        'captcha-delivery.com',
        '<title>Just a moment...</title>',
        '/errors/validateCaptcha',
    ],
    'max_redirects' => 5,
    'default_min_interval_seconds' => 10,
    'interactive_max_wait_seconds' => 15,
    'pause_outside_run_minutes' => 60,
    'pause_max_hours' => 24,
    'pacing_lock_wait_seconds' => 5,
    'retry_outcomes' => ['network_error'],
];
