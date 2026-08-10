# FIX-LOG-003 — Batch Perbaikan #3 (Research-Critical & Dokumentasi)

> **ARSIP HISTORIS.** Flow reset token yang dikembalikan API dan kontrak
> pre-permit telah disupersede karena tidak aman. Gunakan
> [CURRENT-API.md](CURRENT-API.md) dan [SECURITY.md](SECURITY.md).

**Tanggal:** 16 Juni 2026
**Sumber tugas:** `docs/task-baru.md` (Bagian E — Research-Critical) + Dokumentasi (D) + Low (L)
**Status build:** `php -l` semua file backend = OK · `flutter analyze` file terdampak = *No issues found*

Batch ini menutup seluruh task yang masih bisa dikerjakan dari sisi kode. Dua task
tersisa (R-05 & R-07) sengaja **tidak ditandai selesai** karena hanya membutuhkan
**sesi pengambilan data lapangan** (uji genuine/impostor & uji beban simultan) —
infrastruktur kode + endpoint-nya sudah lengkap.

---

## 1) D-01 & D-02 — Sinkronisasi PRD-04 dengan `routes/api.php`

**File:** `docs/PRD-04-api-design.md`

- PRD-04 ditulis ulang penuh agar 1:1 dengan `backend/routes/api.php`.
- Base URL dikoreksi: prefix `/api` (tanpa `/v1`), domain dummy dibuang, ditambah
  varian emulator (`10.0.2.2`), device fisik (IP LAN), dan produksi.
- Seluruh prefix peran (`/mahasiswa`, `/kaprodi`, `/dosen`, `/kajur`,
  `/orang-tua`, `/admin`, `/notifications`) & path nyata dikoreksi.
- Tiap endpoint diberi penanda status: ✅ implemented · 🟡 path berbeda dari draf
  lama · ❌ tidak diimplementasikan.
- Tabel ringkasan "endpoint dihapus + penggantinya" ditambahkan, mis.:
  - `/attendance/summary` → `GET /mahasiswa/dashboard`
  - `/attendance/active-schedule` → `GET /mahasiswa/jadwal/active`
  - `/enrollment/pending` → `GET /kaprodi/enrollments`
  - `/auth/update-profile` → `PUT /profile`
  - `/auth/update-fcm-token` → `POST /fcm-token`
  - `/analysis/far-frr`, `/analysis/documentation` → dihitung di `face-verification` & `test-mode/summary`

---

## 2) L-01 — Forgot-password & layar notifikasi (placeholder → halaman nyata)

**File:**
- `frontend/lib/features/auth/presentation/pages/forgot_password_page.dart` (baru)
- `frontend/lib/features/notifications/presentation/pages/notifications_page.dart` (baru)
- `frontend/lib/main.dart` (wiring route)

- `ForgotPasswordPage`: alur dua langkah — `POST /auth/forgot-password` (mode
  API-only: token reset dikembalikan & diisikan otomatis) → `POST /auth/reset-password`
  dengan validasi password + konfirmasi.
- `NotificationsPage`: list `GET /notifications` (paginated), pull-to-refresh,
  tap = mark-as-read (`PUT /notifications/{id}/read`, optimistic), tombol
  mark-all (`PUT /notifications/read-all`), ikon per tipe notifikasi.
- `main.dart`: route `/forgot-password` & `/notifications` dipindah ke
  `onGenerateRoute` agar bisa inject `apiClient`; placeholder `Text('Notifikasi')`
  dihapus + import ditambahkan.

---

## 3) R-02 — Sinkronisasi threshold θ + kurva FAR/FRR (EER)

**File:** `backend/app/Http/Controllers/Api/Admin/AnalysisController.php`

- `faceVerification()` kini memakai **`ProdiSetting.face_threshold`** sebagai
  default θ (sumber kebenaran tunggal, sama dengan `AttendanceController` &
  `EnrollmentController`, fallback `1.00`). Bisa di-override `?threshold=` atau
  `?prodi_id=`.
- Helper baru:
  - `resolveProdiThreshold($prodiId)` — ambil θ dari setting prodi.
  - `computeFarFrr($genuine, $impostor, $threshold)` — FAR/FRR pada satu θ.
  - `sweepFarFrr($genuine, $impostor)` — sweep θ 0.30–1.40 (step 0.05),
    menghasilkan **kurva FAR/FRR**, titik **EER**, dan **optimal_threshold**
    (|FAR−FRR| minimal) untuk laporan penelitian.
