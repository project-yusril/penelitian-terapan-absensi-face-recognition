<?php

return [
    'key' => env('BIOMETRIC_ENCRYPTION_KEY'),
    'key_id' => env('BIOMETRIC_ENCRYPTION_KEY_ID', 'v1'),
    'previous_keys' => json_decode(env('BIOMETRIC_ENCRYPTION_PREVIOUS_KEYS', '{}'), true) ?: [],
    // Client-attested compatibility switch. Since 21 Sep 2026 this also applies
    // to production (ADR-001 revision): setting true enables the on-device
    // verification flow. Claims remain client-attested, not server-verified.
    'allow_client_claims' => (bool) env('BIOMETRIC_ALLOW_CLIENT_CLAIMS', false),
    'probe_rate_limits' => [
        'user_per_minute' => (int) env('BIOMETRIC_PROBE_USER_PER_MINUTE', 5),
        'user_per_hour' => (int) env('BIOMETRIC_PROBE_USER_PER_HOUR', 30),
        'ip_per_minute' => (int) env('BIOMETRIC_PROBE_IP_PER_MINUTE', 30),
    ],
];
