# Deployment dan Release

**Status:** maintained runbook
**Pembaruan:** 24 September 2026, 10:00 WIB
**Release matrix:** web/backend + Android; iOS tidak didukung

> **Akses SSH ke server production** (IP, port, username, key, pola deploy, backup):
> [`ssh.md`](../ssh.md) — file berisi kredensial dan di-gitignore, hanya ada di lokal workspace.

## Baseline

- PHP 8.3.30, sesuai Composer platform.
- Laravel 13.x.
- Node.js `22.21.1` dan npm `11.6.2` dengan committed lockfile.
- MySQL production dengan database user least-privilege.
- Flutter `3.44.2` / Dart `3.12.2`, sesuai CI dan constraint `pubspec.yaml`.
- JDK 17 untuk Android Gradle/Kotlin build; workflow memakai Temurin 17.
- HTTPS wajib untuk web/API production.

## Development Lokal (Windows/Dev)

Untuk development lokal cukup satu perintah dari `backend/`:

```powershell
php artisan serve:all
```

`serve:all` (lihat `backend/app/Console/Commands/ServeAll.php`) menjalankan dev server **dan** scheduler (`schedule:work`) sekaligus dari satu proses. Default `--host=0.0.0.0 --port=8000`; kedua proses dimonitor — jika salah satu berhenti, semua dihentikan. Alternatif satu perintah penuh:

```powershell
composer dev
```

yang menjalankan `serve:all`, queue listener, log viewer (Pail), dan Vite sekaligus.

> **Penting:** tanpa scheduler yang hidup, `attendance:auto-close` dan `attendance:mark-absent` tidak pernah mengeksekusi sehingga status ALPHA tidak tercatat. `php artisan serve` saja **tidak cukup**; gunakan `serve:all` atau `composer dev`.

## Backend Production (Live)

Backend penelitian **sudah live** pada 12 September 2026 di:

| Item | Nilai |
|---|---|
| Base URL | `https://absensi.yusrilekamahendra.com` |
| API base | `https://absensi.yusrilekamahendra.com/api` |
| Host | Hostinger (hPanel, PHP 8.3.33, HTTP/3 via hCDN) |
| Liveness | `GET /api/health` → `200 {"status":"ok"}` (terverifikasi 12 September 2026) |
| Dashboard web | `GET /` → 302 ke `/login`, cookie session `Secure`+`HttpOnly`+`SameSite=lax` aktif |

Mobile terhubung ke host ini lewat `--dart-define=API_BASE_URL=https://absensi.yusrilekamahendra.com/api` (sudah diuji dari perangkat fisik Android — app boot dan mencapai halaman login). `AppConfig` menerima HTTPS apa pun tanpa allowlist, sehingga tidak ada perubahan kode untuk pindah host.

### Riwayat Deploy via SSH

Deploy langsung dari workspace Windows ke host dilakukan via SSH key (detail koneksi, kredensial, dan pola command: [`ssh.md`](../ssh.md)).

**14 September 2026 — threshold biometrik 0.600 + perbaikan EXIF:** upload 11 file backend (checksum terverifikasi), upload `public/build` hasil `npm run build` lokal (node tidak tersedia di server), clear semua cache Laravel, `UPDATE prodi_settings SET face_threshold = 0.600` (semua 3 prodi), backup penuh DB + storage + `.env` **sebelum** perubahan (lokal: `backup_server_20260914/`). Insiden yang ditemukan & diperbaiki saat deploy: folder hasil `scp -r` ber-permission `700` sehingga apache 404 semua asset dan dashboard blank — fix `chmod 755`/`644` pada `public/build`. Analisis 19 embedding (terdekripsi) menunjukkan 14 enrollment pending 14 September sehat (norm 1.0, jarak antar-orang ~1.0–1.4) — tidak perlu re-enrollment; 1 pasangan mendekati threshold (RIFNO vs Yusril, 0.5752) untuk direview visual via dashboard.

**14 September 2026, 22:58 WIB (15:58 UTC) — fix enrollment foto > 500 KB (M-25, ronde 1):** upload 2 controller via SSH (base64-over-stdin; `scp` bermasalah dari PowerShell — hash SHA256 diverifikasi identik), `php artisan config:clear` + `cache:clear`. Perubahan: validasi foto enrollment/re-enrollment `max:500` → `max:10240` + `dimensions:max_width=6000,max_height=6000` via konstanta `MAX_FOTO_KB`/`MAX_FOTO_DIMENSION_PX`/`fotoRules()` di `EnrollmentController`; foto profil `ProfileController` `max:2048` → `max:10240` + rule dimensi. Kompresi sisi klien (1600 px, q85, ≤ 500 KB) masuk APK build berikutnya (`EnrollmentPhotoCompressor`). Verifikasi deploy: `php -l` bersih, `/api/health` 200, jumlah foto 30 dan hash-chain `932288fc…` **identik** sebelum dan sesudah deploy — tidak ada foto pending yang hilang. Backup sebelum deploy: foto (30 file, 8,9 MB) → `~/backups/face_storage_pre_deploy_20260914/` (server) + `backup_server_face_20260914/` (lokal), dump DB 35 tabel → `~/backups/db_pre_deploy_20260914/db_full.sql.gz` + salinan lokal; controller lama → `~/backups/controllers_pre_deploy_20260914/`.