- Respon `face-verification` menambah field: `threshold_source`, `sweep`, `eer`,
  `optimal_threshold`.
- L2-normalize embedding sudah aktif sejak M-07 → konsisten dengan jarak yang
  dievaluasi di sini.

---

## 4) R-08 — Data perbandingan konvensional (durasi efektif tidak null)

**File:** `backend/app/Http/Controllers/Api/Admin/AnalysisController.php`

- `conventionalComparison()` menghitung `avg_duration_minutes` dari kolom
  **`durasi_efektif_menit`** (diisi saat check-out) dengan `COALESCE` fallback ke
  `TIMESTAMPDIFF(MINUTE, checkin_time, checkout_time)` untuk record lama.
- Ditambah field `with_checkout` (jumlah record yang sudah check-out) agar
  pembanding jelas.
- Karena C-02 (online submit) & H-03 (status check-in/out) sudah selesai, kolom
  waktu & durasi benar-benar terisi → tabel pembanding tidak lagi null.

---

## 5) R-09 — Check-out & durasi kehadiran efektif (verifikasi)

**File:** `backend/app/Http/Controllers/Api/Mahasiswa/AttendanceController.php`

- Diverifikasi: `checkOut()` menghitung
  `durasi_efektif_menit = minutesBetween(checkin_time, actualCheckoutTime)` dan
  menyimpannya beserta `alpha_menit` (termasuk alpha pulang awal CASE B & cap
  CASE C). Jalur offline (`OfflineSyncController`) juga menghitung durasi.
- Status check-in tampil di Home (H-03) → tombol check-out muncul; submit online
  (C-02) sudah aktif. Tidak ada perubahan kode tambahan diperlukan; task ditutup.

---

## 6) R-10 — Early warning SP (peringatan dini)

**File:** `backend/app/Http/Controllers/Api/Mahasiswa/DashboardController.php`

- Basis early warning ditetapkan = **akumulasi jam alpha** (`total_alpha_jam`),
  konsisten dengan `AlphaAccumulationService`.
- Dashboard mahasiswa kini mengembalikan blok **`sp_early_warning`**:
  `current_level`, `next_level`, `next_threshold_jam`, `total_alpha_jam`,
  `progress_persen`, `is_approaching`, `warning_code`
  (via `AlphaAccumulationService::isApproachingNextLevel`, ambang ≥ 80%).
- Pemetaan ke definisi proposal (persentase kehadiran) dicatat untuk laporan.

---

## 7) L-02..L-05 — Verifikasi & rekonsiliasi checklist

Diverifikasi sudah terimplementasi di kode (checkbox di `task-baru.md`
disesuaikan):

- **L-02** — `summary_semester.pending` & objek `sp_threshold` dikirim backend;
  dibaca benar di `home_remote_datasource.dart`.
- **L-03** — `LogInterceptor` dibungkus `kDebugMode` (body/token tidak bocor di release).
- **L-04** — rotasi `InputImage` dihitung dari `_activeCamera.sensorOrientation`.
- **L-05** — enum tipe notifikasi diperluas via migrasi
  `2026_05_28_000001_add_types_to_notifications_table` (mencakup
  `attendance_reminder`, `leave_request_result`, dll.).

---

## Sisa Pekerjaan (butuh sesi data, bukan kode)

| Task | Status | Yang dibutuhkan |
|------|--------|-----------------|
| R-05 | Infra siap | Jalankan test mode untuk merekam percobaan **genuine vs impostor** (label di `attendance_logs.metadata`), lalu endpoint `face-verification` + `sweepFarFrr` menghasilkan FAR/FRR & EER. |
| R-07 | Infra siap | Jalankan **uji beban simultan** (20/30/40 pengguna), tulis `metadata->concurrent_level/success/latency_ms`; endpoint `simultaneous-test` menampilkan hasil. |

## Ringkasan Progres

- **CRITICAL:** 5/5 · **HIGH:** 6/6 · **MEDIUM:** 7/7 · **LOW & DOCS:** 7/7
- **RESEARCH-CRITICAL:** 8/10 selesai (+2 infra siap, tinggal sesi data)
- **TOTAL:** 33/35 (2 sisanya non-koding)
