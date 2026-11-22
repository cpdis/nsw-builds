<?php

return [
    'base_url' => env('NSW_EPLANNING_URL', 'https://api.apps1.nsw.gov.au/eplanning/data/v0'),
    'page_size' => (int) env('NSW_PAGE_SIZE', 1000),
    // Pause between pages. Nobody has reported throttling, but nothing is documented either.
    'page_delay_ms' => (int) env('NSW_PAGE_DELAY_MS', 2000),
    // Per-request timeout and attempts. Keep attempts x timeout well under the
    // page job's 85 s timeout (Laravel Cloud caps jobs at 90 s).
    'timeout' => (int) env('NSW_TIMEOUT', 20),
    'attempts' => 3,
    // Incremental syncs look back this far past the last successful run.
    'overlap_days' => 2,
    'timezone' => 'Australia/Sydney',
];
