/*
 * R-02 — Load test runner eksternal (k6) untuk sistem absensi mahasiswa.
 *
 * Mengukur response latency/failure/timeout dari LUAR aplikasi (bukan
 * inference_time_ms), sesuai rekomendasi validitas penelitian di temuan.md.
 * Skenario: login batch → dashboard/jadwal/history per level simultan
 * 20/30/40 pengguna (target NFR P95 <= 2 detik, lihat PRD-08).
 *
 * CATATAN PENTING:
 * - Ini READ-ONLY: hanya endpoint GET yang tidak mengubah state. Endpoint
 *   check-in/out TIDAK disentuh agar data penelitian tidak terkontaminasi.
 * - Jalankan terhadap environment lokal/staging yang datanya sudah di-seed,
 *   BUKAN production.
 * - Rate limiter `throttle:login` (5/menit/IP) akan membatasi login batch;
 *   script ini me-reuse token (login sekali per VU) dan menahan token di
 *   memori. Untuk mengukur login massal, naikkan limiter sementara di env
 *   staging dan catat itu di hasil.
 *
 * Instalasi: https://k6.io — `choco install k6` / `winget install k6 --source winget`
 * Cara pakai:
 *   k6 run -e BASE_URL=http://127.0.0.1:8000 -e USERS=20 -e DURATION=60s loadtest/r02_load_test.js
 *   k6 run -e BASE_URL=http://127.0.0.1:8000 -e USERS=40 -e DURATION=60s loadtest/r02_load_test.js
 *
 * Kredensial via env (JANGAN commit password asli):
 *   k6 run -e BASE_URL=... -e USERS=20 -e TEST_EMAIL=mhs1@ti.test -e TEST_PASSWORD=... ...
 * atau biarkan script membuat user via seeder penelitian yang sudah ada.
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const USERS = parseInt(__ENV.USERS || '20', 10);
const DURATION = __ENV.DURATION || '60s';
const TEST_EMAIL = __ENV.TEST_EMAIL || '';
const TEST_PASSWORD = __ENV.TEST_PASSWORD || '';

// Metrik eksplisit — inilah yang dituntut R-02: latency/failure/timeout
// diukur eksternal, bukan diambil dari metadata aplikasi.
const dashboardLatency = new Trend('absensi_dashboard_http_duration', true);
const jadwalLatency = new Trend('absensi_jadwal_http_duration', true);
const historyLatency = new Trend('absensi_history_http_duration', true);
const failureRate = new Rate('absensi_request_failure');
const timeoutCount = new Counter('absensi_request_timeouts');

export const options = {
  scenarios: {
    simultaneous: {
      executor: 'constant-arrival-rate',
      rate: USERS, // 1 iterasi per detik per level USERS
      timeUnit: '1s',
      duration: DURATION,
      preAllocatedVUs: USERS,
      maxVUs: USERS * 2,
      exec: 'measuredFlow',
    },
  },
  thresholds: {
    // NFR target P95 <= 2 detik (PRD-08); kegagalan <= 1%.
    'http_req_duration{endpoint:dashboard}': ['p(95)<2000'],
    'http_req_duration{endpoint:jadwal}': ['p(95)<2000'],
    'http_req_duration{endpoint:history}': ['p(95)<2000'],
    absensi_request_failure: ['rate<0.01'],
  },
  // Timeout eksplisit supaya hang tercatat sebagai failure+timeout, bukan
  // membebani VU selamanya.
  httpDebug: 'off',
};

let token = null;

function login() {
  if (token) return token;
  if (!TEST_EMAIL || !TEST_PASSWORD) {
    throw new Error(
      'Set -e TEST_EMAIL dan -e TEST_PASSWORD (akun mahasiswa dari seeder).'
    );
  }
  const res = http.post(
    `${BASE_URL}/api/auth/login`,
    JSON.stringify({ login: TEST_EMAIL, password: TEST_PASSWORD, device_name: `k6-${__VU}` }),
    { headers: { 'Content-Type': 'application/json' }, tags: { endpoint: 'login' } }
  );
  const ok = check(res, { 'login 200': (r) => r.status === 200 });
  if (!ok) {
    failureRate.add(1);
    throw new Error(`Login gagal: ${res.status} ${res.body.slice(0, 200)}`);
  }
  token = res.json('data.token');
  return token;
}

function get(path, tag, trend) {
  const res = http.get(`${BASE_URL}${path}`, {
    headers: { Authorization: `Bearer ${login()}` },
    timeout: '5s',
    tags: { endpoint: tag },
  });
  trend.add(res.timings.duration);
  const ok = check(res, { [`${tag} 200`]: (r) => r.status === 200 });
  if (!ok) failureRate.add(1);
  if (res.error && res.error.toLowerCase().includes('timeout')) timeoutCount.add(1);
  return ok;
}

// Satu iterasi = satu "pengguna" mengecek dashboard, jadwal, lalu history —
// mensimulasikan buka aplikasi saat jam masuk, tanpa mutasi data.
export function measuredFlow() {
  const okDash = get('/api/mahasiswa/dashboard', 'dashboard', dashboardLatency);
  sleep(0.5);
  const okJadwal = get('/api/mahasiswa/jadwal/today', 'jadwal', jadwalLatency);
  sleep(0.5);
  const okHist = get('/api/mahasiswa/attendance/history?per_page=20&page=1', 'history', historyLatency);
  if (okDash && okJadwal && okHist) sleep(2);
}

export function handleSummary(data) {
  const t = (name) => {
    const m = data.metrics[name];
    if (!m) return 'n/a';
    return `avg=${m.values.avg?.toFixed(0)}ms p95=${m.values['p(95)']?.toFixed(0)}ms max=${m.values.max?.toFixed(0)}ms`;
  };
  return {
    stdout: `
=== R-02 Load Test (${USERS} pengguna simultan, ${DURATION}) ===
dashboard : ${t('http_req_duration{endpoint:dashboard}')}
jadwal    : ${t('http_req_duration{endpoint:jadwal}')}
history   : ${t('http_req_duration{endpoint:history}')}
failure   : ${(data.metrics.absensi_request_failure.values.rate * 100).toFixed(2)}%
timeouts  : ${data.metrics.absensi_request_timeouts.values.count}
http_reqs : ${data.metrics.http_reqs.values.count}
Simpan output ini sebagai bukti R-02 (raw: jalankan dengan --summary-export=r02_${USERS}u.json).
`,
  };
}
