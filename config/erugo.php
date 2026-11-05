<?php

return [
    // Set false explicitly only for isolated development over HTTP.
    'secure_cookies' => env('ERUGO_SECURE_COOKIES', true),
    'tusd_hook_secret' => env('ERUGO_TUSD_HOOK_SECRET', ''),
    'bundle_max_bytes' => (int) env('ERUGO_BUNDLE_MAX_BYTES', 4294967296),
    'bundle_max_files' => (int) env('ERUGO_BUNDLE_MAX_FILES', 10000),
    'bundle_max_ratio' => (int) env('ERUGO_BUNDLE_MAX_RATIO', 1000),
    'bundle_manifest_max_bytes' => 4194304,
];
