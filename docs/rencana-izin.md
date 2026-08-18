# Catatan Implementasi Izin: Shortcut Multi-MK (Izin/Sakit Sehari)

> Dokumen rencana kerja. Status `[X]` hanya diberikan setelah task selesai dan
> dapat dibuktikan (test lulus / verifikasi manual). Task yang menunggu device
> atau manual test tetap `[ ]` dengan catatan status parsial.
> Implementasi selesai pada 18 Agustus 2026. Dokumen ini merekam keputusan dan
> langkah kerja, bukan kontrak API/runtime. Kontrak authoritative tersedia di
> [CURRENT-API.md](CURRENT-API.md); arsitektur, test, dan [temuan.md](temuan.md)
> sudah disinkronkan.

**Dibuat:** 11 Agustus 2026.

## Keputusan Desain

**Model izin dipertahankan per-mata-kuliah (tidak diubah ke per-hari).**

Alasan:

- Alpha, SP, dan rekap semua dihitung **per mata kuliah** (`AlphaAccumulationService`,
  `SpDetectionService::evaluate($userId, $semester_id)` dipanggil per MK). Izin per-hari
  murni tetap harus dipecah menjadi per-MK di level `attendances`, jadi per-hari hanya
  menjadi lapisan UI, bukan model data.
- Izin selektif (hanya 1 MK, mis. lomba/urusan MK tertentu) hanya bisa ditangani model
  per-MK. Per-hari murni tidak bisa.
- Mengubah model ke per-hari menuntut migrasi skema, penulisan ulang `materializeRange`,
  perubahan dedup key, dan FK `leave_requests(user_id, mata_kuliah_id)` (`ON DELETE RESTRICT`).
  Risiko tinggi tanpa keuntungan model.

**Satu-satunya kelemahan per-MK** adalah UX: sakit sehari memaksa submit N izin untuk N MK.
Solusinya di lapisan UI + fan-out API, **bukan** perubahan model:

- Backend `store` menerima mode "semua MK pada rentang tanggal" lalu membuat beberapa
  `LeaveRequest` (satu per MK enrolled yang punya jadwal di rentang) dalam satu transaksi.
- Frontend `LeavePage` menambah toggle "Berlaku untuk semua MK".
- Setiap baris tetap per-MK, sehingga alpha/SP/rekap tetap konsisten.

## Kondisi Saat Ini (baseline)

| Komponen | Lokasi | Status |
|---|---|---|
| API submit izin (per-MK) | `Api/Mahasiswa/LeaveRequestController@store` | ada |
| API list izin saya | `Api/Mahasiswa/LeaveRequestController@index` | ada |
| Approve/reject (Kaprodi) | `Api/Kaprodi/LeaveRequestController` | ada |
| Materialisasi ke attendance | `LeaveApprovalService::materializeRange` | ada, per-MK |
| Skip Alpha jika izin approved | `MarkAbsentAttendance` (baris 58-68) | ada |
| Frontend feature izin | `frontend/lib/features/leave_request/` | ada (bloc, datasource, page) |
| Route `POST /mahasiswa/leave-requests` | `routes/api.php:249` | ada |

## Task List

### A. Backend — Fan-out multi-MK di `store`

- [X] A1. Tambah parameter opsional pada validasi `store`: `all_mata_kuliah` (bool) ATAU
      `mata_kuliah_ids` (array of exists). Saat mode multi aktif, `mata_kuliah_id` tunggal
      menjadi opsional. (`LeaveRequestController@store`, validasi + `wantsMultiCourse`.)
- [X] A2. Resolusi daftar MK target: ambil MK yang mahasiswa **enrolled** DAN punya
      `Jadwal` aktif di dalam rentang `tanggal_mulai..tanggal_selesai` (hindari membuat
      izin untuk MK tanpa pertemuan pada rentang itu). (`courseIdsWithScheduleInRange` +
      `dayNamesInRange`.)