**14 September 2026, 23:40 WIB (16:40 UTC) — fix hasil code review M-25 (ronde 2):** upload 4 file backend via SSH base64-over-stdin (hash SHA256 terverifikasi identik): `EnrollmentController.php`, `ProfileController.php`, `PhotoUploadPolicy.php` (baru), `routes/api.php`, lalu `config:clear` + `cache:clear` + `route:clear`. Perubahan: rule foto diekstrak ke `App\Services\PhotoUploadPolicy::facePhotoRules()` (dipakai enrollment, re-enrollment, dan foto profil — menghapus duplikasi); pre-check pending re-enrollment sebelum menulis file ke disk; `throttle:biometric-probe` dipasang pada `POST /re-enrollment`. Verifikasi deploy: `php -l` 4 file bersih, `/api/health` 200, foto 34 → 35 (1 foto baru dari user aktif; tidak ada yang hilang), baseline hash baru `34624b84…` dicatat di `backup_server_face_20260914/BASELINE-HASH.txt`.

**21 September 2026, 00:20–01:15 WIB — eksperimen penelitian (TANPA deploy kode):** tidak ada file backend yang berubah. Aktivitas: (1) admin menyetujui 50 enrollment via dashboard sehingga **55 embedding berstatus `approved`** (4 `rejected`, 59 total baris); (2) **evaluasi FAR asli** — script PHP read-only dijalankan di server (`eksperimen/far_eval_server.php` lokal) mendekripsi 55 embedding via `BIOMETRIC_ENCRYPTION_KEY` dari `.env` server, menghitung 1.485 pasangan lintas-user: **FAR @ θ=0.600 = 0.0000%** (min 0.6185, mean 1.1332, maks 1.5083); embedding tidak pernah keluar dari server, hanya angka distance; (3) **benchmark beban 20/30/40 pengguna** — WAF hCDN memblokir k6 eksternal (403 challenge browser), sehingga benchmark dijalankan dari dalam server via curl-loop `--resolve` ke 127.0.0.1, 39 token akun approved (1 token/pengguna), GET-only dashboard/jadwal/history, 45 detik per level: **p95 seluruh level < 2 detik**, failure seluruhnya HTTP 429 dari rate limiter `api` per-user (by design), log Laravel 0 error baru. Verifikasi pasca-aktivitas: `/api/health` 200, dashboard render normal, seluruh skrip kerja dihapus dari server (`kilo_*.sh`, `kilo_far_eval.php`, `impostor_distances.txt`). Bukti & artefak: `eksperimen/` lokal (impostor_distances_20260921.txt, hasil_analisis_far.txt, PROTOKOL_EKSPERIMEN.md, loadtest_server.sh).

**21 September 2026, 01:41 WIB — penambahan jadwal produksi + pembersihan token + rebuild APK debug:**
1. **Jadwal baru `jadwals` ID 9** dibuat via SQL SSH pada **21 September 2026, 01:41:32 WIB** (created_at DB dikonversi dari UTC 2026-09-20 18:41:32): *Pengantar Teknologi Informasi* (TI-301, 3 SKS, `mata_kuliah_id=6`), kelas **1D** (`kelas_id=22`, tingkat 1, semester aktif 2026/2027-1, **27 mahasiswa** terdaftar di pivot), dosen **Yusril Eka Mahendra, M.TI** (`dosen_id=10`), hari **Senin** (21 September 2026), jam **08:00–16:00** (`durasi_menit=480`), ruangan/geofence **Lab Komputer 1** (`geofence_id=1`, radius 500 m), status `aktif`. Prosedur: backup tabel `jadwals` dulu ke `~/backups/jadwals_pre_pti_20260921/jadwals.sql.gz` (gzip terverifikasi), cek duplikat (0 baris), insert, verifikasi join MK/kelas/dosen/geofence, lalu verifikasi end-to-end login mahasiswa 1D asli → `GET /api/mahasiswa/jadwal/today` mengembalikan jadwal ID 9 lengkap dengan jendela absensi otomatis (check-in 08:00 WIB, window tutup 16:15, pre-45 menit 07:45). Token verifikasi yang dipakai dihapus setelahnya. **Revisi 16:00 WIB hari yang sama:** jam selesai menjadi **18:00** (`durasi_menit=600`) dan radius geofence **30000 m** — lihat entri 12:00–16:00 WIB di atas.
2. **Pembersihan 50 token load test:** `personal_access_tokens` berisi 50 token `mobile-k6-*` (sisa benchmark 21 Sep, terdokumentasi di `eksperimen/r02_20u.json` versi redacted) dihapus via SQL; token pengguna asli (`mobile-app`, 8 token) tidak tersentuh. File summary `r02_20u.json` diarsipkan dengan token di-redaksi.
3. **Rebuild 4 APK debug (01:56–01:57 WIB)** dengan `--dart-define=API_BASE_URL=https://absensi.yusrilekamahendra.com/api`: `app-arm64-v8a-debug.apk` (117 MB), `app-armeabi-v7a-debug.apk` (92 MB), `app-x86_64-debug.apk` (107 MB), `app-debug.apk` fat (212 MB). Latar: APK debug sebelumnya menunjuk `http://192.168.8.3:8000/api` (LAN dev yang sudah tidak terjangkau HP — gejala "Sesi belum dapat diverifikasi / No route to host", terdiagnosis via adb logcat), sehingga HP tidak bisa mencapai server. Diagnosa & verifikasi pakai adb USB (`com.yusrilekamahendra.absensi_mahasiswa`): setelah install ulang, `AppConfig terbaca {"apiBaseUri":"https://absensi.yusrilekamahendra.com/api/"}`, `GET auth/me` mengembalikan 401 dari server (koneksi HTTPS OK; 401 wajar karena belum login ulang), aplikasi membuka halaman login. Build release + signing menyusul bila diperlukan.

