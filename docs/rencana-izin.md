# Rencana Izin: Shortcut Multi-MK (Izin/Sakit Sehari)

> Dokumen rencana kerja. Status `[X]` hanya diberikan setelah task selesai dan
> dapat dibuktikan (test lulus / verifikasi manual). Task yang menunggu device
> atau manual test tetap `[ ]` dengan catatan status parsial.
> Dokumen ini adalah **proposal aktif**, bukan kontrak API/runtime. Bila
> diimplementasikan, perbarui [CURRENT-API.md](CURRENT-API.md),
> [CURRENT-ARCHITECTURE.md](CURRENT-ARCHITECTURE.md), test, dan [temuan.md](temuan.md).

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

- [ ] A1. Tambah parameter opsional pada validasi `store`: `all_mata_kuliah` (bool) ATAU
      `mata_kuliah_ids` (array of exists). Saat mode multi aktif, `mata_kuliah_id` tunggal
      menjadi opsional.
- [ ] A2. Resolusi daftar MK target: ambil MK yang mahasiswa **enrolled** DAN punya
      `Jadwal` aktif di dalam rentang `tanggal_mulai..tanggal_selesai` (hindari membuat
      izin untuk MK tanpa pertemuan pada rentang itu).
- [ ] A3. Bungkus pembuatan banyak `LeaveRequest` dalam **satu** `DB::transaction`;
      lakukan cek duplikat per-MK (reuse aturan `pending`/`approved` yang ada) dan
      lewati MK yang sudah punya izin aktif pada `tanggal_mulai`.
- [ ] A4. Tangani upload `file_surat` sekali lalu pakai path yang sama untuk semua baris,
      dengan rollback file jika transaksi gagal (pola `try/catch` seperti sekarang).
- [ ] A5. Bentuk response konsisten: kembalikan daftar `LeaveRequest` yang dibuat +
      ringkasan MK yang dilewati (duplikat/tidak ada jadwal), pakai helper `created`.
- [ ] A6. Pertahankan jalur single-MK lama (backward compatible) agar klien lama tetap jalan.

### B. Backend — Test

- [ ] B1. Feature test: submit multi-MK membuat N `LeaveRequest` (N = MK enrolled dengan
      jadwal pada rentang), status `pending`.
- [ ] B2. Feature test: MK tanpa jadwal pada rentang **tidak** dibuatkan izin.
- [ ] B3. Feature test: duplikat per-MK dilewati, sisanya tetap dibuat; tidak ada partial
      commit yang korup (transaksi utuh).
- [ ] B4. Feature test: approve salah satu izin multi → `materializeRange` hanya menandai
      attendance MK tersebut; alpha MK lain tak terpengaruh.
- [ ] B5. Jalankan `composer test` dan pastikan lulus.

### C. Frontend — Toggle multi-MK di `LeavePage`

- [ ] C1. Tambah toggle "Berlaku untuk semua MK" pada form pengajuan (`leave_page.dart`).
- [ ] C2. Saat toggle aktif, sembunyikan/menonaktifkan dropdown MK tunggal dan kirim
      payload mode multi (`all_mata_kuliah`/`mata_kuliah_ids`) via datasource + bloc.
- [ ] C3. Perbarui `leave_remote_datasource.dart`, `leave_repository`, `leave_bloc`,
      `leave_event`, `leave_state` untuk mendukung payload multi-MK.
- [ ] C4. Tampilkan hasil: jumlah izin terbentuk + daftar MK yang dilewati (jika ada).
- [ ] C5. Jalankan `flutter analyze --fatal-warnings --fatal-infos` dan `flutter test`.

### D. Dokumentasi (integrasikan setelah kode selesai)

- [ ] D1. `CURRENT-API.md`: dokumentasikan payload baru `POST /mahasiswa/leave-requests`
      (mode single vs multi-MK) dan bentuk response.
- [ ] D2. `PRD-02-functional-requirements.md` (FR-IZIN-001): tambah opsi izin semua MK.
- [ ] D3. `PRD-05-flow-diagram.md` (FLOW IZIN/SAKIT): cantumkan cabang multi-MK.
- [ ] D4. `PRD-04-api-design.md`: sinkronkan tabel endpoint leave-request bila perlu.
- [ ] D5. `docs/README.md`: perbarui tanggal "Pembaruan terakhir".

### E. Verifikasi End-to-End (manual/device)

- [ ] E1. Dari HP: submit sakit sehari mode "semua MK" → cek beberapa izin `pending`
      muncul di list izin saya.
- [ ] E2. Kaprodi approve → attendance MK terkait menjadi `izin`/`sakit`, `alpha_menit=0`.
- [ ] E3. Pastikan `mark-absent` (everyMinute) tidak menandai Alpha untuk MK yang sudah
      punya izin approved pada rentang tersebut.

## Catatan

- Dedup dan perhitungan alpha tetap **per baris per-MK** — tidak ada perubahan pada
  `AlphaAccumulationService`, `SpDetectionService`, maupun skema `leave_requests`.
- Aturan "status `[X]` hanya setelah acceptance terbukti" mengikuti `docs/README.md`.
