# Dokumentasi Sistem

Dokumentasi ini menggunakan hierarki eksplisit agar PRD, implementasi, audit, dan catatan historis tidak saling bertentangan.

## Sumber Kebenaran

Jika terdapat perbedaan, gunakan urutan berikut:

1. **Executable truth:** migrations, routes, middleware, validation/service, Flutter runtime config, automated tests, manifest dependency, Gradle, dan CI.
2. **Dokumen current:** `CURRENT-ARCHITECTURE.md`, `CURRENT-API.md`, `SECURITY.md`, dan `DEPLOYMENT.md`.
3. **PRD:** tujuan produk dan acceptance criteria. Detail endpoint/schema pada PRD harus menunjuk ke dokumen current, bukan mengalahkan implementasi.
4. **Audit aktif:** `temuan.md` adalah backlog risiko dan bukti verifikasi terkini.
5. **Dokumen historis:** task plan, analisis lama, dan fix log hanya merekam kondisi pada tanggal pembuatannya.

## Referensi Current

| Dokumen | Fungsi |
|---|---|
| [CURRENT-ARCHITECTURE.md](CURRENT-ARCHITECTURE.md) | Komponen, trust boundary, data flow, dan platform support |
| [CURRENT-API.md](CURRENT-API.md) | Kontrak auth, attendance permit, online/offline attendance, dan private files |
| [SECURITY.md](SECURITY.md) | Kontrol keamanan, provisioning akun, biometrik, dan residual risk |
| [THREAT-MODEL-ATTENDANCE.md](THREAT-MODEL-ATTENDANCE.md) | Aktor ancaman, kontrol yang ditegakkan server, klaim client yang belum terverifikasi, dan batas klaim |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Environment, backend deployment, Android release, iOS status, rollback |
| [temuan.md](temuan.md) | Temuan aktif, status remediation, acceptance, dan evidence |
| [PRD-INDEX.md](PRD-INDEX.md) | Indeks kebutuhan produk dan status implementasi |

## Klasifikasi Dokumen

### Product Requirements

- `PRD-01`, `PRD-02`, `PRD-02B`, `PRD-05`, `PRD-06`, `PRD-07`, dan `PRD-08`: kebutuhan/desain produk; detail teknis current ada pada referensi current.
- `PRD-03` dan `PRD-04`: desain schema/API awal yang sedang dimigrasikan; migrations dan `CURRENT-API.md` mengalahkan contoh lama.
- `SOP-R05-R07.md`: rancangan penelitian, belum executable sampai fixture/script dan permit flow diperbarui.

### Audit Aktif

- `temuan.md`: satu-satunya tracker remediation aktif.
- `final-task.md`: release-readiness ringkas yang diturunkan dari `temuan.md`.

### Historis

- `ANALISIS-BUG-REPORT.md`
- `ANALISIS-DASHBOARD-GAP.md`
- `task-master.md`, `task-backend.md`, `task-frontend.md`, `task-mobile.md`, `task-baru.md`
- `FIX-LOG-001.md`, `FIX-LOG-002.md`, `FIX-LOG-003.md`, `FIX-LOG-004.md`

Dokumen historis tidak boleh digunakan untuk membuat endpoint, credential, deployment, atau keputusan release baru.

## Aturan Pemeliharaan

- Perubahan route atau payload wajib memperbarui `CURRENT-API.md` dan contract test.
- Perubahan migration/domain state wajib memperbarui `CURRENT-ARCHITECTURE.md` atau PRD terkait.
- Perubahan secret, signing, runtime, queue, scheduler, atau storage wajib memperbarui `DEPLOYMENT.md` dan `.env.example`.
- Perubahan kontrol keamanan wajib memperbarui `SECURITY.md` dan evidence pada `temuan.md`.
- Perubahan comparator/threshold/GPS baseline atau invariant wajib menjaga konsistensi lintas mobile/backend/analisis dan dicatat sebagai "canonical" di `CURRENT-ARCHITECTURE.md`.
- Status `[X]` hanya diberikan setelah acceptance dapat dibuktikan. Implementasi yang menunggu device/manual test tetap `[ ]` dengan status parsial.
- Jangan menyimpan token, password, key, `.env`, atau data biometrik nyata di dokumentasi.

## CI

- `backend-ci.yml` dan `frontend-ci.yml` berjalan pada setiap push/PR. Frontend CI memakai `flutter analyze --fatal-warnings --fatal-infos`. Detail lihat [DEPLOYMENT.md](DEPLOYMENT.md).

**Pembaruan terakhir:** 9 Agustus 2026.