**21 September 2026, 02:14–02:16 WIB — config production fail-closed (M-21/ADR-001):** backup `.env` dulu ke `~/backups/env_pre_config_20260921/.env.20260921_021415` (chmod 600; salinan lokal `eksperimen/backup_env_20260921/`), lalu via sed di server: `APP_ENV=local → production`, `APP_DEBUG=true → false`, `BIOMETRIC_ALLOW_CLIENT_CLAIMS=true → false`, kemudian `config:clear` + `cache:clear` (config cache tidak ada — env dibaca langsung). Verifikasi pasca-perubahan: `/api/health` 200; login API mahasiswa sukses; `GET auth/me` 200; **containment aktif** — POST check-in dengan client claim → **503 `TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED`** sesuai ADR-001; halaman tidak dikenal → 404 bersih (debug off); cookie sesi `Secure+HttpOnly+SameSite=lax` terverifikasi. Implikasi penting: **alur absensi on-device via client claim kini ditolak server** (containment permanen sesuai klaim penelitian — lihat ADR-001 & SECURITY.md); uji coba absen mahasiswa memakai jadwal PTI 1D akan mengalami 503 pada step check-in server-side. Eksekusi scheduler (cron) belum terpasang — item berikutnya.

**21 September 2026, 11:30 WIB — pembukaan alur absensi client-attested (revisi ADR-001, keputusan pemilik proyek):** alur absensi produksi dibuka agar demo/penelitian lapangan dapat berjalan — keputusan eksplisit pemilik proyek, dengan pemahaman bahwa klaim liveness/face-distance tetap **client-attested** (bukan verifier server-side; C-04/H-04 tetap residual risk sesuai ADR-001). Eksekusi: backup `RequireTrustedBiometricEvidence.php` + `biometric.php` ke `~/backups/env_pre_config_20260921/*.bak_20260921`, lalu deploy 2 file via SCP: (1) middleware kini hanya mengecek `config('biometric.allow_client_claims') === true` (pengecualian `! app()->isProduction()` dihapus); (2) komentar config diperbarui. Kemudian `BIOMETRIC_ALLOW_CLIENT_CLAIMS=true` di `.env` server + `config:clear` + `cache:clear`. Test unit gate diperbarui: 4 passed (flag dihormati di production; tanpa flag tetap fail-closed 503). Verifikasi pasca-deploy: `/api/health` 200; `POST /api/mahasiswa/attendance/permits` tanpa auth → **401 Unauthenticated** (bukan lagi 503 — gate terbuka, auth tetap dipaksa). Implikasi klaim penelitian: produksi tidak lagi fail-closed untuk endpoint biometrik; sistem **tidak boleh diklaim tahan proxy attendance/presentation attack** (tidak berubah dari sebelumnya), dan pencatatan keputusan ini ada di ADR-001, SECURITY.md, dan temuan.md.

**21 September 2026, 12:00–16:00 WIB — perbaikan scheduler, tuning jadwal demo, dan penyelesaian data absensi pertama:**
1. **Akar masalah "tidak dapat terhubung ke server" pada absensi mahasiswa:** log Laravel dipenuhi `production.ERROR: The Process class relies on proc_open` — `proc_open` masuk daftar `disable_functions` di `/opt/alt/php83/link/conf/alt_php.ini` milik CageFS/CloudLinux Hostinger, sehingga **semua** task terjadwal (`attendance:auto-close`, `attendance:mark-absent`, `notifications:process-outbox`, reminder, dst.) gagal dieksekusi setiap menit sejak cron aktif. Perbaikan: `proc_open` dikeluarkan dari daftar (backup config: `~/alt_php.ini.bak`), diverifikasi dengan `php artisan schedule:test --name='attendance:auto-close'` → DONE, dan log error scheduler berhenti. Catatan ops: file CageFS dapat ditimpa dari sesi SSH — bila Hostinger mengembalikan defaultnya setelah maintenance, gejala yang sama muncul kembali.
2. **Tuning jadwal demo PTI (keputusan pemilik proyek):** jadwal ID 9 jam selesai **16:00 → 18:00** (`durasi_menit` 480 → 600) dan geofence Lab Komputer 1 (ID 1, titik -0.054646/109.346036) radius **500 → 5000 → 30000 m** (30 km, demo penelitian lapangan). Backup sebelum ubah: `~/backups/jadwal9_geofence1_backup_20260921_152534.json`. Jam/radius tersaji dari server — tanpa rebuild APK.
3. **Bug scheduler baru ditemukan & diperbaiki:** `attendance:send-reminder` gagal exit code 1 (`Undefined variable $totalSent` di `SendAttendanceReminder.php:71` — variabel tidak diinisialisasi). Diperbaiki (inisialisasi `$totalSent = 0`), deploy via SCP, verifikasi eksekusi manual OK. Commit bersama perubahan ini.
4. **Penyelesaian 5 attendance `pending` jadwal PTI hari ini:** akar statusnya bukan checkout terlewat, melainkan check-in sangat terlambat (12:14–13:19 vs jam masuk 08:00, melewati batas terlambat 50% durasi) sehingga masuk `pending` (butuh approval dosen, `alpha_menit` penuh). Atas keputusan pemilik proyek, kelima record (ID 135–139) di-approve menjadi **`hadir`**, `alpha_menit=0`, `approval_status=approved` atas nama dosen jadwal (`dosen_id=10`), lengkap dengan AttendanceLog + AuditTrail per record. Backup sebelum ubah: `~/backups/attendance_pending_backup_20260921_154207.json`. Riwayat check-in/out mahasiswa: tanpa checkout, `attendance:auto-close` kini hidup dan akan mengisi `checkout_time` = jam selesai jadwal + `is_auto_closed=true` tanpa mengubah status.
5. **Retry GPS di aplikasi (di commit yang sama):** `AttendanceLocationService.acquire()` kini mencoba ulang hingga 3× untuk kegagalan fix yang bisa dipulihkan (fix cache Android dengan timestamp > maxAge) dan langsung berhenti untuk kondisi permanen (mock location, izin, di luar geofence, timestamp masa depan). 3 test baru memfinalisasi semantik retry; total 200 test lulus.
6. **Rebuild 4 APK debug (15:11–15:12 WIB)** dengan fix retry + `API_BASE_URL` produksi: `app-arm64-v8a-debug.apk` (117 MB), `app-armeabi-v7a-debug.apk` (92 MB), `app-x86_64-debug.apk` (107 MB), `app-debug.apk` fat (212 MB).
7. **Ops script demo** (`eksperimen/demo_on.sh` / `demo_off.sh`) ditambahkan untuk toggle `BIOMETRIC_ALLOW_CLIENT_CLAIMS` di server dengan backup + restore permission — direview: tanpa flip `APP_ENV`, work file di luar web root, permission asli dipertahankan.

