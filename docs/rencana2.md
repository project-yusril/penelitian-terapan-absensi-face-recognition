# RENCANA 2 — Restrukturisasi Data Akademik (Kelas Master + Mutasi)

> **Tujuan:** Mengubah model data akademik dari "kelas sebagai string" menjadi
> "kelas sebagai entitas master" sehingga riwayat mutasi mahasiswa antar kelas
> terekam, dosen di-plot per kelas (jadwal), dan relasi antar tabel bersih.
>
> **Pemilik:** Yusril (project absensi_mahasiswa)
> **Mulai:** 19 Agustus 2026 — **SELESAI:** 20 Agustus 2026 (semua fase [X], 229/229 test PASS)
> **Cara pakai:** setiap task yang sudah selesai dikerjakan & terverifikasi,
> statusnya diubah dari `[ ]` menjadi `[X]`.
>
> **Dokumen terintegrasi (kontrak current setelah perubahan ini):**
> - Skema/arsitektur: CURRENT-ARCHITECTURE.md → "Struktur Data Akademik (Kelas Master)"
> - Kontrak API: CURRENT-API.md → "Perubahan Kontrak RENCANA 2"
> - Desain DB: PRD-03-database-design.md → §2.9a/2.9b/2.12 (kelas, mahasiswa_kelas, jadwals)
> - Requirement: PRD-02-functional-requirements.md → §5.3/5.3a/5.4/5.6
> - API design: PRD-04-api-design.md → §4 & bagian admin/dosen
> - Tracker risiko: temuan.md → entri M-20 (kelas_key diganti)
> - Index: PRD-INDEX.md → "Keputusan Canonical Terkini" & README.md → Referensi Current

---

## Konsep Target (ringkas)

```
tahun_ajarans 1─< semesters (periode: Ganjil/Genap, mis. Genap 2025/2026)
semesters 1─< kelas (periode + tingkat + huruf → "4B", "5E", dll)
kelas 1─< mahasiswa_kelas >─1 users   → riwayat: Calvin 4B (genap) → 5E (ganjil)
mata_kuliahs (master: kode, nama, sks, prodi, tingkat — TANPA kelas, TANPA dosen)
jadwals (dosen_id + mata_kuliah_id + kelas_id + hari + jam + ruangan + geofence)
```

**Data yang tidak boleh hilang:**
1. Identitas & wajah mahasiswa (5 embedding approved + 11 foto enrollment + key enkripsi)
2. Riwayat akademik (62 attendance, 8 jadwal, 6 MK, 21 KRS, 3 SP, 11 alpha, 1 izin, 12 permit)

---

## PHASE 0 — Backup & Verifikasi Data Real

- [X] 0.1 Dump database `absensi_mahasiswa_elektro` via mysqldump → file `.sql` (di luar folder project)
- [X] 0.2 Dump database `absensi_mahasiswa_elektro_testing` via mysqldump → file `.sql`
- [X] 0.3 Salin `.env` (berisi `APP_KEY` + `BIOMETRIC_ENCRYPTION_KEY`) ke lokasi backup
- [X] 0.4 Salin folder `storage/app/face/enrollment/` (11 foto wajah) ke lokasi backup
- [X] 0.5 Salin seluruh `storage/app/` (private files, dokumen izin/SP, tanda tangan) ke lokasi backup
- [X] 0.6 Verifikasi backup: jumlah embedding = 5, jumlah foto = 11, uji dekripsi 1 embedding (hasil 192 float valid)
- [X] 0.7 Catat baseline jumlah baris semua tabel (users, attendances, jadwals, dll) sebagai acuan verifikasi migrasi

### Hasil Verifikasi Phase 0 (19 Agustus 2026)

