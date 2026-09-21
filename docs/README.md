# Dokumentasi Sistem

Dokumentasi ini menggunakan hierarki eksplisit agar PRD, implementasi, audit, dan catatan historis tidak saling bertentangan.

## Sumber Kebenaran

Jika terdapat perbedaan, gunakan urutan berikut:

1. **Executable truth:** migrations, routes, middleware, validation/service, Flutter runtime config, automated tests, manifest dependency, Gradle, dan CI.
2. **Dokumen current:** `CURRENT-ARCHITECTURE.md`, `CURRENT-API.md`, `SECURITY.md`, dan `DEPLOYMENT.md`.
3. **Audit aktif:** `temuan.md` adalah satu-satunya backlog risiko dan evidence tracker authoritative.
4. **PRD:** tujuan produk dan acceptance criteria. Detail endpoint/schema pada PRD harus menunjuk ke dokumen current, bukan mengalahkan implementasi.
5. **Catatan implementasi:** rencana yang sudah selesai, seperti `rencana-izin.md` dan `rencana2.md`; bukan pengganti kontrak current.
6. **Dokumen historis:** task plan, analisis lama, final-task, dan fix log hanya merekam kondisi pada tanggal pembuatannya.

> **RENCANA 2 (Kelas Master, 20 Agustus 2026):** perubahan skema akademik (kelas master, `mahasiswa_kelas`, dosen/kelas di jadwal, penghapusan pivot `mahasiswa_mata_kuliah`) terdokumentasi end-to-end di `rencana2.md` (jalur implementasi + verifikasi) dan merupakan kontrak current di `CURRENT-ARCHITECTURE.md` → "Struktur Data Akademik (Kelas Master)", `CURRENT-API.md` → "Perubahan Kontrak RENCANA 2", `PRD-02` §5.3a, `PRD-03` §2.9a/2.9b/2.12, dan `PRD-04` §4. Ikuti README hierarki ini: executable truth > current > PRD > catatan implementasi.

## Referensi Current

| Dokumen | Fungsi |
|---|---|
| [CURRENT-ARCHITECTURE.md](CURRENT-ARCHITECTURE.md) | Komponen, trust boundary, data flow, FCM, dan platform support Android-only |
| [CURRENT-API.md](CURRENT-API.md) | Kontrak API executable, termasuk production biometric containment |
| [SECURITY.md](SECURITY.md) | Kontrol keamanan, secret, provisioning, containment biometrik, dan residual risk |
| [ROLE-PERMISSION-MATRIX.md](ROLE-PERMISSION-MATRIX.md) | Matriks role/permission/prodi canonical, tiga lapis enforcement, dan checklist audit negative test (MS-01) |
| [THREAT-MODEL-ATTENDANCE.md](THREAT-MODEL-ATTENDANCE.md) | Aktor ancaman, kontrol yang ditegakkan server, klaim client yang belum terverifikasi, dan batas klaim |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Environment, CI, backend deployment, Android release/device matrix, rollback, dan restore |
| [`eksperimen/` (root repo)](../eksperimen/PROTOKOL_EKSPERIMEN.md) | Protokol & hasil eksperimen penelitian: FAR asli (1.485 pasangan impostor, θ=0.600 → 0%), benchmark beban 20/30/40 (p95 < 2 s), protokol pengumpulan data genuine untuk FRR + uji anti-spoofing terbatas |
| [`ssh.md` (root repo)](../ssh.md) | Kredensial & runbook akses SSH server production (IP/port/user/key, pola deploy, backup/restore) — **rahasia, di-gitignore, hanya lokal** |
| [temuan.md](temuan.md) | Temuan aktif, status remediation, acceptance, dan evidence |
| [PRD-INDEX.md](PRD-INDEX.md) | Indeks kebutuhan produk dan status implementasi |
| rencana2.md | Catatan implementasi & verifikasi RENCANA 2 (kelas master) — selesai |

## Klasifikasi Dokumen

### Product Requirements

- `PRD-01`, `PRD-02`, `PRD-02B`, `PRD-05`, `PRD-06`, `PRD-07`, dan `PRD-08`: kebutuhan/desain produk; detail teknis current ada pada referensi current.
- `PRD-03` dan `PRD-04`: desain schema/API awal yang sedang dimigrasikan; migrations dan `CURRENT-API.md` mengalahkan contoh lama.
- `SOP-R05-R07.md`: rancangan penelitian, belum executable sampai fixture/script dan permit flow diperbarui.

### Tracker dan Proposal

