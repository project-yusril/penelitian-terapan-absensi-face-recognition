# Dokumentasi Sistem

Dokumentasi ini menggunakan hierarki eksplisit agar PRD, implementasi, audit, dan catatan historis tidak saling bertentangan.

## Sumber Kebenaran

Jika terdapat perbedaan, gunakan urutan berikut:

1. **Executable truth:** migrations, routes, middleware, validation/service, Flutter runtime config, automated tests, manifest dependency, Gradle, dan CI.
2. **Dokumen current:** `CURRENT-ARCHITECTURE.md`, `CURRENT-API.md`, `SECURITY.md`, dan `DEPLOYMENT.md`.
3. **Audit aktif:** `temuan.md` adalah satu-satunya backlog risiko dan evidence tracker authoritative.
4. **PRD:** tujuan produk dan acceptance criteria. Detail endpoint/schema pada PRD harus menunjuk ke dokumen current, bukan mengalahkan implementasi.
5. **Proposal aktif:** rencana yang belum diimplementasikan, seperti `rencana-izin.md`; bukan kontrak runtime.
6. **Dokumen historis:** task plan, analisis lama, final-task, dan fix log hanya merekam kondisi pada tanggal pembuatannya.

## Referensi Current

| Dokumen | Fungsi |
|---|---|
| [CURRENT-ARCHITECTURE.md](CURRENT-ARCHITECTURE.md) | Komponen, trust boundary, data flow, FCM, dan platform support Android-only |
| [CURRENT-API.md](CURRENT-API.md) | Kontrak API executable, termasuk production biometric containment |
| [SECURITY.md](SECURITY.md) | Kontrol keamanan, secret, provisioning, containment biometrik, dan residual risk |
| [THREAT-MODEL-ATTENDANCE.md](THREAT-MODEL-ATTENDANCE.md) | Aktor ancaman, kontrol yang ditegakkan server, klaim client yang belum terverifikasi, dan batas klaim |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Environment, CI, backend deployment, Android release/device matrix, rollback, dan restore |
| [temuan.md](temuan.md) | Temuan aktif, status remediation, acceptance, dan evidence |
| [PRD-INDEX.md](PRD-INDEX.md) | Indeks kebutuhan produk dan status implementasi |

## Klasifikasi Dokumen

### Product Requirements

- `PRD-01`, `PRD-02`, `PRD-02B`, `PRD-05`, `PRD-06`, `PRD-07`, dan `PRD-08`: kebutuhan/desain produk; detail teknis current ada pada referensi current.
- `PRD-03` dan `PRD-04`: desain schema/API awal yang sedang dimigrasikan; migrations dan `CURRENT-API.md` mengalahkan contoh lama.
- `SOP-R05-R07.md`: rancangan penelitian, belum executable sampai fixture/script dan permit flow diperbarui.

### Tracker dan Proposal

- `temuan.md`: satu-satunya tracker remediation aktif.
- `rencana-izin.md`: proposal aktif untuk shortcut izin multi-MK; belum menjadi API/runtime contract.
- `SOP-R05-R07.md`: draft SOP penelitian; belum executable sampai prasyarat di dokumen terpenuhi.

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
| Attendance/enrollment production | Fail-closed sampai trusted verifier tersedia | [SECURITY.md](SECURITY.md), [THREAT-MODEL-ATTENDANCE.md](THREAT-MODEL-ATTENDANCE.md) |
| FCM mobile | Lifecycle selesai; release default off, opt-in via secret/config | [CURRENT-ARCHITECTURE.md](CURRENT-ARCHITECTURE.md), [DEPLOYMENT.md](DEPLOYMENT.md) |
| Checkout | Action dan navigation contract selesai | [temuan.md](temuan.md#h-13-checkout-ui-tidak-memiliki-actionnavigasi-yang-dapat-dicapai) |
| Camera matrix | Harness tersedia; physical Android low/mid/high evidence belum ada | [temuan.md](temuan.md#h-16-camera-converter-belum-diverifikasi-pada-device-matrix) |
| CI/repository | Workflow/hygiene tersedia; remote green run dan enforcement belum terbukti | [temuan.md](temuan.md#l-09-hygiene-repositorydeployment-belum-memadai) |
| Dataset analisis penelitian | `prodi_id` mempersempit dataset, bukan hanya threshold; atribusi memakai prodi subjek | [PRD-07-analisis-evaluasi.md](PRD-07-analisis-evaluasi.md), [CURRENT-API.md](CURRENT-API.md) |

## Aturan Pemeliharaan

- Perubahan route atau payload wajib memperbarui `CURRENT-API.md` dan contract test.
- Perubahan migration/domain state wajib memperbarui `CURRENT-ARCHITECTURE.md` atau PRD terkait.
- Perubahan secret, signing, runtime, queue, scheduler, atau storage wajib memperbarui `DEPLOYMENT.md` dan `.env.example`.
- Perubahan kontrol keamanan wajib memperbarui `SECURITY.md` dan evidence pada `temuan.md`.
- Perubahan comparator/threshold/GPS baseline atau invariant wajib menjaga konsistensi lintas mobile/backend/analisis dan dicatat sebagai "canonical" di `CURRENT-ARCHITECTURE.md`.
- Status `[X]` hanya diberikan setelah acceptance dapat dibuktikan. Implementasi yang menunggu device/manual test tetap `[ ]` dengan status parsial.
- Jangan menyimpan token, password, key, `.env`, atau data biometrik nyata di dokumentasi.

## CI

- `backend-ci.yml` dan `frontend-ci.yml` dikonfigurasi untuk setiap push/PR. `android-release.yml` dan `android-device-tests.yml` manual. Green remote run, protected environments, dan required checks belum boleh diklaim sampai evidence L-09 tersedia. Detail lihat [DEPLOYMENT.md](DEPLOYMENT.md).

**Pembaruan terakhir:** 11 Agustus 2026.
