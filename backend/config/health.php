<?php

return [
    'cache_key' => env('READINESS_CACHE_KEY', '_readiness'),
    'storage_disk' => env('READINESS_STORAGE_DISK', 'local'),
    'storage_sentinel' => env('READINESS_STORAGE_SENTINEL', '.readiness'),
];