**21 September 2026, 18:00–18:12 WIB — N-01 + N-02 (mobile) dan rebuild APK:**

1. **N-01 — gate frame netral pasca-liveness:** verifikasi wajah absensi tidak lagi memakai frame ekspresi challenge; verifikasi menunggu frame netral berikutnya (frontal + mata terbuka, ambang identik enrollment, maks 5 detik, timeout = ulang liveness), dan fase tunggu dibatalkan saat diskontinuitas wajah (binding anti-spoofing dari review keamanan). Detail lengkap di [temuan.md N-01](temuan.md#n-01-verifikasi-wajah-dijalankan-pada-frame-ekspresi-challenge--frr-lapangan-menumpuk).
2. **N-02 — migrasi built-in Kotlin + upgrade plugin:** app gradle bermigrasi (hapus `kotlin-android`/`kotlinOptions`), 5 plugin di-upgrade (`shared_preferences_android` 2.4.28, `device_info_plus` 13.2.0, `camera` 0.12.1, `flutter_secure_storage` 10.3.4, `file_picker` 12.3.0). Breaking change `FilePicker.platform` (12.x) diperbaiki di `leave_page.dart`. Saat itu sisa `safe_device` masih KGP — **dituntaskan 22 September 2026** (entri 22:00 WIB di bawah): plugin dihapus total, tidak ada lagi plugin KGP legacy. Detail di [temuan.md N-02](temuan.md#n-02-plugin-android-memakai-kotlin-gradle-plugin-kgp--build-gagal-di-flutter-mendatang-agp-9).
3. **Rebuild 4 APK debug (18:09–18:11 WIB)** dengan `API_BASE_URL` produksi, memuat N-01 + N-02: `app-arm64-v8a-debug.apk` (119 MB), `app-armeabi-v7a-debug.apk` (93 MB), `app-x86_64-debug.apk` (108 MB), `app-debug.apk` fat (213 MB). Verifikasi: `flutter test` 207/207, `flutter analyze` bersih, warning KGP build hanya untuk `safe_device`.

**21 September 2026, 21:55–22:20 WIB — reset data demo jadwal PTI + geser jadwal ke 22 September:**

1. **Latar (keputusan pemilik proyek):** seluruh perbaikan besar (N-01 gate frame netral, N-02 migrasi Kotlin) baru masuk APK rebuild terakhir; data absensi jadwal PTI hari ini (dihasilkan APK lama) dianggap tidak lagi merepresentasikan perilaku aplikasi terbaru, jadi demo penelitian di-reset dengan jadwal pengganti besok.
2. **Backup sebelum ubah (terverifikasi, server + lokal `backup_server_jadwal9_20260921/`):** jadwals id 9 (1 baris), attendances jadwal 9 (27: 5 `hadir` hasil approve manual + 22 `alpha` otomatis scheduler), attendance_logs terkait (10), attendance_permits (32), alpha_accumulations 27 mahasiswa (semua 600 menit / 10 jam, `sp_status=aman`), notifikasi+outbox hari ini (5 `approval_needed` ke dosen, belum ada SP/reminder terkirim). SHA256 tiap file dicatat.
3. **Eksekusi dalam satu transaksi SQL** (rollback penuh bila gagal): hapus logs → permits → attendances → reset `alpha_accumulations` 27 mahasiswa (600 → 0, semester 4) → hapus jadwal 9 (Senin 08:00–18:00) → insert jadwal pengganti **ID 10: Selasa 22 Sep 2026, 09:00–17:00** (480 menit), identik lainnya: PTI (mk 6), kelas 1D (kelas 22), dosen sama (10), Lab Komputer 1, geofence 1 (radius tetap 30 km dari tuning demo). `pertemuan_ke` mulai dari 1 lagi karena seluruh data pertemuan lama terhapus.
4. **Verifikasi pasca:** 0 attendance/permits/logs tersisa untuk jadwal 9, 0 alpha_accumulations non-nol di antara 27 mahasiswa, jadwal 9 hilang, jadwal 10 aktif terdaftar; `/api/health` 200. Konsekuensi jadwal: window absensi besok = not-before 08:45 (toleransi masuk 15 menit), capture sampai 17:15 (toleransi pulang 15 menit); scheduler `auto-close`/`mark-absent` akan memakai jam selesai 17:00. Tidak ada notifikasi SP yang pernah terkirim, jadi reset alpha tidak menimbulkan notifikasi palsu; 5 notifikasi `approval_needed` lama ke dosen dibiarkan (historis, tidak berbahaya).

**22 September 2026, 17:42 WIB — radius seluruh geofence menjadi 500 km (keputusan pemilik proyek):** semua 8 baris `geofences` di-update ke `radius = 500000` (sebelumnya 30.000 m untuk Lab Komputer 1, 50–100 m lainnya) — tanpa deploy kode, hanya perubahan data. Prosedur: koneksi SSH (pola `ssh.md`), backup tabel dulu ke `~/backups/geofences_pre_radius_20260922/geofences.sql.gz` (gzip terverifikasi), `UPDATE geofences SET radius = 500000`, verifikasi select → 8/8 baris 500000, `/api/health` 200, script kerja dihapus dari server. Latar: laporan lapangan error "Anda di luar area perkuliahan" padahal jarak ~4,5 km — sumbernya radius 30 km yang lebih kecil dari lokasi demo mahasiswa; radius 500 km membuat validasi geofence server tidak lagi menjadi penghalang demo penelitian (catatan risiko: klaim lokasi memang client-attested sesuai ADR-001, jadi ini tidak menambah kelas risiko baru, tetapi nilai geofence tidak lagi realistis untuk data penelitian berbasis lokasi).

**22 September 2026, 18:23 WIB — reset absensi hari ini untuk uji coba ulang (permintaan pemilik proyek):** semua attendance jadwal 10 tanggal 22 Sep (17 `hadir_terlambat` + 13 `alpha`) dan seluruh permit jadwal 10 (termasuk 5 yang sudah di-extend) dihapus; `alpha_accumulations` semester aktif (semester 4) direset ke `aman`/0 menit dan 3 `sp_records` semester aktif dihapus. Backup dulu ke `~/backups/attendance_reset_20260922/` (`full.sql.gz` — attendances/permits/logs/audit/notifications; `alpha_sp.sql.gz`). Verifikasi: 0 attendance, 0 permit, 0 baris alpha non-nol semester 4, 0 SP semester 4, `/api/health` 200. Sengaja dibiarkan: `attendance_logs` (18 baris hari ini — historis untuk penelitian R-06), baris `alpha_accumulations` semester lama (riwayat), dan notifikasi lama. Jadwal tetap 09:00–20:00, jadi seluruh 27 mahasiswa bisa uji check-in/check-out dari awal sampai 20:00. Sebelum reset, jadwal 10 sempat diperpanjang 17:00 → 20:00 (`durasi_menit` 480 → 660, backup `~/backups/jadwal10_pre_extend_20260922/`) dan 5 permit aktif lama ikut di-extend ke capture 20:15/sync 20:45.

**22 September 2026, 20:20 WIB — jadwal 10 diperpanjang ke 22:00 + reset data uji kedua (permintaan pemilik proyek):** jadwal 10 di-update `jam_selesai` 20:00 → 22:00 (`durasi_menit` 660 → 780), lalu seluruh attendance jadwal 10 (9 `pending` + 18 `alpha`), attendance_logs terkait, dan 45 permit jadwal 10 dihapus; `alpha_accumulations` semester 4 direset ke `aman`/0 menit (semua flag notifikasi dinolkan) dan `sp_records` semester 4 (0 baris) dipastikan kosong. Backup dulu ke `~/backups/attendance_reset_20260922_b/` (`full.sql.gz` — attendances/permits/logs/alpha/sp/notifications; `jadwal10.sql.gz`). Semua dalam satu transaksi SQL. Verifikasi: jadwal 10 = 09:00–22:00/780 menit aktif, 0 attendance, 0 permit, 0 alpha non-nol semester 4, 0 SP semester 4, `/api/health` 200. Seluruh 27 mahasiswa bisa mendaftar (check-in) ulang dari awal sampai window capture 22:15.

**22 September 2026, 22:00 WIB — hapus `safe_device` (N-02 tuntas) + rebuild 4 APK debug:** plugin terakhir penyandang KGP lama dihapus dari `pubspec.yaml`; sinyal mock perangkat kini dibaca dari flag `Location.isMock()` Android via `Geolocator.getLastKnownPosition` (fail-open, lapis pelengkap — fix utama tetap `position.isMocked`; semantik deteksi fake GPS identik). Regresi retry permit-bound ditambahkan (`attendance_retry_challenge_test.dart`, 2 kelompok: perilaku liveness + kontrak source halaman). Verifikasi: `flutter analyze` bersih, `flutter test` **214/214** lulus, `flutter build apk` fat + 3 ABI sukses **tanpa warning KGP sama sekali**. Breakdown APK (21:55–21:56 WIB): `app-arm64-v8a-debug.apk` (117 MB), `app-armeabi-v7a-debug.apk` (91 MB), `app-x86_64-debug.apk` (106 MB), `app-debug.apk` fat (211 MB), dengan `--dart-define=API_BASE_URL=https://absensi.yusrilekamahendra.com/api`. Catatan pesan baru pada gagal capture lokasi: akurasi vs umur fix kini dibedakan (`location_policy_rejected` membawa angka fix yang ditolak). Detail di [temuan.md N-02](temuan.md#n-02-plugin-android-memakai-kotlin-gradle-plugin-kgp--build-gagal-di-flutter-mendatang-agp-9).

**23 September 2026, 07:14 WIB — jadwal PTI baru untuk Rabu 23 Sep (permintaan pemilik proyek):** jadwal produksi baru `jadwals` **ID 11** di-insert via SSH (pola `ssh.md`, query lewat file SQL — `mysql -e` inline rusak quoting PowerShell): PTI TI-301 (`mata_kuliah_id=6`), kelas 1D (`kelas_id=22`, semester aktif, **27 mahasiswa** terdaftar), dosen **Yusril Eka Mahendra, M.TI** (`dosen_id=10`), **Rabu, 09:00–16:00** (`durasi_menit=420`), **Lab Komputer 1** (`geofence_id=1`), status `aktif` — identik dengan jadwal 10 kecuali hari/jam. Backup tabel dulu ke `~/backups/jadwals_pre_pti_20260923/jadwals.sql.gz` (gzip terverifikasi). Cek duplikat: tidak ada jadwal Rabu untuk kelas 22 + MK 6 sebelumnya (jadwal 10 tetap Selasa, tidak diubah). Verifikasi pasca-insert: join lengkap dari DB cocok (TI-301 / kelas D / dosen / geofence Lab Komputer 1 / 09:00–16:00 / aktif), `/api/health` 200. Window absensi: check-in not-before 08:45 (toleransi 15 menit), capture sampai 16:15 (toleransi pulang 15 menit); scheduler memakai jam selesai 16:00. Script kerja server dihapus total (termasuk sisa script dari sesi sebelumnya: `extend_jadwal2.sh`, `final.sh`, `kilo_db.sh`, `reset_alpha.sh`, `reset_step1.sh`, `verify_backup.sh`); `cron-laravel.sh` dipertahankan (dipakai cron hPanel).

**24 September 2026, 09:54 WIB (02:54 UTC) — deploy fitur foto attempt berisiko + geofence prodi-scoped via SSH:** kode FR-ABS-009 terdeploy ke host live. Backup dulu ke `~/backups/absensi_pre_deploy_20260924_025405/` (DB penuh gzip 484K terverifikasi `gzip -t`, storage app 20M tar.gz, `.env`). Upload 14 file backend via SCP: 5 controller (`Api/Mahasiswa/AttendanceController`, `Api/Mahasiswa/OfflineSyncController`, `Api/Admin/GeofenceController`, `Web/AttendanceController`, `PrivateFileController`), 2 model (`Attendance`, `AttendanceLog`), `AuthorizationService`, `AttemptFotoService` (baru), `PurgeAttemptFotos` (baru), migrasi `2026_09_23_000001_add_attempt_foto_columns`, dan 3 routes (`api.php`, `console.php`, `web.php`) — `php -l` bersih seluruhnya. `php artisan migrate --force` → DONE (38ms). Upload `public/build` hasil build Vite lokal + fix permission `chmod 755`/`644` (pola 14 September — scp membuat folder `700`). Clear config/cache/route/view. Verifikasi pasca-deploy: `/api/health` → 200; `/dashboard` → 200 dengan asset `app-CAPDNvg6.js` → 200; route `private.attempt-foto` terdaftar (`GET /private/attempt-fotos/{attendanceLog}` API + web); `migrate:status` → Ran; `attendance:purge-attempt-fotos` ada di `routes/console.php` (scheduler harian via cron `schedule:run` yang sudah aktif); log Laravel 0 error baru. Script kerja dihapus dari server dan lokal. Tidak ada perubahan env.

Yang **belum diverifikasi** pada host live (masuk L-09 sampai ada bukti):

1. ~~Scheduler/queue worker long-running~~ — **scheduler aktif sejak 21 September 2026, 12:00 WIB** (cron `schedule:run` per menit; perbaikan `proc_open` pada `disable_functions`). Task terverifikasi: `schedule:test` DONE, log cron `DONE` per menit, dan fix bug reminder terdeploy. Queue worker tetap mengikuti mekanisme cron (`queue:work --stop-when-empty` via scheduler).
2. Mail delivery (reset/activation) dari host.
3. `/api/healthz` tetap tidak boleh diekspos publik — batasi via hPanel/`.htaccess` bila perlu.

## Backend Production (Runbook Umum)

1. Install dependency dengan committed lockfiles: `composer install --no-dev --optimize-autoloader` dan `npm ci`.
2. Buat `.env` melalui secret manager; jangan menyalin `.env` development.
3. Isi `APP_KEY`, biometric key/key ID, database, mail, session, queue, dan VAPID sesuai fitur yang digunakan.
   Gunakan `APP_TIMEZONE=Asia/Pontianak`; attendance window dan scheduler memakai timezone aplikasi ini.
4. Jalankan `php artisan migrate --force`.
5. Jalankan seeder production secara eksplisit. `UserSeeder` memang diblokir di production, tetapi jangan menjalankan seluruh demo academic seed tanpa review.
6. Build dashboard: `npm run build`.
7. Cache config/routes/views setelah seluruh env final.
8. Beri permission hanya pada `storage/` dan `bootstrap/cache/` yang diperlukan runtime.
9. Jalankan scheduler dan queue worker sebagai proses long-running yang dipantau. Pilih mekanisme sesuai OS host:
   - **Linux (production)**: install manifest `deploy/systemd/absensi-queue.service`, `absensi-schedule.service`, dan `absensi-schedule.timer`, lalu sesuaikan `/srv/absensi` serta `/etc/absensi/absensi.env` dengan host.
   - **Windows (dev/on-prem)**: `php artisan schedule:work` dijalankan oleh **Windows Task Scheduler**. Repo menyertakan `backend/schedule-worker.bat` (wrapper yang men-set path php + project lalu memanggil `schedule:work`); daftarkan sebagai task (mis. `AbsensiMahasiswaScheduler`) dengan trigger *At log on*, *restart on failure*, dan *execution time limit* unlimited agar scheduler hidup permanen tanpa perintah manual. Registrasi task memerlukan hak admin satu kali. Untuk development lokal harian cukup `php artisan serve:all` (lihat seksi Development Lokal di atas) — Windows Task Scheduler hanya untuk skenario on-prem permanen.
   Scheduler inilah yang memicu `attendance:auto-close` dan `attendance:mark-absent` (keduanya `everyMinute`), reminder, notification outbox, dan backup. Tanpa scheduler yang hidup, ALPHA dan auto-close tidak akan pernah tercatat.
10. Gunakan `/api/health` sebagai public liveness. Batasi `/api/healthz` ke operator/internal network.

Foto enrollment/re-enrollment dan dokumen izin tidak dipublikasikan melalui `storage:link`. Endpoint private controller adalah access path resminya. `storage:link` hanya boleh digunakan untuk asset yang memang diklasifikasikan publik.

## Environment Minimum

Production harus menggunakan:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://absensi.yusrilekamahendra.com
APP_TIMEZONE=Asia/Pontianak
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
DB_CONNECTION=mysql
QUEUE_CONNECTION=database
BIOMETRIC_ALLOW_CLIENT_CLAIMS=true
```

> Revisi 21 September 2026: alur absensi client-attested diaktifkan di production
> berdasarkan keputusan pemilik proyek (lihat catatan deploy tanggal tersebut).
> `BIOMETRIC_ALLOW_CLIENT_CLAIMS=false` mengembalikan fail-closed 503.

Session cookie bersifat fail-closed di production (M-21): bila `SESSION_SECURE_COOKIE` tidak diset, aplikasi tetap memaksa cookie `Secure` + `HttpOnly` dan `SameSite` minimal `lax`. Jangan menyetel `SESSION_SECURE_COOKIE=false` di production; itu mematikan proteksi HTTPS-only cookie. `SameSite=none` hanya boleh dipakai bila memang lintas situs dan otomatis dipasangkan dengan `Secure`.

Mail delivery adalah dependency keamanan untuk reset/activation. Verifikasi pengiriman sebelum provisioning user.

Lifecycle FCM mobile (register/refresh/revoke + handler) sudah diimplementasikan. Release default mematikan FCM melalui `ENABLE_FCM_PUSH=false`; aplikasi tidak mengklaim atau mencoba push tanpa konfigurasi. Untuk mengaktifkan push, inject `google-services.json` dari secret manager sebelum build, set protected variable `ENABLE_FCM_PUSH=true`, dan isi `FIREBASE_PROJECT_ID`/`FIREBASE_CREDENTIALS_PATH` pada backend. Workflow fail-closed bila FCM diaktifkan tanpa file konfigurasi. Service account JSON tetap private dan tidak boleh masuk repository.

Attendance/enrollment berbasis client scalar kini **diizinkan** di production sejak 21 September 2026 (keputusan pemilik proyek, revisi ADR-001): `BIOMETRIC_ALLOW_CLIENT_CLAIMS=true` membuat middleware `RequireTrustedBiometricEvidence` loloskan request, sehingga endpoint permit, check-in/out, offline sync, enrollment/re-enrollment, dan approval biometrik berfungsi normal. Bukti liveness/face-distance tetap **client-attested** — server tidak mengulang matching/liveness (trusted verifier server-side tetap **di luar scope penelitian** dan ditolak di [ADR-001](ADR-001-trusted-biometric-verifier.md)). Setel `BIOMETRIC_ALLOW_CLIENT_CLAIMS=false` untuk kembali fail-closed (`503 TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED`). Sistem **tidak boleh diklaim tahan proxy attendance atau presentation attack** — batas klaim di `THREAT-MODEL-ATTENDANCE.md` tetap berlaku.

## Android Release

Workflow manual `.github/workflows/android-release.yml` membutuhkan protected environment `production` dan konfigurasi berikut:

| Name | Type |
|---|---|
| `ANDROID_KEYSTORE_BASE64` | GitHub secret |
| `ANDROID_KEYSTORE_PASSWORD` | GitHub secret |
| `ANDROID_KEY_ALIAS` | GitHub secret |
| `ANDROID_KEY_PASSWORD` | GitHub secret |
| `GOOGLE_SERVICES_JSON_BASE64` | GitHub secret; wajib hanya bila `ENABLE_FCM_PUSH=true` |
| `API_BASE_URL` | Protected GitHub variable, HTTPS dan berakhiran `/api` |
| `ENABLE_FCM_PUSH` | Protected GitHub variable; default `false`, `true` hanya setelah config di-inject |

Gradle juga mendukung untracked `android/key.properties` untuk build operator lokal. Release tidak pernah fallback ke debug signing.

Build lokal:

```powershell
flutter build appbundle --release --dart-define=API_BASE_URL=https://absensi.yusrilekamahendra.com/api
```

Verifikasi certificate signer, app startup, login, checkout navigation, offline recovery, camera, dan GPS pada physical Android device sebelum distribusi. Permit/check-in/out production berjalan dengan bukti client-attested sejak revisi ADR-001 21 September 2026; bukti tetap bukan verifier server-side.

## iOS

Keputusan release H-17: **iOS tidak didukung dan tidak termasuk release matrix**. Folder `frontend/ios` dipertahankan hanya sebagai scaffold pengembangan, bukan artifact yang boleh diterbitkan. Tidak ada workflow IPA/TestFlight, signing, capability, atau dukungan operasional iOS. Platform mobile produksi satu-satunya adalah Android. Membuka dukungan iOS di masa depan memerlukan keputusan release baru, Podfile lock, macOS CI build, signing/capability, Firebase/APNs bila push diaktifkan, serta physical-iPhone camera/GPS test.

## Continuous Integration

Backend/frontend workflow dikonfigurasi pada setiap `push`/`pull_request`; release/device workflow dijalankan manual:

| Workflow | Isi |
|---|---|
| `.github/workflows/backend-ci.yml` | MySQL service, `composer validate --strict`, `check-platform-reqs`, `composer audit`, `npm ci` + `npm run build`, `php artisan test` |
| `.github/workflows/frontend-ci.yml` | Flutter `3.44.2`, enforced lockfile, analyzer strict, dan `flutter test` |
| `.github/workflows/android-device-tests.yml` | Manual Firebase Test Lab physical low/mid/high matrix untuk camera converter contract |

Analyzer warning maupun info menjadi CI failure (L-05). `android-release.yml` (manual `workflow_dispatch`) memakai gate analyzer strict yang sama sebelum membangun AAB. Device-test workflow memerlukan environment `device-testing`, secret `FIREBASE_TEST_LAB_CREDENTIALS_JSON`, serta variables `FIREBASE_PROJECT_ID`, `FIREBASE_TEST_RESULTS_BUCKET`, dan tiga device spec `FIREBASE_ANDROID_DEVICE_LOW/MID/HIGH` dalam format `model=...,version=...,locale=id,orientation=portrait`.

> Definisi workflow bukan bukti enforcement. Sebelum release, buktikan latest Backend
> CI dan Frontend CI green pada clean clone, branch `main` mewajibkan checks tersebut,
> environment `production`/`device-testing` protected, dan workflow manual terkait
> berhasil. Sampai itu tersedia, L-09 tetap terbuka.

Seluruh pekerjaan lokal sudah di-push ke `origin/main`; push terakhir 12 September 2026 (`840082b` — test invarian guard route, runner k6 R-02, scaffold Playwright E2E, pembersihan dead code Flutter) memicu workflow push/PR. **Push hanya memicu workflow, bukan membuktikan
hasilnya** — status green tetap harus diperiksa langsung di GitHub karena
GitHub CLI tidak tersedia di workspace pengembangan.

## Master Data Lifecycle dan Migration

- Rekam akademik historis memakai FK `ON DELETE RESTRICT` (M-19). Hard delete master (user/jadwal/mata_kuliah/semester) akan ditolak database selama masih ada riwayat; gunakan arsip (soft delete) atau flag `status`.
- Migration constraint domain (M-20) dan restrict lifecycle (M-19) bersifat idempotent dan reversible; `migrate`, `migrate:rollback`, dan `migrate:fresh` sudah diverifikasi pada MySQL 8.
- Sebelum memasang constraint pada dataset lama, pastikan data existing bersih (koordinat/urutan waktu/threshold valid, tanpa duplikat MK `kelas` NULL).

## Preflight dan Rollback

- Jalankan `composer validate --strict`, `composer check-platform-reqs`, backend tests, web build, Flutter tests, dan `flutter analyze --fatal-warnings --fatal-infos`.
- Backup database dan private storage sebelum migration production.
- Simpan key biometrik lama selama masih ada row dengan key ID tersebut.
- Rollback application dan database harus mempertahankan kemampuan decrypt data. Migration purge biometrik tidak boleh di-rollback secara destruktif.
- Pantau queue failure, scheduler, mail activation, auth rejection, dan attendance permit rejection setelah deploy.

Deploy berdasarkan commit SHA/tag ke direktori release baru, lalu pindahkan symlink `/srv/absensi/current` secara atomik. Setelah symlink berubah: jalankan `php artisan migrate --force`, `php artisan optimize`, `php artisan queue:restart`, restart `absensi-queue`, dan cek `/api/health`. Rollback aplikasi dilakukan dengan mengembalikan symlink ke release sebelumnya lalu mengulangi restart/health check. Migration hanya boleh di-rollback bila migration release tersebut eksplisit reversible dan tidak menghapus data; jika tidak, pertahankan schema forward-compatible dan rollback aplikasi saja.

Backup wajib mencakup database dan `storage/app/private`, `storage/app/face`, serta keyring biometrik di secret manager. Restore drill harus dilakukan ke host/database terisolasi dan membuktikan login, decrypt embedding, private file download berpolicy, queue, scheduler, dan health sebelum backup dianggap valid.