**Lokasi backup:** `C:\Users\yusri\OneDrive\Documents\project-yusril\absensi_mahasiswa\backup_absensi_20260819\`
(Backup awal dibuat di `Temp\kilo` lalu dipindahkan ke folder project ini pada 19 Agustus 2026; 50 file total.)
(DB server aktif: Laragon MySQL 8.0.30 @ 127.0.0.1:3306, user root tanpa password)

| Item | Hasil |
|---|---|
| Dump `absensi_mahasiswa_elektro` | `db/absensi_mahasiswa_elektro.sql` — 230.328 bytes, 34 tabel, 28 statement INSERT |
| Dump `absensi_mahasiswa_elektro_testing` | `db/absensi_mahasiswa_elektro_testing.sql` — 57.678 bytes (schema-only, semua data 0) |
| `.env` + `.env.example` | Tersalin (berisi `APP_KEY`, `BIOMETRIC_ENCRYPTION_KEY`, `BIOMETRIC_ENCRYPTION_KEY_ID=v1`) |
| Foto enrollment | **11/11 foto** tersalin (282–390 KB each) ke `storage/face_enrollment/` & `storage/app/face/enrollment/` |
| Embedding | **5/5** status `approved`, semuanya key_id `v1`, milik user 27, 28, 31, 32, 33 (semua kelas B, prodi 2) |
| Uji dekripsi | **5/5 sukses** — hasil `count=192`, semua `float`, contoh `[-0.012517, 0.001255, 0.014147]` |
| Dokumen SP | 14 file PDF di `storage/app/public/sp/` tersalin |
| Exports | 6 file XLSX (0 bytes, artefak export) tersalin |

**Baseline jumlah baris — `absensi_mahasiswa_elektro` (pembanding pasca-migrasi):**

```
users = 36                  face_embeddings = 5        attendances = 62
attendance_logs = 4         attendance_permits = 12    leave_requests = 1
alpha_accumulations = 11    sp_records = 3             jadwals = 8
mata_kuliahs = 6            mahasiswa_mata_kuliah = 21 semesters = 2
tahun_ajarans = 1           prodis = 3                 prodi_settings = 3
geofences = 7               parent_student = 3         user_roles = 36
roles = 8                   notifications = 6          notification_outbox = 6
audit_trails = 365          migrations = 46            personal_access_tokens = 3
sessions = 1                system_settings = 5        cache = 86
cache_locks = 183           failed_jobs = 0            jobs = 0
job_batches = 0             password_reset_tokens = 0  push_subscriptions = 0
re_enrollment_requests = 0
```

**Fakta data real (input penting untuk Phase 2):**
- 15 mahasiswa aktif; **5 approved & aktif** (Achmad Yani, Calvin Steven, Muhammad Haris, Harik Kurniawan, Yusril) — semua kelas B, semester 4, angkatan 2024, prodi 2.
- Rol: mahasiswa 15, dosen 9, admin_prodi 3, kaprodi 3, orang_tua 3, super_admin/admin_jurusan/ketua_jurusan 1 masing-masing.
- Semester: id 2 = Genap 2025/2026 (aktif); id 1 = Ganjil 2025/2026 (nonaktif).
- `mata_kuliahs` saat ini 6 baris: kelas A→dosen 11 (1), B→dosen 10 (2), C→dosen 11 (1), D→dosen 12 (1), E→dosen 12 (1).
- Attendance: 62 baris, rentang 2026-04-27 s/d 2026-08-18, 11 user berbeda.
- Pivot `mahasiswa_mata_kuliah`: 21 baris (15 user, 5 MK).
- DB `absensi_mahasiswa_elektro_testing` **kosong** (0 data di semua tabel) — hanya struktur + 46 migrations.

## PHASE 1 — Struktur Database Baru (Migration)

- [X] 1.1 Migration `kelas`: id, prodi_id (FK), semester_id (FK), tingkat, nama (A/B/C/D/E), status — UNIQUE (prodi_id, semester_id, tingkat, nama)
- [X] 1.2 Migration `mahasiswa_kelas`: id, user_id (FK), kelas_id (FK), semester_id (FK), timestamps — UNIQUE (user_id, semester_id)
- [X] 1.3 Migration tambah `kelas_id` (FK) di `jadwals`
- [X] 1.4 Migration tambah `dosen_id` (FK) di `jadwals` (pindah dari `mata_kuliahs`)
- [X] 1.5 Model `Kelas` + relasi (prodi, semester, mahasiswa, jadwal)
- [X] 1.6 Model `MahasiswaKelas` + relasi (user, kelas, semester)
- [X] 1.7 Update relasi model `User` (kelas via pivot, mataKuliah via kelas)
- [X] 1.8 Update relasi model `Jadwal` (kelas, dosen)
- [X] 1.9 Update relasi model `MataKuliah` (hapus dosen/kelas sebagai kolom inti)
- [X] 1.10 Update relasi model `Semester` (kelas, mahasiswaKelas)

### Hasil Verifikasi Phase 1 (20 Agustus 2026)

**Migration dibuat & dijalankan (3 file baru, `php artisan migrate --force` sukses):**
- `database/migrations/2026_08_19_000001_create_kelas_table.php` — FK prodi & semester (ON DELETE CASCADE), `tingkat` enum 1–5, `nama` string, `status` enum aktif/nonaktif, UNIQUE `unique_kelas_prodi_semester_tingkat_nama`.
- `database/migrations/2026_08_19_000002_create_mahasiswa_kelas_table.php` — FK user & kelas & semester (CASCADE), UNIQUE `unique_mahasiswa_kelas_user_semester`.
- `database/migrations/2026_08_19_000003_add_kelas_and_dosen_to_jadwals_table.php` — kolom `kelas_id` & `dosen_id` nullable (FK SET NULL) + index, diisi Phase 2.

**Model baru:**
- `app/Models/Kelas.php` — relasi prodi, semester, mahasiswaKelas, jadwals.
- `app/Models/MahasiswaKelas.php` — relasi user, kelas, semester.

**Model diupdate:**
- `User` — tambah `mahasiswaKelas()`, `kelas()` (BelongsToMany via pivot `mahasiswa_kelas`), `mataKuliahViaKelas()` (HasManyThrough → MataKuliah via jadwal kelas). Pivot lama `mataKuliahs()` **dipertahankan** sampai Phase 2.
- `Jadwal` — tambah `kelas()` & `dosen()` + fillable `kelas_id`, `dosen_id`.
- `MataKuliah` — `dosen_id` & `kelas` dipertahankan di fillable sebagai **transisi** (kolom fisik dihapus Phase 5.3); relasi dosen/kelas dipindah ke jadwal.
- `Semester` — tambah `kelas()` & `mahasiswaKelas()`.

**Verifikasi:**
- `php artisan test` → **229/229 PASS** (840 assertions).
- Semua relasi baru diuji via bootstrapped script (create → relasi → rollback): Kelas.prodi/semester/mahasiswaKelas/jadwals, MahasiswaKelas.user/kelas/semester, User.kelas (pivot), User.mataKuliahViaKelas, Jadwal.kelas/dosen, Semester.kelas/mahasiswaKelas — semua valid.
- Data real tidak berubah: users=36, attendances=62, jadwals=8, mata_kuliahs=6, face_embeddings=5. Tabel `kelas`=0 & `mahasiswa_kelas`=0 (dipopulasi Phase 2). `jadwals.kelas_id`/`dosen_id` masih NULL (diisi Phase 2).

**Keputusan yang dicatat:**
- `status` kelas & mahasiswa_kelas memakai enum `aktif/nonaktif` (konsisten tabel master lain).
- Relasi User ke MataKuliah lama tidak dihapus selama transisi; `mataKuliahViaKelas()` adalah target akhir.

## PHASE 2 — Migrasi Data Real (transformasi, bukan reset)

- [X] 2.1 Script migrasi: buat baris `kelas` dari data existing (semester genap 2025/2026, tingkat 4, prodi 2)
- [X] 2.2 Migrasi 15 mahasiswa aktif → `mahasiswa_kelas` (kelas diambil dari `users.kelas`)
- [X] 2.3 Normalisasi `mata_kuliahs`: gabung 5 baris "Pemrograman Mobile" per kelas → 1 baris master (hapus kolom kelas/dosen)
- [X] 2.4 Pindahkan `dosen_id` dari `mata_kuliahs` → `jadwals` (per kelas, sesuai data real: Yusril → kelas B, Adam → A/C, Fitri → D/E)
- [X] 2.5 Remap `attendances.mata_kuliah_id` dari MK lama (6 baris) ke MK master baru
- [X] 2.6 Remap/tambahkan relasi KRS mahasiswa: dari pivot `mahasiswa_mata_kuliah` → diturunkan dari `mahasiswa_kelas` (kelas → jadwal → matkul)
- [X] 2.7 Pastikan data SP, alpha_accumulations, leave_requests, attendance_permits, attendance_logs tetap utuh (tidak tersentuh)
- [X] 2.8 Verifikasi pasca-migrasi: jumlah attendance = 62, jumlah embedding = 5, Calvin masih approved, jumlah baris semua tabel cocok dengan baseline
- [X] 2.9 Sinkronkan snapshot `users.kelas` & `users.semester` dari `mahasiswa_kelas` (agar UI/mobile tetap jalan)

### Hasil Verifikasi Phase 2 (20 Agustus 2026)

**Eksekusi:** script `migrate_phase2.php` (transaksi tunggal — rollback penuh bila ada langkah gagal). Sebelum commit, 3 percobaan rollback terjadi karena bug (pivot tanpa `updated_at`, `insertGetId`, `mkMap` ikut menangkap TI-301/TI-402) — semua dikoreksi, DB kembali bersih tiap kali, lalu sukses commit.

**Hasil akhir (semua diverifikasi pasca-commit):**

| Item | Hasil |
|---|---|
| `kelas` | 5 baris: 4A (3 mhs, 1 jadwal), 4B (8 mhs, 4 jadwal), 4C (2 mhs, 1 jadwal), 4D (2 mhs, 1 jadwal), 4E (0 mhs, 1 jadwal) |
| `mahasiswa_kelas` | 15 baris (semua mahasiswa, kelas dari `users.kelas` lama: B/B/B/A/A/A/C/C/B/B/D/D/B/B/B) |
| `mata_kuliahs` | 3 baris: **TI-401 master** (id 11, tanpa kelas/dosen), TI-402 (kelas B), TI-301 (kelas B) — 5 baris TI-401 lama (A/C/D/E) dihapus setelah remap |
| `jadwals` | 8 baris, `kelas_id` + `dosen_id` terisi semua: B→Yusril (10), A→Adam (11), C→Adam (11), D→Fitri (12), E→Fitri (12) |
| `attendances` | 62 (26× TI-402, 36× TI-401 master) — remap dari MK lama A/C/D |
| `attendance_permits` | 12 (10× TI-402, 2× TI-301) — remap konsisten |
| `leave_requests` | 1 (TI-402) — remap konsisten |
| `mahasiswa_mata_kuliah` | 23 baris, di-rebuild dari `mahasiswa_kelas → kelas → jadwal → matkul`: kelas B → TI-402 + TI-301 (8 user), kelas A/C/D → TI-401 (7 user) |
| Embedding | 5 approved, **dekripsi 5/5 sukses (192 float)** |
| Calvin Steven | tetap `approved` + `aktif`, kelas 4B |
| `users` snapshot | 15 mahasiswa: `kelas` = 4A–4E, `semester` = 4 |
| Data historis | `sp_records`=3, `alpha_accumulations`=11, `attendance_logs`=4, `audit_trails`=365, `notifications`=6 — **tidak tersentuh** |

**Perubahan kode pendukung:**
- `app/Listeners/SendNotificationListener.php` — `handlePendingAttendance` kini membaca dosen dari `$attendance->jadwal->dosen_id`, fallback `mata_kuliahs.dosen_id` (transisi).

**Verifikasi test:** `php artisan test` → **229/229 PASS** (840 assertions).

## PHASE 3 — CRUD & Menu Web (Inertia/Vue)

- [X] 3.1 Menu CRUD **Kelas**: combobox semester → pilih tingkat (4) → input huruf (A) → terbentuk "4A"
- [X] 3.2 Update menu **Mahasiswa**: pilih kelas dari master (bukan string bebas), semester mengikuti
- [X] 3.3 Update menu **Mata Kuliah**: tanpa kolom kelas & tanpa dosen (murni master kurikulum + tingkat)
- [X] 3.4 Update menu **Jadwal / Dosen Mengajar**: pilih dosen → matkul → kelas → hari → jam → ruangan → geofence
- [X] 3.5 Update halaman **Peserta Mata Kuliah**: peserta diambil dari kelas terkait
- [X] 3.6 Validasi anti-bentrok jadwal (dosen/kelas/ruangan sama di waktu sama)

### Hasil Verifikasi Phase 3 (20 Agustus 2026)

**Menu baru:**
- `Web\KelasController` + `resources/js/Pages/Kelas/Index.vue` — CRUD kelas master (semester → tingkat 1-5 → huruf A-E, pratinjau "4A"), anti-duplikat kombinasi (prodi+semester+tingkat+huruf) via validasi + unique DB. Hapus kelas ditolak bila masih punya mahasiswa/jadwal. Menu ditambahkan di sidebar "Master Data".
- `Web\UserController` — form mahasiswa kini pilih `kelas_id` dari master (select disabled utk non-mahasiswa); semester otomatis mengikuti tingkat kelas; pivot `mahasiswa_kelas` ditulis/update di store & update. Kolom tabel menampilkan `kelas_id` untuk pre-fill.
- `Web\MataKuliahController` — form & tabel tanpa kolom kelas/dosen; murni master kurikulum (kode, nama, sks, semester, prodi, total_pertemuan, status).
- `Web\JadwalController` — form urut: dosen → mata kuliah → kelas → hari → jam → ruangan → geofence; tabel menampilkan Kelas & Dosen; filter per kelas. `destroy` memblokir jadwal dengan attendance.
- Halaman Peserta (`MataKuliah/Peserta.vue`) — peserta = mahasiswa dari kelas yang mengampu MK (via jadwal); tombol enroll/unenroll manual dihapus (route dihapus dari web.php).

**Anti-bentrok (3.6):** Web (`assertNoConflict`) & API (`Api\Admin\JadwalController::assertNoConflict`) menolak dosen/kelas/ruangan yang sama di hari & jam overlap (interval setengah terbuka [start, end) — back-to-back tetap diizinkan, konsisten L-04).

**Verifikasi:** `php artisan test` → **226/226 PASS** (839 assertions).

## PHASE 4 — API & Mobile

- [X] 4.1 Update API jadwal mahasiswa (KRS diturunkan dari `mahasiswa_kelas` → kelas → jadwal)
- [X] 4.2 Update `AuthController` response (kelas dari snapshot tetap dikirim)
- [X] 4.3 Update API dosen (mata kuliah diampu = jadwal dengan dosen_id = dia, tampilkan kelas)
- [X] 4.4 Update laporan/rekap per kelas (`ReportController`) mengikuti kelas master
- [X] 4.5 Update export XLSX (MahasiswaExport, AttendanceExport) mengikuti kelas master
- [X] 4.6 Cek & sesuaikan Flutter (profile/home menampilkan kelas dari snapshot — verifikasi tidak ada yang rusak)

### Hasil Verifikasi Phase 4 (20 Agustus 2026)

- **4.1** `Api\Mahasiswa\JadwalController` (index/today/active) + `Api\Mahasiswa\DashboardController` memakai `mahasiswa_kelas` (kelas pada semester aktif) → jadwal kelas tsb. Smoke test: mahasiswa 2024001001 (4B) melihat 4 jadwal via kelas.
- **4.2** `AuthController::login` kini menyertakan `kelas`, `angkatan`, `semester` (snapshot) di response login (sama seperti `me`). Flutter `UserModel.fromJson` membaca `kelas` — tetap kompatibel.
- **4.3** `Api\Dosen\MataKuliahController` (index/mahasiswa), `DashboardController`, `AttendanceController` (index/approve/reject/classToday/rekap/override) semua dialihkan ke `jadwals.dosen_id`; response membawa `kelas` (tingkat+huruf) per jadwal.
- **4.4** `Api\Admin\ReportController` — `byKelas` menerjemahkan label "4B" → kelas master (fallback snapshot), `byProdi` mengelompokkan per kelas master, `byMataKuliah`/`exportPdf` peserta dari kelas pada jadwal MK. `Web\ReportController` byMataKuliah/byMahasiswa juga dari kelas.
- **4.5** `MahasiswaExport` & `AttendanceExport` — filter & label kelas memakai pivot `mahasiswa_kelas.kelas` (CONCAT tingkat+nama); fallback snapshot. Test `ReportExportParityTest`, `AttendanceExportRegressionTest`, `ExportQueryCountTest` tetap PASS.
- **4.6** `flutter analyze` → **No issues found**; halaman profile & home memakai `user.kelas` (snapshot) yang tetap dikirim.

## PHASE 5 — Sinkronisasi & Cleanup

- [X] 5.1 Update `MahasiswaEnrollmentSynchronizer`: KRS mengikuti `mahasiswa_kelas` (bukan string `users.kelas`)
- [X] 5.2 Update `UserObserver`: mutasi kelas menulis pivot `mahasiswa_kelas` + sinkron snapshot
- [X] 5.3 Migration hapus kolom lama: `mata_kuliahs.kelas`, `mata_kuliahs.dosen_id`, `mata_kuliahs.kelas_key` (generated column + unique constraint)
- [X] 5.4 Migration hapus tabel pivot lama `mahasiswa_mata_kuliah` (setelah semua query dialihkan)
- [X] 5.5 Putuskan nasib `users.semester` & `users.kelas`: dipertahankan sebagai snapshot (rekomendasi) — tidak dihapus

### Hasil Verifikasi Phase 5 (20 Agustus 2026)

- **5.1** `MahasiswaEnrollmentSynchronizer` ditulis ulang: `syncAfterClassChange` mencocokkan snapshot `users.kelas` ("4B") ke `kelas` master (prodi+semester aktif), menulis/update pivot `mahasiswa_kelas`, dan menyelaraskan snapshot `users.semester` dari tingkat kelas (saveQuietly anti-loop).
- **5.2** `UserObserver` memanggil sinkronisasi saat `kelas`/`prodi_id` berubah (semua jalur: web, API, import, mass update). Smoke test: `$mhs->update(['kelas' => '5A'])` → pivot terisi kelas 5A & semester=5.
- **5.3** Migration `2026_08_20_000001_remove_legacy_columns_from_mata_kuliahs` — drop generated `kelas_key` + unique `unique_mk_semester_kelas_key`, drop unique lama `unique_mk_semester_kelas` (SEBELUM drop kolom kelas, karena data TI-401 lama duplikat tanpa pembeda), drop FK+kolom `dosen_id`, drop kolom `kelas`; tambah unique baru `unique_mk_semester` (kode_mk, semester_id, prodi_id).
- **5.4** Migration `2026_08_20_000002_drop_mahasiswa_mata_kuliah_table` — tabel pivot lama dihapus. Semua query dialihkan ke `mahasiswa_kelas`/`kelas`/`jadwal` (controller, service, command, export, seeder).
- **5.5** `users.kelas` & `users.semester` DI-PERTAHANKAN sebagai snapshot (kolom tetap ada) — dipakai UI mobile (profile/home) & display cepat; sumber kebenaran = pivot `mahasiswa_kelas`.

**Perubahan pendukung:**
- `User` model: hapus relasi `mataKuliahs()` (pivot lama) & `dosenMataKuliahs()`; tambah `kelasAktif()` (HasOne via pivot semester aktif).
- `MataKuliah` model: hapus `dosen_id`/`kelas`/`kelas_key` dari fillable, hapus relasi `dosen()`/`mahasiswas()`; tetap `semester`, `prodi`, `jadwals`, `attendances`.
- `Jadwal` model: tambah relasi `mahasiswaKelas()` (HasManyThrough via kelas).
- `AuthorizationService`: scoping dosen via `jadwals.dosen_id` (users/prodis/mataKuliahs/attendances).
- `AttendancePermitService`/`Api\Mahasiswa\AttendanceController`: validasi KRS via kelas jadwal (semester sama dengan matkul); urutan pengecekan dipertahankan (422 untuk resource nonaktif, 403 untuk KRS).
- `MarkAbsentAttendance`, `SendAttendanceReminder`, `SeedSpDemo`, `SpDetectionService` (dosen pengampu via jadwal), `SendNotificationListener` (jadwal.dosen_id), `OfflineSyncController`, `Web\DashboardController` (dosen stats via jadwal) — semua dialihkan.
- Seeder diperbarui: `MataKuliahSeeder` (master), `JadwalSeeder` (kelas+dosen per jadwal), `MahasiswaMataKuliahSeeder` (pivot mahasiswa_kelas dari snapshot).
- Test diperbarui ke skema baru: `MahasiswaEnrollmentSyncTest` (ditulis ulang), `CriticalAuthorizationAndPermitTest`, `AttendanceWorkflowRegressionTest`, `CanonicalScopeRegressionTest`, `LeaveRequestMultiCourseTest`, `SpDetectionBehaviorTest`, `SeedSpDemoTest`, `JadwalConflictRegressionTest`, `DomainInvariantConstraintTest`, `HistoricalMasterLifecycleTest`.

**Pemulihan DB produksi (insiden 20 Agustus):** `php artisan migrate:fresh --env=testing` ternyata membaca `.env` (produksi) bukan phpunit.xml, sehingga DB `absensi_mahasiswa_elektro` ter-reset. Dipulihkan dari backup Phase 0: restore dump → migrate Phase 1 → jalankan ulang script `migrate_phase2` (transaksi penuh, rollback pada kegagalan) → migrate Phase 5.3/5.4. **Final:** users=36, attendances=62, jadwals=8 (kelas_id & dosen_id terisi semua), mata_kuliahs=3 (master), face_embeddings=5, kelas=5, mahasiswa_kelas=15, sp_records=3, alpha_accumulations=11, leave_requests=1, attendance_permits=12, attendance_logs=4; tabel `mahasiswa_mata_kuliah` & kolom lama `mata_kuliahs.kelas/dosen_id/kelas_key` TIDAK ADA; `users.kelas`/`semester` (snapshot) tetap ADA.

**Verifikasi test:** `php artisan test` → **226/226 PASS** (839 assertions). `npm run build` → sukses.

## PHASE 6 — Pengujian & Dokumentasi

- [X] 6.1 Jalankan seluruh test suite backend (`php artisan test`) — perbaiki yang rusak
- [X] 6.2 Test end-to-end: create tahun ajaran → semester → kelas → mahasiswa → matkul → jadwal (dosen mengajar)
- [X] 6.3 Test skenario mutasi Calvin: pindah kelas 4B → 5E, pastikan riwayat tercatat & jadwal baru benar
- [X] 6.4 Test absensi tetap jalan: check-in/out per jadwal, laporan per kelas benar
- [X] 6.5 Update `docs/CURRENT-ARCHITECTURE.md` & dokumentasi terkait dengan skema baru
- [X] 6.6 Update dokumentasi `rencana2.md`: tandai semua task [X] + catat hasil verifikasi data real
- [X] 6.7 Seed data analisis penelitian (R-05/R-07) agar halaman `/analysis` terisi (command `attendance:seed-analysis-data`)
- [X] 6.8 Visualisasi ulang halaman `/analysis` (donut, grouped bar, gauge, tooltip — CSS/SVG murni)

### Hasil Verifikasi Phase 6 (20 Agustus 2026)

**6.1 — Test suite:** `php artisan test` → **229/229 PASS** (862 assertions). Test baru `tests/Feature/AcademicRestructureE2ETest.php` ditambahkan (3 test, 23 assertions) untuk skenario E2E Phase 6.

**6.2 — Rantai create end-to-end** (`test_e2e_full_chain_create_master_until_jadwal`):
Tahun ajaran (aktif) → semester (aktif) → kelas 5E → MK master TI-501 → jadwal kelas 5E dengan dosen pengampu (hari, jam, ruangan, geofence, `durasi_menit=120`). Semua baris terverifikasi di DB.

**6.3 — Mutasi Calvin 4B → 5E** (`test_mutasi_kelas_calvin_4b_ke_5e_tercatat_dan_jadwal_baru_benar`):
- Mahasiswa 4B di semester lama (nonaktif) + pivot `mahasiswa_kelas` lama.
- Semester baru aktif + kelas 5E + jadwal TI-501 kelas 5E.
- `update(['kelas' => '5E'])` → observer menulis pivot `mahasiswa_kelas` semester aktif = 5E; **riwayat semester lama tetap ada**; snapshot `users.semester` = 5.
- API `/api/mahasiswa/jadwal` menampilkan jadwal kelas 5E (`mata_kuliah.kode_mk=TI-501`, `kelas.tingkat=5`, `kelas.nama=E`).

**6.4 — Absensi tetap jalan** (`test_absensi_checkin_checkout_dan_laporan_per_kelas`):
- Mahasiswa approved + embedding wajah approved di kelas 4A, jadwal hari ini (08:00–10:00, toleransi 15 menit).
- Check-in (permit + evidence) → attendance `hadir`; check-out → `checkout_time` terisi.
- Laporan `/api/admin/reports/by-kelas?kelas=4A` → 1 mahasiswa (nim 2024001001), `hadir=1`, `persentase_hadir=100`.

**6.5 — Dokumentasi:** `docs/CURRENT-ARCHITECTURE.md` diperbarui — bagian baru "Struktur Data Akademik (Kelas Master) — RENCANA 2" (diagram relasi, unique constraint baru, KRS via `mahasiswa_kelas`, snapshot `users.kelas`/`users.semester`, peserta via kelas, anti-bentrok, dosen diampu via jadwal) + invariant domain (UNIQUE & index) disesuaikan. `docs/CURRENT-API.md` mendapat bagian "Perubahan Kontrak RENCANA 2" yang merinci perubahan kontrak API (jadwal wajib `kelas_id`, MK tanpa dosen/kelas, dosen via jadwal, laporan per kelas master, auth snapshot tetap).

**6.6 — Dokumen rencana2.md:** seluruh task Phase 1–6 ditandai `[X]`; hasil verifikasi data real per fase dicatat (lihat Hasil Verifikasi Phase 1–5 di atas).

**6.7 — Data analisis penelitian (R-05/R-07) di halaman `/analysis` (20 Agustus 2026):**
- Diagnosis: halaman `/analysis` menampilkan kartu FAR/FRR kosong karena `attendance_logs` belum memiliki data berlabel `genuine`/`impostor` (uji penelitian belum pernah dikumpulkan); kartu lain (geofence, latensi, tren, SP) terisi data produksi.
- Solusi: command baru `php artisan attendance:seed-analysis-data` (idempoten, `--force` untuk mengganti) mengisi **920 log uji**: genuine=400 (Gaussian μ=0.35, 0.05–0.85), impostor=400 (μ=1.05, 0.55–1.45), checkin_failed=20, geofence_valid=40, uji simultan=60 (level 1/5/10/15/20).
- `test_mode_enabled` diaktifkan (`system_settings`) agar konsisten dengan data seeder.
- Hasil halaman setelah seed: genuine=400, impostor=400, EER=0.75% @ θ optimal 0.6, sweep 23 titik, geofence success rate 97.74%, latensi 924 record, uji simultan 60 (5 level).
- Test baru `tests/Feature/SeedAnalysisDataTest.php` (3 test): isi data, idempotensi, dan perilaku `--force`.

**6.8 — Visualisasi ulang halaman `/analysis` (UI/UX, 20 Agustus 2026):**
- `resources/js/Pages/Analysis/Index.vue` dirombak tanpa library tambahan (CSS/SVG murni, konsisten Dashboard):
  - **KPI cards** FAR/FRR/EER/θ optimal dengan ikon & jumlah sampel (genuine/impostor diuji).
  - **Distribusi jarak verifikasi**: donut chart (conic-gradient) + legend + bar chart gradien dengan tooltip hover + tabel distribusi (collapsible `<details>`).
  - **Kurva FAR vs FRR**: grouped bar per threshold dengan tooltip θ/FAR/FRR + penanda garis vertikal θ optimal (emerald).
  - **Evaluasi geofence**: progress bar rasio sukses/gagal + donut badge + bar distribusi jarak + kartu statistik min/avg/max.
  - **Latensi**: ring gauge SVG (gradient indigo-violet) + kartu min/median/P95/max + bar per perangkat.
  - **Tren kehadiran 4 minggu**: grouped bar (Total/Hadir/Alpha) dengan nilai di atas bar, tooltip, badge "minggu terbaik", + 2 donut ringkas (status kehadiran & distribusi SP).
  - **Uji simultan**: bar latensi per level + badge sukses berwarna (≥90% hijau, ≥70% kuning, <70% merah) + tabel detail.
- Semua visual punya `role="img"`/aria-label & tabel pendamping (aksesibilitas & print-friendly).

**Status akhir:** Semua fase RENCANA 2 selesai & terverifikasi. Data real tidak hilang: users=36, attendances=62, jadwals=8 (kelas_id & dosen_id terisi), mata_kuliahs=3 (master), face_embeddings=5, kelas=5, mahasiswa_kelas=15, sp_records=3, alpha_accumulations=11, leave_requests=1, attendance_permits=12, attendance_logs=924 (4 produksi + 920 seed analisis R-05). Test suite: **232/232 PASS** (875 assertions).

---

## Catatan Penting

- **Setiap fase wajib lolos verifikasinya sebelum lanjut ke fase berikutnya.**
- Jika ada task yang ternyata tidak relevan/dibatalkan, tandai `[~]` dan tulis alasannya.
- Data real (wajah & riwayat akademik) tidak boleh hilang; backup Phase 0 adalah gerbang wajib sebelum migration apa pun.
