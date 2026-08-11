<?php

return [
    'key' => env('BIOMETRIC_ENCRYPTION_KEY'),
    'key_id' => env('BIOMETRIC_ENCRYPTION_KEY_ID', 'v1'),
    'previous_keys' => json_decode(env('BIOMETRIC_ENCRYPTION_PREVIOUS_KEYS', '{}'), true) ?: [],
    // Temporary compatibility switch for local/test only. Production must
    // leave this false until capture artifacts are verified by a trusted
    // server-side verifier; a permit alone does not make client scalars true.
    'allow_client_claims' => (bool) env('BIOMETRIC_ALLOW_CLIENT_CLAIMS', false),
    'probe_rate_limits' => [
        'user_per_minute' => (int) env('BIOMETRIC_PROBE_USER_PER_MINUTE', 5),
        'user_per_hour' => (int) env('BIOMETRIC_PROBE_USER_PER_HOUR', 30),
        'ip_per_minute' => (int) env('BIOMETRIC_PROBE_IP_PER_MINUTE', 30),
    ],
];
