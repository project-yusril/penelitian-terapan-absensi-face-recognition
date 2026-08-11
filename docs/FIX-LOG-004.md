# FIX-LOG-004 — Remediasi 26 Juli 2026

> **ARSIP HISTORIS.** Status pada dokumen ini berlaku untuk batch 26 Juli 2026.
> Status current dan acceptance authoritative ada di [temuan.md](temuan.md);
> beberapa item pada bagian "Batas" telah selesai atau dikontain setelah tanggal ini.

Dokumen ini memetakan setiap temuan yang dikerjakan pada tanggal ini ke file yang diubah dan regression test-nya, agar perubahan yang landing sebagai satu batch besar tetap dapat direview per temuan. Backlog dan acceptance authoritative tetap di [temuan.md](temuan.md).

## Cara Review Per Temuan

Review satu baris tabel pada satu waktu. Untuk tiap temuan, baca file implementasi lalu jalankan test yang tercantum:

```powershell
cd backend
php artisan test --filter=<NamaTest>
```

## Peta Temuan → File → Test

| Temuan | Ringkasan | File utama | Test |
|---|---|---|---|
| L-04 | Bentrok jadwal pakai interval setengah terbuka; update parsial tidak boleh membalik rentang waktu | `app/Http/Controllers/Api/Admin/JadwalController.php` | `JadwalConflictRegressionTest` |
| L-05 | Analyzer Flutter bersih; `camera_platform_interface` jadi dev_dependency terpin | `frontend/pubspec.yaml` | `flutter analyze` |
| L-03 | Auto-close verifikasi memakai setting prodi (bukan perubahan kode) | `app/Console/Commands/AutoCloseAttendance.php` | suite penuh |
| M-18 | Clamp `per_page` dan allowlist sort global; tahan input array/non-numerik | `app/Traits/ResolvesListQuery.php`, `app/Http/Controllers/Controller.php`, seluruh controller list API/Web | `ListQueryHardeningTest` |
| M-21 | Throttle login/TOTP; change-password mencabut sesi lain | `routes/web.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/Web/ProfileController.php` | `WebAuthHardeningTest`, `RateLimitingTest` |
| M-20 | CHECK constraint, unique kelas NULL, composite index; `MataKuliah` buang generated column | `database/migrations/2026_07_26_000001_add_domain_invariant_constraints.php`, `app/Models/MataKuliah.php` | `DomainInvariantConstraintTest` |
| M-17 | Aggregate query menggantikan N+1 dan full-table `get()` | `app/Exports/AttendanceExport.php`, `app/Http/Controllers/Web/AnalysisController.php` | `ExportQueryCountTest`, `AttendanceExportRegressionTest` |
| M-16 | Export menghormati filter layar termasuk filter mahasiswa | `app/Http/Controllers/Web/ReportController.php`, `app/Exports/AttendanceExport.php` | `ReportExportParityTest`, `AttendanceExportRegressionTest` |
| M-22 | Redaction pesan exception dengan correlation ID | `app/Support/SafeErrorMessage.php`, import user API/Web | `SafeErrorMessageTest` |
| L-01 | Pin dependency Flutter ke caret range dari lockfile | `frontend/pubspec.yaml` | `flutter analyze`, `flutter test` |
| L-07 | Aksesibilitas modal, tabel, pagination, icon button, chart | `resources/js/Components/Modal.vue`, `DataTable.vue`, `FlashToast.vue`, `Layouts/AppLayout.vue`, `Pages/*/Index.vue`, `Pages/Dashboard.vue` | `npm run build` |
| L-08 | Role canonical 8 role | `docs/CURRENT-ARCHITECTURE.md` | — |
| C-04 | Threat model attendance (dokumentasi; C-04 tetap terbuka) | `docs/THREAT-MODEL-ATTENDANCE.md` | — |

## Perbaikan dari Review Internal

Ditemukan saat review lima-axis terhadap perubahan di atas:

1. `MataKuliah` semula meng-override `performInsert`/`performUpdate` dan melewatkan `saveOrIgnore`; diganti hook `saving` yang menutup semua jalur persist.
2. `JadwalController::update` tidak memvalidasi urutan waktu pada update parsial; ditambahkan validasi 422 agar tidak jatuh ke pelanggaran CHECK constraint.
3. `resolvePerPage`/`resolveSort` rapuh terhadap input array/non-numerik; ditambahkan guard `is_scalar`/`is_numeric`.
4. `SafeErrorMessage` semula membuang seluruh info `QueryException`; sekarang mencatat SQLSTATE dan file/line untuk diagnosis tanpa membocorkan data.
5. Login limiter semula keyed per IP saja (risiko mengunci NAT kampus); diganti keying gabungan IP+identitas plus batas IP yang lebih longgar.

## Verifikasi Batch

| Pemeriksaan | Hasil |
|---|---|
| `php artisan test` | 171 test, 624 assertion lulus |
| `flutter test` | 152 test lulus |
| `flutter analyze` | Bersih |
| `npm run build` | Lulus |
| `php artisan migrate:fresh` | Lulus di MySQL 8.0.30 |

## Batas

Pada 26 Juli 2026, C-04, H-04, H-13, H-16, H-17, M-21, L-02, L-06, L-09, dan R-01–R-05 masih terbuka. Daftar ini adalah snapshot historis, bukan status current. Lihat [temuan.md](temuan.md) untuk status masing-masing.
