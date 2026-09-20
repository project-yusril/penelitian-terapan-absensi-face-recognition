<?php
// FAR evaluation: pairwise cross-user Euclidean distances between real enrolled embeddings.
// Runs on server, read-only DB. Outputs ONLY aggregate distances (no embedding data leaves server).
$rawKey = trim(getenv('BIOMETRIC_KEY_B64'));
if ($rawKey === '') { fwrite(STDERR, "missing key\n"); exit(1); }
$key = str_starts_with($rawKey, 'base64:') ? base64_decode(substr($rawKey, 7), true) : $rawKey;
if (!is_string($key) || strlen($key) !== 32) { fwrite(STDERR, "bad key\n"); exit(1); }

$APP = getenv('HOME').'/domains/absensi.yusrilekamahendra.com/public_html';
require $APP.'/vendor/autoload.php';
$app = require $APP.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::table('face_embeddings')
    ->whereIn('status', ['approved', 'pending'])
    ->select('id', 'user_id', 'embedding_ciphertext', 'embedding_key_id', 'status')
    ->get();

$encrypter = new Illuminate\Encryption\Encrypter($key, 'AES-256-GCM');

$embs = [];
foreach ($rows as $row) {
    try {
        $plain = $encrypter->decryptString($row->embedding_ciphertext);
        $vec = array_map('floatval', json_decode($plain, true, 512, JSON_THROW_ON_ERROR));
        if (count($vec) !== 192) { continue; }
        $embs[$row->user_id] = $vec; // last embedding per user
    } catch (Throwable $e) {
        fwrite(STDERR, "decrypt fail id={$row->id}\n");
    }
}

$userIds = array_keys($embs);
$n = count($userIds);
fwrite(STDERR, "users with embeddings: {$n}\n");

// L2 normalize? Production check-in does NOT normalize; duplicate service does not either.
// Follow production logic: raw Euclidean distance on canonical floats.
$distances = [];
for ($i = 0; $i < $n; $i++) {
    for ($j = $i + 1; $j < $n; $j++) {
        $a = $embs[$userIds[$i]];
        $b = $embs[$userIds[$j]];
        $sum = 0.0;
        for ($k = 0; $k < 192; $k++) {
            $d = $a[$k] - $b[$k];
            $sum += $d * $d;
        }
        $distances[] = sqrt($sum);
    }
}

sort($distances);
$m = count($distances);
fwrite(STDERR, "pairwise distances: {$m}\n");

echo "n_pairs={$m}\n";
echo "min=" . $distances[0] . "\n";
echo "max=" . $distances[$m-1] . "\n";
$sum = array_sum($distances);
echo "mean=" . ($sum / $m) . "\n";

// histogram 0.1 bins from 0 to 1.6
$bins = array_fill(0, 16, 0);
foreach ($distances as $d) {
    $b = (int) floor($d / 0.1);
    if ($b > 15) { $b = 15; }
    $bins[$b]++;
}
echo "histogram:\n";
foreach ($bins as $i => $c) {
    printf("%.1f-%.1f: %d\n", $i * 0.1, ($i + 1) * 0.1, $c);
}

// FAR at candidate thresholds (impostor distance <= theta => false accept)
echo "FAR sweep:\n";
for ($t = 0.30; $t <= 1.21; $t += 0.05) {
    $accepted = 0;
    foreach ($distances as $d) { if ($d <= $t) { $accepted++; } }
    printf("%.2f %.6f %d\n", $t, $accepted / $m, $accepted);
}

// save full sorted distances for local analysis (aggregate numbers only)
file_put_contents(getenv('HOME').'/impostor_distances.txt', implode("\n", array_map(fn($d) => sprintf('%.6f', $d), $distances)));
fwrite(STDERR, "saved impostor_distances.txt\n");
