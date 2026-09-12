# R-02 — Load Test Runner Eksternal (k6)

> Melengkapi rekomendasi validitas penelitian #2/#3 di `docs/temuan.md`:
> latency/failure/timeout harus diukur **eksternal** (HTTP response time),
> bukan `inference_time_ms` atau payload `success=true` dari dalam aplikasi.

## Prasyarat

1. k6 terpasang: `winget install k6 --source winget` (atau https://k6.io/downloads).
2. Backend berjalan di environment lokal/staging dengan database yang sudah
   di-seed (`php artisan migrate --seed` atau data penelitian yang ada).
3. Akun mahasiswa aktif dari seeder (lihat `backend/database/seeders`).
4. Jangan jalankan terhadap production. Script ini read-only, tetapi tetap
   membebani server.

## Skenario

Script `r02_load_test.js` mensimulasikan buka aplikasi saat jam masuk:

1. `GET /api/mahasiswa/dashboard`
2. `GET /api/mahasiswa/jadwal/today`
3. `GET /api/mahasiswa/attendance/history?per_page=20&page=1`

Semua endpoint GET tanpa mutasi — data penelitian tidak tersentuh. Tidak ada
check-in/out agar `attendance_logs` tetap bersih untuk R-05/R-07.

## Menjalankan

```powershell
cd loadtest
k6 run -e BASE_URL=http://127.0.0.1:8000 `
       -e USERS=20 -e DURATION=60s `
       -e TEST_EMAIL=<email-mahasiswa-seeder> `
       -e TEST_PASSWORD=<password-seeder> `
       --summary-export=r02_20u.json `
       r02_load_test.js

# Level berikutnya:
k6 run -e USERS=30 -e DURATION=60s ... --summary-export=r02_30u.json r02_load_test.js
k6 run -e USERS=40 -e DURATION=60s ... --summary-export=r02_40u.json r02_load_test.js
```

## Target NFR (PRD-08)

| Metrik | Target |
|---|---|
| P95 per endpoint | ≤ 2000 ms |
| Failure rate | < 1% |
| Timeout | 0 |

Threshold sudah dipasang di script; k6 exit code non-zero bila dilanggar.

## Catatan metodologi

- `throttle:login` = 5/menit/IP. Script login **sekali per VU** lalu reuse
  token, sehingga limiter tidak mengotori hasil. Jika ingin mengukur login
  massal, naikkan limiter di `.env` staging sementara dan catat perubahannya.
- Timeout request dipatok 5 detik; hang tercatat di `absensi_request_timeouts`.
- Simpan `--summary-export=*.json` + stdout ringkasan sebagai bukti raw yang
  dapat diaudit (rekomendasi #7 temuan.md).
- Level uji simultan R-07 (20/30/40) memakai script ini sebagai runner; label
  `metadata.concurrent_level` untuk R-07 tetap lewat jalur test-mode backend
  yang sudah ada (TestModeController), tidak lewat script ini.

## Bukti yang dicatat per sesi

1. File `r02_<LEVEL>u.json` (raw k6).
2. Screenshot/teks ringkasan stdout.
3. Konfigurasi host (CPU/RAM, versi PHP, opcache on/off) — wajib dicatat agar
   hasil dapat direproduksi.