- `temuan.md`: satu-satunya tracker remediation aktif.
- `rencana-izin.md`: catatan implementasi shortcut izin multi-MK yang sudah selesai; kontrak authoritative ada di `CURRENT-API.md`.
- `SOP-R05-R07.md`: draft SOP penelitian; belum executable sampai prasyarat di dokumen terpenuhi.

### Architecture Decision Records (ADR)

- `ADR-001-trusted-biometric-verifier.md`: **DITOLAK / tidak dilanjutkan.** Rancangan trusted biometric verifier server-side (C-04/H-04). Verifier **di luar scope penelitian**; residual risk diterima. Revisi 21 September 2026: alur client-attested di production diaktifkan via `BIOMETRIC_ALLOW_CLIENT_CLAIMS` (keputusan pemilik proyek) — bukan penghidupan verifier. Disimpan sebagai catatan keputusan bila proyek dinaikkan ke tingkat produksi. Bukan rencana aktif.

### Historis

- `ANALISIS-BUG-REPORT.md`
- `ANALISIS-DASHBOARD-GAP.md`
- `final-task.md`
- `task-master.md`, `task-backend.md`, `task-frontend.md`, `task-mobile.md`, `task-baru.md`
- `FIX-LOG-001.md`, `FIX-LOG-002.md`, `FIX-LOG-003.md`, `FIX-LOG-004.md`

Dokumen historis tidak boleh digunakan untuk membuat endpoint, credential, deployment, atau keputusan release baru.

## Status Release Terpadu

