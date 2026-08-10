# FIX LOG #002 — Lanjutan perbaikan dari `ANALISIS-BUG-REPORT.md`

> **ARSIP HISTORIS.** Status dan klaim readiness adalah snapshot pada tanggal
> log. Audit aktif dan acceptance current berada di [temuan.md](temuan.md).

**Tanggal:** 16 Juni 2026
**Cakupan:** Batch kedua — menutup sisa CRITICAL/HIGH/MEDIUM + sebagian LOW.
**Rujukan:** Setiap item mengacu pada kode temuan di `docs/ANALISIS-BUG-REPORT.md`
dan rencana kerja di `docs/task-baru.md`.

> Catatan: ini **catatan eksekusi**. Untuk konteks "kenapa bug ini penting",
> baca `ANALISIS-BUG-REPORT.md` pada kode temuan terkait.

---

## 1. FRONTEND

### [C-02] Absensi ONLINE tidak pernah dikirim ke server
- **File:** `frontend/lib/features/attendance/presentation/pages/attendance_page.dart`
  - Sebelumnya `_submitAttendance()` hanya `Navigator.pop(...)` pada jalur online —
    **tidak ada** pemanggilan API sama sekali, sehingga check-in/out online tidak
    pernah tersimpan ke tabel `attendances`.
  - Sekarang: saat **online** halaman benar-benar `POST` ke
    `ApiConstants.checkInEndpoint` / `checkOutEndpoint` via `ApiClient`,
    lalu mengembalikan `response.data['data']` ke pemanggil.
  - `ApiClient` diambil **sebelum** `await` (pre-async-gap) supaya tidak melanggar
    lint `use_build_context_synchronously`.
  - Error API ditangani: status di-update + `SnackBar` + stream wajah di-restart.

### [M-07] Embedding belum di-L2-normalize sebelum hitung jarak
- **File:** `frontend/lib/features/face_recognition/domain/services/face_recognition_service.dart`
  - Embedding output model di-**L2-normalize** sebelum euclidean distance,
    sehingga rentang jarak menjadi wajar (~0–2) dan konsisten dengan threshold
    per-prodi (lihat R-02).

### [H-02] Mismatch field geofence `radius` vs `radius_meter`
- **File:** `frontend/lib/features/home/data/datasources/home_remote_datasource.dart`
  - Frontend kini membaca `geofence['radius']` (backend) dengan fallback
    `radius_meter` lama → radius custom benar-benar terpakai (bukan selalu 50m).

### [L-02] Field `pending` & `sp_threshold` di dashboard
- **File (frontend):** `home_remote_datasource.dart`
  - `sp_threshold` di-parse sebagai **objek** `{sp1, sp2, sp3, do}` (dalam JAM),
    progress bar SP memakai ambang `sp1`. `pending` dibaca dari summary.
- **File (backend):** `DashboardController.php`
  - Response menyertakan `sp_threshold` (per-prodi via `AlphaAccumulationService::getSpThresholds`)
    & `summary_semester.pending`.

### [L-03] Log body sensitif aktif di release
- **File:** `frontend/lib/core/network/api_client.dart`
  - `requestBody`/`responseBody` interceptor hanya aktif saat `kDebugMode`
    → embedding & token tidak tercetak pada build release.

### [L-04] Rotasi `InputImage` hardcoded 270°
- **File:** `attendance_page.dart`
  - Rotasi dihitung dari `camera.sensorOrientation`
    (`InputImageRotationValue.fromRawValue`), fallback `rotation270deg`.

---

## 2. BACKEND

### [H-01] Alpha "pulang awal" pada status `hadir` tidak terhitung ke SP
- **File:** `backend/app/Services/AlphaAccumulationService.php`
  - `recalculate()` tidak lagi memfilter `whereIn('status', [...])`; semua
    `alpha_menit` dijumlahkan (record `hadir` murni tetap `0`).
  - Akibatnya alpha "pulang awal" pada mahasiswa berstatus `hadir` ikut akumulasi.

### [H-06] Duplikasi pemanggilan `evaluateSpLevel`
- **File:** `AlphaAccumulationService.php`
  - Baris `evaluateSpLevel(...)` yang ganda dihapus → tinggal satu pemanggilan.

### [H-03] Home tidak tahu status sudah check-in
- **File:** `backend/app/Http/Controllers/Api/Mahasiswa/JadwalController.php`
  - `today()` kini menempelkan `attendance_id`, `attendance_status`,
    `checkin_time`, `checkout_time` per jadwal (via map terhadap `attMap`
    `Attendance` user hari ini).
- **File:** `frontend/.../home_remote_datasource.dart`
  - `_parseJadwal` membaca field tersebut → kartu jadwal tahu status check-in
    & tombol check-out bisa muncul (prasyarat R-09).

### [M-03] Nama device hilang saat refresh token
- **File:** `AuthController.php`
  - Saat refresh, `createToken($deviceName)` memakai nama device asli
    (`mobile-<device_name>`), bukan label generik.

### [M-04] Single-device policy untuk admin
- **File:** `AuthController.php`
  - Penghapusan token dibatasi pada token mobile saja → login mobile tidak
    memutus sesi panel web admin.