- [X] A3. Bungkus pembuatan banyak `LeaveRequest` dalam **satu** `DB::transaction`;
      lakukan cek duplikat per-MK (aturan `pending`/`approved`) dan lewati MK yang
      sudah punya izin dengan rentang **beririsan**. (`courseIdsWithActiveLeave`
      memakai predikat overlap `tanggal_mulai ≤ selesai AND tanggal_selesai ≥ mulai`
      + cek ulang di dalam `lockForUpdate`.)
- [X] A4. Tangani upload `file_surat` sekali lalu pakai path yang sama untuk semua baris,
      dengan rollback file jika transaksi gagal (pola `try/catch` seperti sekarang).
- [X] A5. Bentuk response konsisten: kembalikan daftar `LeaveRequest` yang dibuat +
      ringkasan MK yang dilewati (`created_count`, `leave_requests[]`, `skipped[]`), pakai
      helper `created`. Cabang 422 "nihil dibuat" — baik saat target kosong maupun saat
      semua tersaring cek-ulang dalam lock — sama-sama membawa `errors.skipped` di mode multi.
- [X] A6. Pertahankan jalur single-MK lama (backward compatible): response tetap satu objek
      dan dibuktikan `test_single_course_submission_keeps_the_legacy_contract`.

### B. Backend — Test

- [X] B1. Feature test: submit multi-MK membuat N `LeaveRequest` (N = MK enrolled dengan
      jadwal pada rentang), status `pending`. (`test_multi_course_submission_creates_one_pending_leave_per_scheduled_course`.)
- [X] B2. Feature test: MK tanpa jadwal pada rentang **tidak** dibuatkan izin.
      (`test_course_without_schedule_in_range_is_skipped`.)
- [X] B3. Feature test: duplikat per-MK dilewati, sisanya tetap dibuat; tidak ada partial
      commit yang korup (transaksi utuh). (`test_duplicate_course_is_skipped_while_the_rest_is_still_created`,
      `test_all_courses_skipped_creates_nothing_and_returns_422`,
      `test_failed_transaction_rolls_back_...`.) Overlap multi-hari untuk MK yang sama
      ditolak sebagai duplikat: `test_overlapping_multi_day_leave_for_same_course_is_treated_as_duplicate`.
- [X] B4. Feature test: approve salah satu izin multi → `materializeRange` hanya menandai
      attendance MK tersebut; alpha MK lain tak terpengaruh.
      (`test_approving_one_multi_leave_only_touches_its_own_course`,
      `test_mark_absent_skips_alpha_for_courses_with_approved_multi_leave`.)
- [X] B5. Jalankan `composer test` dan pastikan lulus. Bukti terbaru 18 Agustus 2026:
      `php artisan test` → 224/224 (819 assertions), termasuk 14 test
      `LeaveRequestMultiCourseTest` dan regresi enrollment historis/nonaktif/periode.

### C. Frontend — Toggle multi-MK di `LeavePage`

- [X] C1. Tambah toggle "Berlaku untuk semua MK" pada form pengajuan (`leave_page.dart`,
      `SwitchListTile.adaptive`).
- [X] C2. Saat toggle aktif, sembunyikan/menonaktifkan dropdown MK tunggal dan kirim
      payload mode multi (`all_mata_kuliah`/`mata_kuliah_ids`) via datasource + bloc.
      (`if (!_semuaMataKuliah) ... _buildMataKuliahField()`; datasource memilih field payload.)
- [X] C3. Perbarui `leave_remote_datasource.dart`, `leave_repository`, `leave_bloc`,
      `leave_event`, `leave_state` untuk mendukung payload multi-MK. (Entity/model
      `LeaveSubmissionResult` + `SkippedCourse`; datasource pakai `ListFormat.multiCompatible`.)
- [X] C4. Tampilkan hasil: jumlah izin terbentuk + daftar MK yang dilewati (jika ada).
      (`_submissionSummary` pada `LeavePage`.)