| Area | Status current | Authority |
|---|---|---|
| Platform mobile | Android-only; iOS tidak didukung | [CURRENT-ARCHITECTURE.md](CURRENT-ARCHITECTURE.md), [DEPLOYMENT.md](DEPLOYMENT.md) |
| Attendance/enrollment production | Fail-closed. Trusted verifier (C-04/H-04) **di luar scope penelitian** — [ADR-001](ADR-001-trusted-biometric-verifier.md) ditolak; residual risk diterima | [SECURITY.md](SECURITY.md), [THREAT-MODEL-ATTENDANCE.md](THREAT-MODEL-ATTENDANCE.md), [ADR-001](ADR-001-trusted-biometric-verifier.md) |
| FCM mobile | Lifecycle selesai; release default off, opt-in via secret/config | [CURRENT-ARCHITECTURE.md](CURRENT-ARCHITECTURE.md), [DEPLOYMENT.md](DEPLOYMENT.md) |
| Checkout | Action dan navigation contract selesai | [temuan.md](temuan.md#h-13-checkout-ui-tidak-memiliki-actionnavigasi-yang-dapat-dicapai) |
| Camera matrix | Harness tersedia; physical Android low/mid/high evidence belum ada | [temuan.md](temuan.md#h-16-camera-converter-belum-diverifikasi-pada-device-matrix) |
| CI/repository | Workflow/hygiene tersedia; remote green run dan enforcement belum terbukti | [temuan.md](temuan.md#l-09-hygiene-repositorydeployment-belum-memadai) |
| Benchmark beban (R-02) | **Selesai 21 Sep 2026** — p95 < 2 s pada level 20/30/40 (server-side, WAF blokir k6 eksternal); failure = 429 limiter by design | [temuan.md](temuan.md), [eksperimen/](../eksperimen/PROTOKOL_EKSPERIMEN.md) |
| FAR biometrik (R-03) | **FAR selesai 21 Sep 2026** — 1.485 pasangan impostor asli, FAR @ θ=0.600 = 0%; FRR menunggu data genuine lapangan; data seeder 400+400 dinyatakan sintetis (tidak valid untuk laporan) | [temuan.md](temuan.md), [eksperimen/](../eksperimen/PROTOKOL_EKSPERIMEN.md) |
| Gate frame netral (N-01) | **Selesai 21 Sep 2026** — verifikasi absensi mobile memakai frame netral pasca-liveness (bukan frame ekspresi challenge); ganti wajah membatalkan fase tunggu (binding anti-spoofing); 7 test kontrak gate | [temuan.md](temuan.md#n-01-verifikasi-wajah-dijalankan-pada-frame-ekspresi-challenge--frr-lapangan-menumpuk), [CURRENT-ARCHITECTURE.md](CURRENT-ARCHITECTURE.md), [THREAT-MODEL-ATTENDANCE.md](THREAT-MODEL-ATTENDANCE.md) |
| Dataset analisis penelitian | `prodi_id` mempersempit dataset, bukan hanya threshold; atribusi memakai prodi subjek | [PRD-07-analisis-evaluasi.md](PRD-07-analisis-evaluasi.md), [CURRENT-API.md](CURRENT-API.md) |
| Authorization | Tiga lapis: guard role, object policy, query scope. Role tingkat prodi fail-closed ke `prodi_id` aktor | [ROLE-PERMISSION-MATRIX.md](ROLE-PERMISSION-MATRIX.md) |

## Aturan Pemeliharaan

- Perubahan route atau payload wajib memperbarui `CURRENT-API.md` dan contract test.
- Perubahan route, guard role, atau aturan scope wajib memperbarui `ROLE-PERMISSION-MATRIX.md`. Regenerasi datanya dengan `php artisan route:list --json`, jangan menyuntingnya dari ingatan. Setiap route baru wajib menyebut lapis mana yang menjaganya (guard role, object policy, atau query scope) — M-24 lolos justru karena permukaan API lebih luas daripada permukaan web.
- Perubahan limiter wajib memperbarui `PRD-08-non-functional.md`, `SECURITY.md`, dan tabel troubleshooting `SOP-R05-R07.md`, karena rencana load test R-07 bergantung pada angka tersebut.
- Perubahan migration/domain state wajib memperbarui `CURRENT-ARCHITECTURE.md` atau PRD terkait.
- Perubahan secret, signing, runtime, queue, scheduler, atau storage wajib memperbarui `DEPLOYMENT.md` dan `.env.example`.
- Perubahan kredensial akses server (SSH key, port, user, alamat host) wajib memperbarui `ssh.md` di root repo; `ssh.md` tidak pernah masuk Git.
- Perubahan command dev server/scheduler (mis. `serve:all`) wajib memperbarui `backend/README.md`, `README.md` root, dan `DEPLOYMENT.md` agar alur menjalankan backend tetap satu sumber.
- Perubahan kontrol keamanan wajib memperbarui `SECURITY.md` dan evidence pada `temuan.md`.
- Perubahan comparator/threshold/GPS baseline atau invariant wajib menjaga konsistensi lintas mobile/backend/analisis dan dicatat sebagai "canonical" di `CURRENT-ARCHITECTURE.md`.
- Status `[X]` hanya diberikan setelah acceptance dapat dibuktikan. Implementasi yang menunggu device/manual test tetap `[ ]` dengan status parsial.
- Jangan menyimpan token, password, key, `.env`, atau data biometrik nyata di dokumentasi.

## CI

- `backend-ci.yml` dan `frontend-ci.yml` dikonfigurasi untuk setiap push/PR. `android-release.yml` dan `android-device-tests.yml` manual. Detail lihat [DEPLOYMENT.md](DEPLOYMENT.md).
- Seluruh pekerjaan lokal sudah di-push ke `origin/main`; push terakhir 12 September 2026 (`840082b`), sehingga workflow push/PR terpicu pada revision tersebut. **Push hanya memicu workflow, bukan membuktikan hasilnya.** Green remote run, protected environments, dan required checks tetap belum boleh diklaim sampai evidence L-09 tersedia — GitHub CLI tidak tersedia di workspace ini.

**Pembaruan terakhir:** 21 September 2026 (fix N-01 di mobile: verifikasi wajah absensi kini menunggu frame netral pasca-liveness dengan binding wajah—challenge; APK debug 4 varian di-rebuild memuat fix ini; detail di [temuan.md N-01](temuan.md#n-01-verifikasi-wajah-dijalankan-pada-frame-ekspresi-challenge--frr-lapangan-menumpuk). Sebelumnya 14 September 2026: deploy backend live via SSH: `face_threshold` 0.600 semua prodi, 11 file backend + build Vite baru, perbaikan EXIF embedding — kredensial & pola deploy di [`ssh.md`](../ssh.md), riwayat di [DEPLOYMENT.md](DEPLOYMENT.md#riwayat-deploy-via-ssh). Analisis 19 face embeddings terverifikasi sehat: 14 pending 14 September tidak perlu re-enrollment. Backend penelitian live di `https://absensi.yusrilekamahendra.com` — health 200, dashboard login aktif, mobile sudah diuji konek dari perangkat fisik; detail di [DEPLOYMENT.md](DEPLOYMENT.md#backend-production-live). Verifikasi tooling terakhir lihat [temuan.md](temuan.md#hasil-verifikasi-tooling): `php artisan test` 235/235, `flutter test` 192, `flutter analyze` bersih, `npm run build` lulus).
