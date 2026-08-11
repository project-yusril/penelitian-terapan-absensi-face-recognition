# FINAL TASK — Sisa Pekerjaan Sistem Absensi Mahasiswa

> **ARSIP SNAPSHOT, bukan tracker aktif.** Backlog dan acceptance authoritative
> berada di [temuan.md](temuan.md), status terpadu di [README.md](README.md), dan
> deployment authoritative di [DEPLOYMENT.md](DEPLOYMENT.md). Checkbox/angka di
> bawah merekam perencanaan pada saat dokumen dibuat dan tidak boleh dipakai untuk
> keputusan release tanpa memeriksa dokumen current.
>
> Sinkronisasi 11 Agustus 2026: H-13, H-17, dan L-02 selesai. C-04/H-04
> terkontain fail-closed tetapi menunggu trusted verifier; H-16 menunggu physical
> Android matrix; L-09 menunggu green remote CI/protected enforcement. iOS tidak
> didukung dan FCM default release off.
>
> Sinkronisasi lanjutan 11 Agustus 2026: R-04 (filter dataset analisis per prodi),
> MS-01 ([matriks role-permission-prodi](ROLE-PERMISSION-MATRIX.md)), M-23 (limiter
> API terautentikasi), dan M-24 (scope aktor endpoint analisis) selesai. Sisa
> milestone tanpa ID kini dilacak sebagai MS-02 (policy retention biometrik) dan
> MS-03 (browser/device E2E).

**Tanggal dibuat:** 20 Juni 2026
**Konteks:** Hasil audit menyeluruh terhadap `task-master.md`, `task-backend.md`,
`task-frontend.md`, `task-mobile.md`, dan `ANALISIS-DASHBOARD-GAP.md`, dibandingkan
dengan kondisi nyata kode di `backend/` dan `frontend/`.

> **Ringkasan:** Phase 1–12 sudah selesai (backend API, web dashboard, mobile app,
> face recognition, attendance, analisis & evaluasi, integration & unit testing).
> Sisa pekerjaan riil terbagi menjadi 4 kelompok di bawah ini. Kerjakan berurutan,
> tandai `[x]` saat selesai.

---

## STATUS GLOBAL

| Phase | Nama | Status |
|-------|------|--------|
| 1 | Project Setup & Foundation | ✅ Selesai |
| 2 | Authentication & User Management | ✅ Selesai |
| 3 | Academic Module (CRUD) | ✅ Selesai |
| 4 | Attendance System (Core) | ✅ Selesai |
| 5 | SP & Early Warning System | ✅ Selesai |
| 6 | Notification & Export | ✅ Selesai |
| 7 | Web Dashboard (Vue/Inertia) | ✅ Selesai |
| 8 | Mobile Foundation | ✅ Selesai |
| 9 | Mobile Face Recognition | ✅ Selesai |
| 10 | Mobile Attendance Flow | ✅ Selesai |
| 11 | Menu Analisis & Evaluasi | ✅ Selesai |
| 12 | Integration & Testing | 🔄 Hampir selesai (E2E manual belum) |
| 13 | Final Polish & Deployment | ❌ Belum dimulai |

**Enhancement tambahan (di luar task-master, sudah selesai):** 2FA, soft deletes,
audit trail, maintenance mode, backup database, dan **Web Push (VAPID)**.

---

## GROUP A — Phase 13: Final Polish & Deployment ❌

Prioritas utama. Sebagian hardening build/release sudah dikerjakan, tetapi deployment production belum dilakukan.

### A.1 Persiapan Server (VPS)
- [ ] Provision VPS (OS, user non-root, firewall/UFW, SSH key)
- [ ] Install stack: PHP 8.3+, Composer, Node.js, MySQL, Nginx, Redis (opsional), Supervisor
- [ ] Konfigurasi Nginx (server block, root ke `backend/public`, gzip, cache header)
- [ ] Setup database production + user terbatas (bukan root)
- [ ] Konfigurasi PHP-FPM (pool, memory_limit, upload_max_filesize untuk foto enrollment)

### A.2 Deployment Aplikasi
- [ ] Clone repo + `composer install --no-dev --optimize-autoloader`
- [ ] `npm ci && npm run build` (build aset Inertia/Vue)
- [ ] Setup `.env` production (APP_ENV=production, APP_DEBUG=false, DB, MAIL, FCM, VAPID)
- [ ] Provision `APP_KEY`/biometric key melalui secret manager, lalu `migrate --force`; jalankan hanya seeder production yang telah direview
- [ ] `php artisan config:cache route:cache view:cache`
- [ ] Verifikasi private biometric/document disks; jangan gunakan `storage:link` sebagai akses enrollment/izin
- [ ] Set permission `storage/` & `bootstrap/cache/`
- [ ] Setup scheduler agar hidup permanen (auto-close, mark-absent, reminder, backup — semua `everyMinute`):
  - Linux: cron `* * * * * php artisan schedule:run`
  - Windows: Windows Task Scheduler menjalankan `backend/schedule-worker.bat` (`php artisan schedule:work`), trigger *At log on* + *restart on failure* (task `AbsensiMahasiswaScheduler`)
- [ ] Setup queue worker via Supervisor (`queue:work`) untuk notifikasi/push

