<?php

/**
 * Load Testing Script — 40 Concurrent Users
 *
 * Simulates real-world usage:
 * - Login (all roles)
 * - Dashboard queries
 * - CRUD operations
 * - Attendance check-in/out
 * - History & reports
 *
 * Usage: php tests/Load/load_test.php [base_url] [concurrency]
 */
$BASE_URL = $argv[1] ?? 'http://127.0.0.1:8000';
$CONCURRENCY = (int) ($argv[2] ?? 40);
$PASSWORD = '12345678';

$users = [
    ['login' => 'administrator@gmail.com', 'role' => 'super_admin'],
    ['login' => 'ketua_jurusan@gmail.com', 'role' => 'ketua_jurusan'],
    ['login' => 'admin_jurusan@gmail.com', 'role' => 'admin_jurusan'],
    ['login' => 'kaprodi_elektro@gmail.com', 'role' => 'kaprodi'],
    ['login' => 'kaprodi_informatika@gmail.com', 'role' => 'kaprodi'],
    ['login' => 'kaprodi_listrik@gmail.com', 'role' => 'kaprodi'],
    ['login' => 'admin_prodi_elektro@gmail.com', 'role' => 'admin_prodi'],
    ['login' => 'admin_prodi_informatika@gmail.com', 'role' => 'admin_prodi'],
    ['login' => 'admin_prodi_listrik@gmail.com', 'role' => 'admin_prodi'],
    ['login' => 'dosen_yusril@gmail.com', 'role' => 'dosen'],
    ['login' => 'dosen_adam@gmail.com', 'role' => 'dosen'],
    ['login' => 'dosen_fitri@gmail.com', 'role' => 'dosen'],
    ['login' => 'dosen_rudi@gmail.com', 'role' => 'dosen'],
    ['login' => 'dosen_sari@gmail.com', 'role' => 'dosen'],
    ['login' => 'dosen_wahyu@gmail.com', 'role' => 'dosen'],
    ['login' => 'dosen_dian@gmail.com', 'role' => 'dosen'],
    ['login' => 'dosen_joko@gmail.com', 'role' => 'dosen'],
    ['login' => 'dosen_mega@gmail.com', 'role' => 'dosen'],
    ['login' => 'mahasiswa_ahmad@gmail.com', 'role' => 'mahasiswa'],
    ['login' => 'mahasiswa_budi@gmail.com', 'role' => 'mahasiswa'],
    ['login' => 'mahasiswa_citra@gmail.com', 'role' => 'mahasiswa'],
    ['login' => 'mahasiswa_dani@gmail.com', 'role' => 'mahasiswa'],
    ['login' => 'mahasiswa_eka@gmail.com', 'role' => 'mahasiswa'],
    ['login' => 'mahasiswa_fajar@gmail.com', 'role' => 'mahasiswa'],
    ['login' => 'mahasiswa_gita@gmail.com', 'role' => 'mahasiswa'],
    ['login' => 'mahasiswa_hadi@gmail.com', 'role' => 'mahasiswa'],
    ['login' => 'mahasiswa_indra@gmail.com', 'role' => 'mahasiswa'],
    ['login' => 'mahasiswa_jihan@gmail.com', 'role' => 'mahasiswa'],
    ['login' => 'mahasiswa_kiki@gmail.com', 'role' => 'mahasiswa'],
    ['login' => 'mahasiswa_lukman@gmail.com', 'role' => 'mahasiswa'],
    ['login' => 'mahasiswa_mira@gmail.com', 'role' => 'mahasiswa'],
    ['login' => 'mahasiswa_nanda@gmail.com', 'role' => 'mahasiswa'],
    ['login' => 'mahasiswa_oki@gmail.com', 'role' => 'mahasiswa'],
    ['login' => 'orangtua_fauzi@gmail.com', 'role' => 'orang_tua'],
    ['login' => 'orangtua_prasetyo@gmail.com', 'role' => 'orang_tua'],
    ['login' => 'orangtua_saputra@gmail.com', 'role' => 'orang_tua'],
];