- [X] C5. Jalankan `flutter analyze --fatal-warnings --fatal-infos` (No issues found) dan
      `flutter test` (All tests passed, termasuk `leave_multi_course_contract_test.dart`).

### D. Dokumentasi (integrasikan setelah kode selesai)

- [X] D1. `CURRENT-API.md`: dokumentasikan payload baru `POST /mahasiswa/leave-requests`
      (mode single vs multi-MK) dan bentuk response.
- [X] D2. `PRD-02-functional-requirements.md` (FR-IZIN-001): tambah opsi izin semua MK.
- [X] D3. `PRD-05-flow-diagram.md` (FLOW IZIN/SAKIT): cantumkan cabang multi-MK.
- [X] D4. `PRD-04-api-design.md`: sinkronkan tabel endpoint leave-request (catatan mode
      single vs multi-MK ditambahkan).
- [X] D5. `docs/README.md`: perbarui tanggal "Pembaruan terakhir" → 12 Agustus 2026.
- [X] D6. `PRD-03-database-design.md` (§2.17): catatan dedup level-aplikasi memakai
      **range-overlap** + cek ulang dalam lock; `PRD-02` FR-IZIN dan `temuan.md` L-IZIN
      disinkronkan dengan aturan overlap yang sama.

### E. Verifikasi End-to-End (manual/device)

> Status parsial: perilaku E1–E3 sudah dibuktikan otomatis di level API/command lewat
> `LeaveRequestMultiCourseTest` (fan-out membuat baris `pending`; approve hanya menyentuh
> attendance MK terkait dengan `alpha_menit=0`; `attendance:mark-absent` melewati MK ber-izin
> approved). Checklist tetap `[ ]` karena verifikasi dari device fisik belum dijalankan.

- [ ] E1. Dari HP: submit sakit sehari mode "semua MK" → cek beberapa izin `pending`
      muncul di list izin saya. (Ekuivalen otomatis: `test_multi_course_submission_...`.)
- [ ] E2. Kaprodi approve → attendance MK terkait menjadi `izin`/`sakit`, `alpha_menit=0`.
      (Ekuivalen otomatis: `test_approving_one_multi_leave_only_touches_its_own_course`.)
- [ ] E3. Pastikan `mark-absent` (everyMinute) tidak menandai Alpha untuk MK yang sudah
      punya izin approved pada rentang tersebut. (Ekuivalen otomatis:
      `test_mark_absent_skips_alpha_for_courses_with_approved_multi_leave`.)

## Catatan

- Dedup dan perhitungan alpha tetap **per baris per-MK** — tidak ada perubahan pada
  `AlphaAccumulationService`, `SpDetectionService`, maupun skema tabel `leave_requests`.
- Aturan dedup diperketat saat review: dari kesamaan `tanggal_mulai` menjadi **irisan
  rentang** (`tanggal_mulai ≤ selesai AND tanggal_selesai ≥ mulai`), berlaku untuk mode
  single dan multi. Ini menutup celah izin multi-hari yang bertumpuk untuk MK yang sama
  yang sebelumnya lolos dan menimpa attendance yang sama saat `materializeRange`.
- Cabang respons 422 "nihil dibuat" pada jalur cek-ulang dalam lock kini konsisten dengan
  cabang target-kosong (sama-sama mengirim `errors.skipped` di mode multi).
- Aturan "status `[X]` hanya setelah acceptance terbukti" mengikuti `docs/README.md`.
- Dokumen terintegrasi: [CURRENT-API.md](CURRENT-API.md#izinsakit-leave-request),
  [PRD-02](PRD-02-functional-requirements.md) (FR-IZIN-001), [PRD-03](PRD-03-database-design.md)
  (§2.17), [PRD-04](PRD-04-api-design.md) (§5), [PRD-05](PRD-05-flow-diagram.md) (§8),
  dan [temuan.md](temuan.md) saling merujuk pada kontrak yang sama.
