/*
 * R-02 v2 — Load test multi-akun (satu akun mahasiswa per VU).
 *
 * Mengatasi rate limiter `api` (60 req/menit per user, M-23): setiap VU
 * memakai token milik akun mahasiswa berbeda sehingga kuota per-pengguna
 * tidak saling menabrak — mencerminkan skenario nyata 20/30/40 mahasiswa
 * berbeda yang membuka aplikasi serentak.
 *
 * Sama seperti v1: READ-ONLY (dashboard, jadwal, history — GET saja),
 * tidak menyentuh check-in/out agar attendance_logs tetap bersih.
 *
 * Akun via file CSV 2 kolom: email,password (baris = akun). Buat dengan:
 *   akun1@x.com,pass1
 *   akun2@x.com,pass2
 * Jalankan:
 *   k6 run -e BASE_URL=https://host -e ACCOUNTS_FILE=akun.csv -e USERS=20 -e DURATION=60s r02_load_test_v2.js
 * Jika jumlah akun < USERS, token dibagi bergilir (beberapa VU berbagi
 * kuota — catat di hasil bila terjadi).
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';
import { SharedArray } from 'k6/data';
import encoding from 'k6/encoding';

const BASE_URL = __ENV.BASE_URL || 'https://absensi.yusrilekamahendra.com';
const USERS = parseInt(__ENV.USERS || '20', 10);
const DURATION = __ENV.DURATION || '60s';
const ACCOUNTS_FILE = __ENV.ACCOUNTS_FILE || '';

const accounts = new SharedArray('accounts', function () {
  const raw = open(ACCOUNTS_FILE);
  return raw
    .split('\n')
    .map((l) => l.trim())
    .filter((l) => l.length > 0 && !l.startsWith('#'))
    .map((l) => {
      const idx = l.indexOf(',');
      return { email: l.slice(0, idx).trim(), password: l.slice(idx + 1).trim() };
    });
});

const dashboardLatency = new Trend('absensi_dashboard_http_duration', true);
const jadwalLatency = new Trend('absensi_jadwal_http_duration', true);
const historyLatency = new Trend('absensi_history_http_duration', true);
const failureRate = new Rate('absensi_request_failure');
const timeoutCount = new Counter('absensi_request_timeouts');

export const options = {
  scenarios: {
    simultaneous: {
      executor: 'constant-arrival-rate',
      rate: USERS,
      timeUnit: '1s',
      duration: DURATION,
      preAllocatedVUs: USERS,
      maxVUs: USERS * 2,
      exec: 'measuredFlow',
    },
  },
  thresholds: {
    'http_req_duration{endpoint:dashboard}': ['p(95)<2000'],
    'http_req_duration{endpoint:jadwal}': ['p(95)<2000'],
    'http_req_duration{endpoint:history}': ['p(95)<2000'],
    absensi_request_failure: ['rate<0.01'],
  },
  setupTimeout: '120s',
};

export function setup() {
  if (accounts.length === 0) {
    throw new Error('ACCOUNTS_FILE kosong atau tidak berisi akun valid.');
  }
  const tokens = [];
  for (let i = 0; i < accounts.length; i++) {
    const res = http.post(
      `${BASE_URL}/api/auth/login`,
      JSON.stringify({ login: accounts[i].email, password: accounts[i].password, device_name: 'k6-r02' }),
      { headers: { 'Content-Type': 'application/json' }, tags: { endpoint: 'login' } }
    );
    const ok = check(res, { 'login 200': (r) => r.status === 200 });
    if (ok) {
      tokens.push(res.json('data.token'));
    }
    // throttle:login 5/menit per IP+identitas → 30/menit per IP (M-21).
    // Jeda kecil agar 30/menit per IP tidak terlewati saat login batch.
    if ((i + 1) % 25 === 0) sleep(62);
  }
  if (tokens.length === 0) {
    throw new Error('Tidak ada satu pun login yang berhasil.');
  }
  return { tokens: tokens };
}

function get(path, tag, trend, token) {
  const res = http.get(`${BASE_URL}${path}`, {
    headers: { Authorization: `Bearer ${token}` },
    timeout: '5s',
    tags: { endpoint: tag },
  });
  trend.add(res.timings.duration);
  const ok = check(res, { [`${tag} 200`]: (r) => r.status === 200 });
  if (!ok) failureRate.add(1);
  if (res.error && res.error.toLowerCase().includes('timeout')) timeoutCount.add(1);
  return ok;
}

export function measuredFlow(data) {
  // satu akun per VU; bergilir bila akun < VU
  const token = data.tokens[__VU % data.tokens.length];
  const okDash = get('/api/mahasiswa/dashboard', 'dashboard', dashboardLatency, token);
  sleep(0.5);
  const okJadwal = get('/api/mahasiswa/jadwal/today', 'jadwal', jadwalLatency, token);
  sleep(0.5);
  const okHist = get('/api/mahasiswa/attendance/history?per_page=20&page=1', 'history', historyLatency, token);
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
=== R-02 Load Test v2 (${USERS} pengguna simultan, ${DURATION}, ${data.setupData ? '' : ''}token pool) ===
dashboard : ${t('http_req_duration{endpoint:dashboard}')}
jadwal    : ${t('http_req_duration{endpoint:jadwal}')}
history   : ${t('http_req_duration{endpoint:history}')}
failure   : ${(data.metrics.absensi_request_failure.values.rate * 100).toFixed(2)}%
timeouts  : ${data.metrics.absensi_request_timeouts.values.count}
http_reqs : ${data.metrics.http_reqs.values.count}
`,
  };
}