echo "============================================================\n";
echo "  LOAD TESTING — Sistem Absensi Mahasiswa Elektro\n";
echo "  Target: {$BASE_URL}\n";
echo "  Concurrent Users: {$CONCURRENCY}\n";
echo '  Total Users Available: '.count($users)."\n";
echo "============================================================\n\n";

function curlRequest($url, $method = 'GET', $headers = [], $body = null)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    if ($body) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $start = microtime(true);
    $response = curl_exec($ch);
    $time = (microtime(true) - $start) * 1000;
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $httpCode,
        'time_ms' => round($time, 2),
        'body' => $response,
        'error' => $error,
    ];
}

// ==================== PHASE 1: LOGIN ALL USERS ====================
echo "[Phase 1] Logging in {$CONCURRENCY} users concurrently...\n";

$loginHandles = [];
$loginResults = [];
$multiHandle = curl_multi_init();

$activeUsers = array_slice($users, 0, $CONCURRENCY);
// Pad with random mahasiswa if we need more
while (count($activeUsers) < $CONCURRENCY) {
    $activeUsers[] = $users[18 + (count($activeUsers) % 15)]; // mahasiswa pool
}

foreach ($activeUsers as $i => $user) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "{$BASE_URL}/api/auth/login");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'login' => $user['login'],
        'password' => $PASSWORD,
        'device_name' => "load-test-{$i}",
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $loginHandles[$i] = $ch;
    curl_multi_add_handle($multiHandle, $ch);
}

$loginStart = microtime(true);
do {
    $status = curl_multi_exec($multiHandle, $active);
    if ($active) {
        curl_multi_select($multiHandle);
    }
} while ($active && $status === CURLM_OK);
$loginTime = (microtime(true) - $loginStart) * 1000;

$tokens = [];
$loginSuccess = 0;
$loginFailed = 0;
$loginTimes = [];