### [M-05] Guard null semester aktif
- **File:** `DashboardController.php`
  - Jika tidak ada `Semester` berstatus `aktif`, response mengembalikan struktur
    aman (`summary_semester` nol, `alpha_accumulation: null`, `sp_threshold: null`)
    + `warning`, alih-alih query dengan `semester_id = null`.

### [L-05] Enum tipe notifikasi
- **File:** migrasi `notifications` + `SpDetectionService`
  - Diverifikasi semua tipe yang dipakai (`sp_warning`, `sp_issued`,
    `approval_needed`, `enrollment_*`, dll.) sudah ada di enum → insert tidak
    gagal di MySQL strict mode.

### Race-condition guard double check-in (terkait H-05/uji simultan)
- **File:** `AttendanceController@checkIn`
  - Pengecekan "belum check-in" (step 4) **tidak atomik**; pada beban tinggi
    dua request bisa lolos bersamaan. Unique constraint `unique_attendance`
    `(user_id, jadwal_id, tanggal)` **sudah ada** sejak migrasi awal
    `2024_01_01_000014_create_attendances_table.php`.
  - Ditambahkan `try/catch (QueryException)`: pelanggaran unique (SQLSTATE `23000`)
    dikembalikan sebagai **422 idempotent**, bukan **500**.
  - **Tidak ada migrasi baru** untuk ini (constraint sudah ada). Sempat dibuat
    migrasi duplikat lalu dihapus karena akan menyebabkan `migrate` gagal.

---

## 3. RESEARCH-CRITICAL — STATUS INFRA

| Kode | Status | Catatan |
|------|--------|---------|
| R-05 (FAR/FRR genuine/impostor) | **Infra siap** | `TestModeController` + `AnalysisController::faceVerification` + route sudah lengkap. Perlu **sesi pengambilan data** uji genuine/impostor untuk mengisi angka. |
| R-07 (uji simultan `concurrent_level`) | **Infra siap** | `AnalysisController::simultaneousTest` + pencatatan metadata siap. Perlu menjalankan beban 20/30/40 user (k6/JMeter). |
| R-08 (perbandingan konvensional) | Menunggu data | Tergantung C-02 (sudah) + akumulasi `checkout_time` dari pemakaian nyata. |
| R-09 (durasi efektif check-out) | Terbuka di UI | Backend siap + H-03 & C-02 sudah; tinggal pemicu check-out di Home + uji end-to-end. |
| R-02 / R-10 | Terbuka | Sinkronisasi θ lintas komponen & definisi early-warning SP — perlu keputusan + sweep θ (butuh data R-05). |

---

## 4. YANG MASIH TERBUKA (BATCH BERIKUTNYA)

| Kode | Ringkasan |
|------|-----------|
| D-01 | Sinkronkan PRD-04 dengan `routes/api.php` (base URL, path enrollment/jadwal/leave/sp, dll.) |
| D-02 | Tinjau endpoint terdokumentasi tapi belum ada (`/attendance/summary`, `/analysis/*`, `/enrollment/pending`) |
| L-01 | Implementasi UI forgot-password & halaman notifikasi (hubungkan ke endpoint yang sudah ada) |
| R-02 | θ sebagai single source of truth + sweep nilai optimal |
| R-08 | Lengkapi data pembanding konvensional |
| R-09 | Picu check-out dari Home + uji durasi efektif |
| R-10 | Selaraskan definisi early-warning SP (persentase vs akumulasi jam) |

---

## 5. CHECKLIST DEPLOY BATCH #2

```bash
# Backend — TIDAK ADA migrasi baru di batch ini.
cd backend
php artisan config:clear
php -l app/Http/Controllers/Api/Mahasiswa/AttendanceController.php   # sanity

# Frontend
cd ../frontend
flutter pub get
flutter analyze   # harus "No issues found"
flutter build apk --release
```

### Smoke test wajib
1. **Check-in online** (WiFi nyala) → response 201 & baris baru di `attendances`
   (sebelumnya tidak pernah tersimpan).
2. **Double check-in cepat** (dua tap beruntun) → request kedua dapat 422
   ("sudah check-in"), bukan 500.
3. **Geofence radius custom** (set ≠ 50m di geofence) → batas jarak mengikuti
   nilai backend, bukan 50m statis.
4. **Build release** → cek log: body request/response (embedding/token) tidak
   tercetak.
5. **Dashboard tanpa semester aktif** → tidak error, muncul `warning`.
6. **Home jadwal** → kartu menampilkan status "Sudah check-in" bila ada record
   hari ini.

---

## 6. CATATAN AKHIR

- Verifikasi statis lulus: `php -l` (semua file PHP yang disentuh) &
  `flutter analyze` (4 file Dart yang disentuh) → **No issues found**.
- Tidak ada migrasi baru pada batch ini; constraint anti-duplikat sudah tersedia
  dari skema awal — perubahan hanya pada penanganan error (graceful 422).
- Semua perubahan diberi komentar kode temuan (`// C-02:`, `// H-02:`, dst.)
  agar mudah ditelusuri saat review.