### A.3 SSL & Keamanan Produksi
- [ ] Pasang SSL (Let's Encrypt / Certbot) + auto-renew
- [ ] Paksa HTTPS redirect + HSTS header
- [ ] Generate VAPID production (`php artisan webpush:vapid --write`) & set subject email institusi
- [x] Tetapkan FCM sebagai explicit opt-in: default release off dan workflow fail-closed bila opt-in tanpa secret/config
- [ ] Jika operator mengaktifkan FCM, sediakan Firebase project/credential dan verifikasi push runtime di perangkat
- [ ] Review CORS (`config/cors.php`) untuk origin produksi
- [ ] Pastikan `.env`, file kredensial, backup tidak ter-expose ke publik

### A.4 Final Testing di Produksi
- [ ] Smoke test login 6 role di domain produksi
- [ ] Setelah trusted verifier tersedia, uji alur absensi end-to-end (mobile → API produksi); flow production saat ini sengaja diblokir
- [ ] Uji Web Push & FCM di produksi (HTTPS)
- [ ] Uji export Excel/PDF + generate SP di produksi
- [ ] Verifikasi scheduler & queue berjalan (cek log); pastikan ALPHA tercatat otomatis beberapa menit setelah jadwal selesai dan attendance yang lupa checkout ter-auto-close

### A.5 Build & Distribusi APK
- [x] Gunakan `API_BASE_URL` protected variable dan HTTPS fail-closed
- [x] Konfigurasi signing key release melalui CI/secret manager
- [x] Siapkan workflow signed `appbundle`; eksekusi release memerlukan production secrets
- [ ] Uji APK release di device fisik (kamera, GPS, push)
- [ ] Siapkan distribusi (link unduh / Play Store internal testing)

### A.6 Finalisasi Dokumentasi
- [x] Klasifikasikan `ANALISIS-DASHBOARD-GAP.md` sebagai arsip historis
- [x] Tulis panduan deployment terintegrasi di `DEPLOYMENT.md`
- [ ] Tulis panduan pengguna (admin, kaprodi, dosen, mahasiswa)
- [ ] Dokumentasi setup Web Push & FCM (termasuk catatan OpenSSL Windows)

---

## GROUP B — Task 12.6: End-to-End Testing Manual (Mobile) 🔄

Butuh device/emulator + backend berjalan. Dari `task-mobile.md`.

- [ ] Login → Home → cek jadwal hari ini
- [ ] Enrollment: kamera → liveness → capture → submit → status pending
- [ ] Admin/Kaprodi approve enrollment → mahasiswa bisa check-in
- [ ] Check-in: geofence → liveness → face verify → status → submit
- [ ] Check-out: alur sama → durasi terhitung
- [ ] History: lihat riwayat absensi
- [ ] Leave request: ajukan izin → admin approve → alpha terupdate
- [ ] Bila FCM diaktifkan untuk release: terima push dan mark read
- [ ] SP status: lihat akumulasi alpha & SP records
- [ ] Logout → login lagi → data persisten

---

## GROUP C — Verifikasi Test Backend (item "[x] ?") 🔄

`task-backend.md` sekarang diklasifikasikan historis. Full suite telah lulus,
tetapi coverage per flow tetap mengikuti evidence dan gap di `temuan.md`.

- [ ] Enrollment flow test (submit → pending → approve → embedding aktif + foto)
- [ ] Check-in test (tepat waktu, terlambat, pending, di luar geofence)
- [ ] Check-out test (normal, pulang awal, terlambat checkout)
- [ ] Alpha accumulation test (berbagai skenario)
- [ ] SP detection test (AMAN → SP1 → SP2 → SP3 → DO)
- [ ] SP approval flow test (draft → kaprodi → kajur → final)
- [ ] Notification triggers test (penerima benar per skenario)
- [ ] Dosen approve/reject/override test
- [ ] Export Excel & PDF test (file valid)
- [x] Jalankan `php artisan test` penuh: **206 test/735 assertion lulus** (11 Agustus 2026, setelah R-04/M-23/M-24; sebelumnya 198/711, 189/666, dan 182/653 pada 9 Agustus 2026). Angka current selalu di [temuan.md](temuan.md) — dokumen ini arsip.

---

## GROUP D — Opsional / Nice-to-have

Tidak menghalangi rilis, kerjakan bila ada waktu.

- [ ] `l5-swagger` — dokumentasi API otomatis (ditandai optional di task-backend)
- [x] Tandai `ANALISIS-DASHBOARD-GAP.md` sebagai arsip historis
- [ ] Tambah Feature test untuk modul web baru (2FA, audit trail, maintenance, web push)
- [x] Audit aksesibilitas (a11y) statis halaman dashboard (L-07: Modal, DataTable, icon button, chart, dropdown menu akun, notifikasi; sisa hanya scan runtime axe/Lighthouse yang butuh browser)

---

## CATATAN TEKNIS PENTING

### Web Push (VAPID) — sudah aktif
- Generate kunci: `php artisan webpush:vapid --write` (otomatis tulis ke `.env`).
- Di Windows, command sudah auto-deteksi `openssl.cnf`. Jika gagal, set manual:
  `set OPENSSL_CONF=C:\php\extras\ssl\openssl.cnf`.
- Web Push butuh konteks **HTTPS** (atau `localhost`) agar service worker (`public/sw.js`)
  bisa diregistrasi browser.
- Notifikasi otomatis terkirim ke browser via `NotificationService::send()` (berdampingan
  dengan FCM mobile). User mengaktifkan dari halaman **Profil → Notifikasi Browser**.

### Status dokumen lama
- `ANALISIS-DASHBOARD-GAP.md` (18 Juni) sudah **tidak akurat**: semua modul web yang
  ditandai "❌ belum" di sana kini SUDAH ada di `resources/js/Pages/` (TahunAjaran,
  Semester, Geofence, Settings, Sp, Approval, Dosen, Reports, Notifications, AuditTrail,
  Analysis, TestMode).
