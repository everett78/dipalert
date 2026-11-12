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

    /*
     * Chart line colours (RGB): the 500 shades of the Tailwind palette, enough
     * for the 12-15 stores a Ceneo offer list brings.
     */
    'chart_palette' => [
        '239, 68, 68',   // red
        '249, 115, 22',  // orange
        '245, 158, 11',  // amber
        '234, 179, 8',   // yellow
        '132, 204, 22',  // lime
        '34, 197, 94',   // green
        '16, 185, 129',  // emerald
        '20, 184, 166',  // teal
        '6, 182, 212',   // cyan
        '14, 165, 233',  // sky
        '59, 130, 246',  // blue
        '99, 102, 241',  // indigo
        '139, 92, 246',  // violet
        '168, 85, 247',  // purple
        '217, 70, 239',  // fuchsia
        '236, 72, 153',  // pink
        '244, 63, 94',   // rose
        '120, 113, 108', // stone
        '100, 116, 139', // slate
        '113, 113, 122', // zinc
    ],

    /*
     * Store logos.
     */
    'fetch_store_logos' => true,
    'logo_max_bytes' => 524288,
    'logo_timeout_seconds' => 10,
];