foreach ($loginHandles as $i => $ch) {
    $response = curl_multi_getcontent($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
    $loginTimes[] = round($totalTime, 2);

    $data = json_decode($response, true);
    if ($httpCode === 200 && isset($data['data']['token'])) {
        $tokens[$i] = $data['data']['token'];
        $loginSuccess++;
    } else {
        $loginFailed++;
    }

    curl_multi_remove_handle($multiHandle, $ch);
    curl_close($ch);
}
curl_multi_close($multiHandle);

$loginAvg = round(array_sum($loginTimes) / count($loginTimes), 2);
$loginMin = round(min($loginTimes), 2);
$loginMax = round(max($loginTimes), 2);
sort($loginTimes);
$loginP95 = $loginTimes[(int) (count($loginTimes) * 0.95)];

echo '  ✓ Login completed in '.round($loginTime, 0)."ms (wall clock)\n";
echo "  ✓ Success: {$loginSuccess}/{$CONCURRENCY}, Failed: {$loginFailed}\n";
echo "  ✓ Response times — Avg: {$loginAvg}ms, Min: {$loginMin}ms, Max: {$loginMax}ms, P95: {$loginP95}ms\n\n";

// ==================== PHASE 2: CONCURRENT API REQUESTS ====================
echo "[Phase 2] Sending concurrent API requests (all endpoints)...\n";

$endpoints = [];

foreach ($tokens as $i => $token) {
    $role = $activeUsers[$i]['role'];
    $authHeaders = [
        "Authorization: Bearer {$token}",
        'Accept: application/json',
    ];

    // Common endpoints for all roles
    $endpoints[] = ['url' => "{$BASE_URL}/api/auth/me", 'headers' => $authHeaders, 'label' => 'GET /auth/me', 'user' => $i];
    $endpoints[] = ['url' => "{$BASE_URL}/api/notifications", 'headers' => $authHeaders, 'label' => 'GET /notifications', 'user' => $i];
    $endpoints[] = ['url' => "{$BASE_URL}/api/notifications/unread-count", 'headers' => $authHeaders, 'label' => 'GET /notifications/unread-count', 'user' => $i];

    switch ($role) {
        case 'super_admin':
        case 'admin_jurusan':
        case 'admin_prodi':
            $endpoints[] = ['url' => "{$BASE_URL}/api/admin/dashboard", 'headers' => $authHeaders, 'label' => 'GET /admin/dashboard', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/admin/users", 'headers' => $authHeaders, 'label' => 'GET /admin/users', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/admin/tahun-ajaran", 'headers' => $authHeaders, 'label' => 'GET /admin/tahun-ajaran', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/admin/mata-kuliah", 'headers' => $authHeaders, 'label' => 'GET /admin/mata-kuliah', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/admin/jadwal", 'headers' => $authHeaders, 'label' => 'GET /admin/jadwal', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/admin/geofence", 'headers' => $authHeaders, 'label' => 'GET /admin/geofence', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/admin/settings", 'headers' => $authHeaders, 'label' => 'GET /admin/settings', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/admin/sp-records", 'headers' => $authHeaders, 'label' => 'GET /admin/sp-records', 'user' => $i];
            break;

        case 'kaprodi':
            $endpoints[] = ['url' => "{$BASE_URL}/api/kaprodi/dashboard", 'headers' => $authHeaders, 'label' => 'GET /kaprodi/dashboard', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/kaprodi/sp-records", 'headers' => $authHeaders, 'label' => 'GET /kaprodi/sp-records', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/kaprodi/leave-requests", 'headers' => $authHeaders, 'label' => 'GET /kaprodi/leave-requests', 'user' => $i];
            break;

        case 'ketua_jurusan':
            $endpoints[] = ['url' => "{$BASE_URL}/api/kajur/dashboard", 'headers' => $authHeaders, 'label' => 'GET /kajur/dashboard', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/kajur/sp-records", 'headers' => $authHeaders, 'label' => 'GET /kajur/sp-records', 'user' => $i];
            break;

        case 'dosen':
            $endpoints[] = ['url' => "{$BASE_URL}/api/dosen/dashboard", 'headers' => $authHeaders, 'label' => 'GET /dosen/dashboard', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/dosen/mata-kuliah", 'headers' => $authHeaders, 'label' => 'GET /dosen/mata-kuliah', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/dosen/attendance/class-today", 'headers' => $authHeaders, 'label' => 'GET /dosen/attendance/class-today', 'user' => $i];
            break;

        case 'mahasiswa':
            $endpoints[] = ['url' => "{$BASE_URL}/api/mahasiswa/dashboard", 'headers' => $authHeaders, 'label' => 'GET /mahasiswa/dashboard', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/mahasiswa/attendance/today", 'headers' => $authHeaders, 'label' => 'GET /mahasiswa/attendance/today', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/mahasiswa/attendance/history", 'headers' => $authHeaders, 'label' => 'GET /mahasiswa/attendance/history', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/mahasiswa/enrollment/status", 'headers' => $authHeaders, 'label' => 'GET /mahasiswa/enrollment/status', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/mahasiswa/sp-records", 'headers' => $authHeaders, 'label' => 'GET /mahasiswa/sp-records', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/mahasiswa/leave-requests", 'headers' => $authHeaders, 'label' => 'GET /mahasiswa/leave-requests', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/mahasiswa/jadwal", 'headers' => $authHeaders, 'label' => 'GET /mahasiswa/jadwal', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/mahasiswa/jadwal/today", 'headers' => $authHeaders, 'label' => 'GET /mahasiswa/jadwal/today', 'user' => $i];
            break;

        case 'orang_tua':
            $endpoints[] = ['url' => "{$BASE_URL}/api/orang-tua/dashboard", 'headers' => $authHeaders, 'label' => 'GET /orang-tua/dashboard', 'user' => $i];
            $endpoints[] = ['url' => "{$BASE_URL}/api/orang-tua/children", 'headers' => $authHeaders, 'label' => 'GET /orang-tua/children', 'user' => $i];
            break;
    }
}

echo '  Total requests queued: '.count($endpoints)."\n";

// Execute in batches to avoid overwhelming curl_multi
$allResults = [];
$batchSize = 50;
$batches = array_chunk($endpoints, $batchSize);

$requestStart = microtime(true);

foreach ($batches as $batchIndex => $batch) {
    $mh = curl_multi_init();
    $handles = [];

    foreach ($batch as $j => $ep) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $ep['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $ep['headers']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $handles[$j] = $ch;
        curl_multi_add_handle($mh, $ch);
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh);
        }
    } while ($active && $status === CURLM_OK);

    foreach ($handles as $j => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;

        $allResults[] = [
            'label' => $batch[$j]['label'],
            'user' => $batch[$j]['user'],
            'status' => $httpCode,
            'time_ms' => round($totalTime, 2),
            'error' => curl_error($ch),
        ];

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
}

$requestTime = (microtime(true) - $requestStart) * 1000;

// ==================== ANALYSIS ====================
echo "\n[Phase 3] Analyzing results...\n\n";

$statusCounts = [];
$endpointStats = [];
$responseTimes = [];
$successCount = 0;
$failCount = 0;

foreach ($allResults as $r) {
    $statusCounts[$r['status']] = ($statusCounts[$r['status']] ?? 0) + 1;
    $responseTimes[] = $r['time_ms'];

    if ($r['status'] >= 200 && $r['status'] < 300) {
        $successCount++;
    } else {
        $failCount++;
    }

    if (! isset($endpointStats[$r['label']])) {
        $endpointStats[$r['label']] = ['count' => 0, 'total_ms' => 0, 'min_ms' => PHP_INT_MAX, 'max_ms' => 0, 'errors' => 0];
    }
    $endpointStats[$r['label']]['count']++;
    $endpointStats[$r['label']]['total_ms'] += $r['time_ms'];
    $endpointStats[$r['label']]['min_ms'] = min($endpointStats[$r['label']]['min_ms'], $r['time_ms']);
    $endpointStats[$r['label']]['max_ms'] = max($endpointStats[$r['label']]['max_ms'], $r['time_ms']);
    if ($r['status'] < 200 || $r['status'] >= 400) {
        $endpointStats[$r['label']]['errors']++;
    }
}

// Sort response times for percentile calculation
sort($responseTimes);
$totalRequests = count($responseTimes);
$avgTime = round(array_sum($responseTimes) / $totalRequests, 2);
$minTime = round(min($responseTimes), 2);
$maxTime = round(max($responseTimes), 2);
$p50 = $responseTimes[(int) ($totalRequests * 0.5)];
$p90 = $responseTimes[(int) ($totalRequests * 0.9)];
$p95 = $responseTimes[(int) ($totalRequests * 0.95)];
$p99 = $responseTimes[(int) ($totalRequests * 0.99)];
$rps = round($totalRequests / ($requestTime / 1000), 2);

echo "============================================================\n";
echo "  LOAD TEST RESULTS\n";
echo "============================================================\n\n";

echo "--- Overall ---\n";
echo "  Total Requests:   {$totalRequests}\n";
echo "  Successful:       {$successCount}\n";
echo "  Failed:           {$failCount}\n";
echo '  Error Rate:       '.round(($failCount / $totalRequests) * 100, 2)."%\n";
echo '  Total Time:       '.round($requestTime, 0)."ms\n";
echo "  Throughput:       {$rps} req/s\n\n";

echo "--- Response Times (ms) ---\n";
echo "  Avg:    {$avgTime}\n";
echo "  Min:    {$minTime}\n";
echo "  Max:    {$maxTime}\n";
echo "  P50:    {$p50}\n";
echo "  P90:    {$p90}\n";
echo "  P95:    {$p95}\n";
echo "  P99:    {$p99}\n\n";

echo "--- HTTP Status Codes ---\n";
ksort($statusCounts);
foreach ($statusCounts as $code => $count) {
    echo "  {$code}: {$count} requests\n";
}
echo "\n";

echo "--- Per-Endpoint Breakdown ---\n";
printf("  %-50s %6s %8s %8s %8s %6s\n", 'Endpoint', 'Count', 'Avg(ms)', 'Min(ms)', 'Max(ms)', 'Errors');
echo '  '.str_repeat('-', 90)."\n";

uksort($endpointStats, function ($a, $b) use ($endpointStats) {
    return $endpointStats[$b]['total_ms'] / $endpointStats[$b]['count'] <=> $endpointStats[$a]['total_ms'] / $endpointStats[$a]['count'];
});

foreach ($endpointStats as $label => $stats) {
    $avg = round($stats['total_ms'] / $stats['count'], 2);
    printf("  %-50s %6d %8s %8s %8s %6d\n", $label, $stats['count'], $avg, $stats['min_ms'], $stats['max_ms'], $stats['errors']);
}

echo "\n";

// ==================== PHASE 3: STRESS TEST (Rapid Fire) ====================
echo "[Phase 4] Stress test — 100 rapid requests to /api/health...\n";

$stressHandles = [];
$stressResults = [];
$stressMh = curl_multi_init();
$stressCount = 100;

for ($i = 0; $i < $stressCount; $i++) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "{$BASE_URL}/api/health");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $stressHandles[] = $ch;
    curl_multi_add_handle($stressMh, $ch);
}

$stressStart = microtime(true);
do {
    $status = curl_multi_exec($stressMh, $active);
    if ($active) {
        curl_multi_select($stressMh);
    }
} while ($active && $status === CURLM_OK);
$stressTime = (microtime(true) - $stressStart) * 1000;

$stressSuccess = 0;
$stressTimes = [];
foreach ($stressHandles as $ch) {
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
    $stressTimes[] = round($totalTime, 2);
    if ($httpCode === 200) {
        $stressSuccess++;
    }
    curl_multi_remove_handle($stressMh, $ch);
    curl_close($ch);
}
curl_multi_close($stressMh);

sort($stressTimes);
$stressAvg = round(array_sum($stressTimes) / count($stressTimes), 2);
$stressRps = round($stressCount / ($stressTime / 1000), 2);

echo "  ✓ {$stressCount} requests in ".round($stressTime, 0)."ms\n";
echo "  ✓ Success: {$stressSuccess}/{$stressCount}\n";
echo "  ✓ Avg: {$stressAvg}ms, P95: {$stressTimes[(int) ($stressCount * 0.95)]}ms, Max: ".max($stressTimes)."ms\n";
echo "  ✓ Throughput: {$stressRps} req/s\n\n";

// ==================== SUMMARY ====================
echo "============================================================\n";
echo "  FINAL SUMMARY\n";
echo "============================================================\n";
echo "  Login Phase:     {$loginSuccess}/{$CONCURRENCY} success, ".round($loginTime, 0)."ms wall time\n";
echo "  API Phase:       {$successCount}/{$totalRequests} success, {$rps} req/s\n";
echo "  Stress Phase:    {$stressSuccess}/{$stressCount} success, {$stressRps} req/s\n";
echo "  Overall P95:     {$p95}ms\n";
echo '  Overall Error:   '.round(($failCount / $totalRequests) * 100, 2)."%\n";

$passed = ($failCount / $totalRequests) < 0.05 && $p95 < 5000;
echo "\n  STATUS: ".($passed ? '✅ PASSED' : '❌ FAILED')."\n";
echo "  (Pass criteria: <5% error rate, P95 < 5000ms)\n";
echo "============================================================\n";

// Save results to JSON
$reportData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'config' => ['concurrency' => $CONCURRENCY, 'base_url' => $BASE_URL],
    'login' => ['success' => $loginSuccess, 'failed' => $loginFailed, 'wall_time_ms' => round($loginTime, 0), 'avg_ms' => $loginAvg, 'p95_ms' => $loginP95],
    'api' => ['total' => $totalRequests, 'success' => $successCount, 'failed' => $failCount, 'rps' => $rps, 'avg_ms' => $avgTime, 'p50_ms' => $p50, 'p90_ms' => $p90, 'p95_ms' => $p95, 'p99_ms' => $p99],
    'stress' => ['total' => $stressCount, 'success' => $stressSuccess, 'rps' => $stressRps, 'avg_ms' => $stressAvg],
    'status_codes' => $statusCounts,
    'passed' => $passed,
];

file_put_contents(__DIR__.'/load_test_results.json', json_encode($reportData, JSON_PRETTY_PRINT));
echo "\nResults saved to tests/Load/load_test_results.json\n";
