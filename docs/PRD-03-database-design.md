# PRD-03: DATABASE DESIGN

> **Status:** desain logis awal. Schema executable dan authoritative berada di
> `backend/database/migrations/`. Ringkasan arsitektur data/security terkini ada
> di [CURRENT-ARCHITECTURE.md](CURRENT-ARCHITECTURE.md). Dokumen ini tidak boleh
> digunakan untuk membuat migration baru tanpa membandingkan seluruh migration
> forward, termasuk attendance permits, encrypted biometrics, private files,
> dan `activation_pending`.

**Nama Database**: `absensi_mahasiswa_elektro`

## 1. ENTITY RELATIONSHIP DIAGRAM (ERD) - Tekstual

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   users     │────<│ user_roles  │>────│    roles    │
└─────────────┘     └─────────────┘     └─────────────┘
       │
       ├── 1:1
       │    ▼
       │ ┌─────────────────┐
       │ │ face_embeddings │
       │ └─────────────────┘
       │
       └── M:N (orang_tua ↔ mahasiswa)
            ▼
       ┌─────────────────┐
       │ parent_student  │
       └─────────────────┘

┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   prodis    │────<│ mata_kuliahs│>────│  semesters  │
└─────────────┘     └─────────────┘     └─────────────┘
                          │                     │
                          │                     │
                          ▼                     ▼
                    ┌─────────────┐     ┌─────────────────┐
                    │  jadwals    │     │  tahun_ajarans  │
                    └─────────────┘     └─────────────────┘
                          │
                          │
                          ▼
                    ┌─────────────┐
                    │  geofences  │
                    └─────────────┘

┌─────────────┐     ┌──────────────────┐
│   users     │────<│   attendances    │
│(mahasiswa)  │     │  (check-in/out)  │
└─────────────┘     └──────────────────┘
                          │
                          ▼
                    ┌──────────────────┐
                    │ attendance_logs  │
                    │ (detail record)  │
                    └──────────────────┘

┌─────────────┐     ┌──────────────────┐
│   users     │────<│  sp_records      │
│(mahasiswa)  │     │  (SP1/SP2/SP3/DO)│
└─────────────┘     └──────────────────┘
                          │
                          ▼
                    ┌──────────────────┐
                    │  sp_documents    │
                    │  (PDF generated) │
                    └──────────────────┘
```

---

## 2. SCHEMA DETAIL (MySQL)

### 2.1 Tabel: `roles`
```sql
CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Data awal:
-- 1: super_admin, 2: ketua_jurusan, 3: admin_jurusan,
-- 4: kaprodi, 5: admin_prodi, 6: dosen, 7: mahasiswa, 8: orang_tua
```

### 2.2 Tabel: `prodis`
```sql
CREATE TABLE prodis (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kode VARCHAR(20) NOT NULL UNIQUE,
    nama VARCHAR(100) NOT NULL,
    jenjang ENUM('D3', 'D4', 'S1') DEFAULT 'D3',
    jurusan VARCHAR(100) DEFAULT 'Teknik Elektro',
    status ENUM('aktif', 'nonaktif') DEFAULT 'aktif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Data awal:
-- 1: Teknik Listrik, 2: Teknik Informatika, 3: Teknik Elektro
```

### 2.3 Tabel: `users`
```sql
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(150) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    nim VARCHAR(20) NULL UNIQUE,          -- untuk mahasiswa
    nidn VARCHAR(20) NULL UNIQUE,         -- untuk dosen
    nip VARCHAR(30) NULL,                 -- untuk PNS
    no_hp VARCHAR(20) NULL,
    tempat_lahir VARCHAR(100) NULL,       -- biodata
    tanggal_lahir DATE NULL,             -- biodata
    jenis_kelamin ENUM('L', 'P') NULL,   -- L=Laki-laki, P=Perempuan
    alamat TEXT NULL,                     -- alamat lengkap
    prodi_id BIGINT UNSIGNED NULL,
    kelas VARCHAR(10) NULL,               -- untuk mahasiswa (A, B, C)
    angkatan YEAR NULL,                   -- untuk mahasiswa
    semester INT NULL,                    -- semester aktif mahasiswa (1-8)
    jabatan_fungsional VARCHAR(50) NULL,   -- untuk dosen
    pendidikan_terakhir VARCHAR(50) NULL,  -- untuk dosen (S1, S2, S3)
    bidang_keahlian VARCHAR(255) NULL,     -- untuk dosen (misal: "Mobile Development, AI")
    foto_profil VARCHAR(255) NULL,
    foto_enrollment VARCHAR(255) NULL,     -- foto wajah saat enrollment (JPG, untuk identitas visual/biodata)
    tanda_tangan VARCHAR(255) NULL,        -- path file tanda tangan digital
    status ENUM('aktif', 'nonaktif', 'do') DEFAULT 'aktif',
    must_change_password BOOLEAN DEFAULT TRUE,
    enrollment_status ENUM('belum', 'pending', 'approved', 'rejected') DEFAULT 'belum',
    fcm_token TEXT NULL,                  -- untuk push notification
    last_login_at TIMESTAMP NULL,
    email_verified_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,            -- soft delete
    
    FOREIGN KEY (prodi_id) REFERENCES prodis(id) ON DELETE SET NULL,
    INDEX idx_users_prodi (prodi_id),
    INDEX idx_users_nim (nim),
    INDEX idx_users_nidn (nidn),
    INDEX idx_users_status (status)
);
```

### 2.4 Tabel: `user_roles`
```sql
CREATE TABLE user_roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_role (user_id, role_id)
);
```

### 2.5 Tabel: `face_embeddings`
```sql
CREATE TABLE face_embeddings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    embedding JSON NOT NULL,              -- array 192 float values
    version INT DEFAULT 1,                -- untuk tracking re-enrollment
    status ENUM('pending', 'approved', 'rejected', 'inactive') DEFAULT 'pending',
    approved_by BIGINT UNSIGNED NULL,     -- admin yang approve
    approved_at TIMESTAMP NULL,
    rejected_reason TEXT NULL,
    liveness_passed BOOLEAN DEFAULT FALSE,
    enrollment_device VARCHAR(100) NULL,   -- model HP saat enrollment
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_embedding_user (user_id),
    INDEX idx_embedding_status (status)
);
```

### 2.6 Tabel: `re_enrollment_requests`
```sql
CREATE TABLE re_enrollment_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    alasan ENUM('potong_rambut', 'pakai_jilbab', 'lepas_jilbab', 'perubahan_lain') NOT NULL,
    keterangan TEXT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    approved_by BIGINT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    rejected_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);
```

### 2.7 Tabel: `tahun_ajarans`
```sql
CREATE TABLE tahun_ajarans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kode VARCHAR(20) NOT NULL UNIQUE,     -- misal "2025/2026"
    nama VARCHAR(50) NOT NULL,
    tanggal_mulai DATE NOT NULL,
    tanggal_selesai DATE NOT NULL,
    status ENUM('aktif', 'nonaktif') DEFAULT 'nonaktif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### 2.8 Tabel: `semesters`
```sql
CREATE TABLE semesters (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tahun_ajaran_id BIGINT UNSIGNED NOT NULL,
    nama ENUM('Ganjil', 'Genap') NOT NULL,
    kode VARCHAR(20) NOT NULL UNIQUE,     -- misal "2025/2026-1"
    tanggal_mulai DATE NOT NULL,
    tanggal_selesai DATE NOT NULL,
    status ENUM('aktif', 'nonaktif') DEFAULT 'nonaktif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajarans(id) ON DELETE CASCADE,
    INDEX idx_semester_tahun (tahun_ajaran_id)
);
```

### 2.9 Tabel: `mata_kuliahs`
```sql
CREATE TABLE mata_kuliahs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kode_mk VARCHAR(20) NOT NULL,
    nama VARCHAR(100) NOT NULL,
    sks INT NOT NULL DEFAULT 2,
    semester_id BIGINT UNSIGNED NOT NULL,
    prodi_id BIGINT UNSIGNED NOT NULL,
    dosen_id BIGINT UNSIGNED NULL,        -- dosen pengampu
    kelas VARCHAR(10) NULL,               -- A, B, C
    total_pertemuan INT DEFAULT 16,
    status ENUM('aktif', 'nonaktif') DEFAULT 'aktif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
    FOREIGN KEY (prodi_id) REFERENCES prodis(id) ON DELETE CASCADE,
    FOREIGN KEY (dosen_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_mk_semester (semester_id),
    INDEX idx_mk_prodi (prodi_id),
    INDEX idx_mk_dosen (dosen_id),
    UNIQUE KEY unique_mk_semester_kelas (kode_mk, semester_id, kelas)
);
```

### 2.10 Tabel: `mahasiswa_mata_kuliah` (Pivot)
```sql
CREATE TABLE mahasiswa_mata_kuliah (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,     -- mahasiswa
    mata_kuliah_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (mata_kuliah_id) REFERENCES mata_kuliahs(id) ON DELETE CASCADE,
    UNIQUE KEY unique_mhs_mk (user_id, mata_kuliah_id)
);
```

### 2.11 Tabel: `geofences`
```sql
CREATE TABLE geofences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(100) NOT NULL,           -- misal "Lab Komputer 1"
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    radius INT NOT NULL DEFAULT 50,       -- dalam meter
    gedung VARCHAR(100) NULL,
    lantai VARCHAR(10) NULL,
    prodi_id BIGINT UNSIGNED NULL,
    status ENUM('aktif', 'nonaktif') DEFAULT 'aktif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (prodi_id) REFERENCES prodis(id) ON DELETE SET NULL,
    INDEX idx_geofence_prodi (prodi_id)
);
```

### 2.12 Tabel: `jadwals`
```sql
CREATE TABLE jadwals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mata_kuliah_id BIGINT UNSIGNED NOT NULL,
    geofence_id BIGINT UNSIGNED NOT NULL,
    hari ENUM('Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu') NOT NULL,
    jam_mulai TIME NOT NULL,
    jam_selesai TIME NOT NULL,
    ruangan VARCHAR(50) NULL,
    durasi_menit INT GENERATED ALWAYS AS (TIMESTAMPDIFF(MINUTE, jam_mulai, jam_selesai)) STORED,
    status ENUM('aktif', 'nonaktif') DEFAULT 'aktif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (mata_kuliah_id) REFERENCES mata_kuliahs(id) ON DELETE CASCADE,
    FOREIGN KEY (geofence_id) REFERENCES geofences(id) ON DELETE CASCADE,
    INDEX idx_jadwal_mk (mata_kuliah_id),
    INDEX idx_jadwal_hari (hari)
);
```

### 2.13 Tabel: `attendances`
```sql
CREATE TABLE attendances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,         -- mahasiswa
    jadwal_id BIGINT UNSIGNED NOT NULL,
    mata_kuliah_id BIGINT UNSIGNED NOT NULL,
    tanggal DATE NOT NULL,
    pertemuan_ke INT NULL,                    -- pertemuan ke-berapa
    
    -- Check-in data
    checkin_time TIMESTAMP NULL,
    checkin_latitude DECIMAL(10, 8) NULL,
    checkin_longitude DECIMAL(11, 8) NULL,
    checkin_distance DECIMAL(8, 2) NULL,      -- jarak ke geofence (meter)
    checkin_face_distance DECIMAL(10, 6) NULL, -- euclidean distance
    checkin_liveness_passed BOOLEAN DEFAULT FALSE,
    checkin_device VARCHAR(100) NULL,
    
    -- Check-out data
    checkout_time TIMESTAMP NULL,
    checkout_latitude DECIMAL(10, 8) NULL,
    checkout_longitude DECIMAL(11, 8) NULL,
    checkout_distance DECIMAL(8, 2) NULL,
    checkout_face_distance DECIMAL(10, 6) NULL,
    checkout_liveness_passed BOOLEAN DEFAULT FALSE,
    checkout_device VARCHAR(100) NULL,
    
    -- Status & Kalkulasi
    status ENUM('hadir', 'hadir_terlambat', 'pending', 'alpha', 'izin', 'sakit') DEFAULT 'alpha',
    alpha_menit INT DEFAULT 0,                -- akumulasi alpha dalam menit untuk sesi ini
    durasi_efektif_menit INT DEFAULT 0,       -- durasi kehadiran efektif
    
    -- Flags
    is_auto_closed BOOLEAN DEFAULT FALSE,     -- lupa checkout, auto-close
    is_offline_synced BOOLEAN DEFAULT FALSE,  -- absen offline lalu sync
    is_overridden BOOLEAN DEFAULT FALSE,      -- di-override manual oleh dosen
    overridden_by BIGINT UNSIGNED NULL,
    override_reason TEXT NULL,
    
    -- Approval (untuk status pending)
    approved_by BIGINT UNSIGNED NULL,         -- dosen yang approve
    approved_at TIMESTAMP NULL,
    approval_status ENUM('pending', 'approved', 'rejected') NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (jadwal_id) REFERENCES jadwals(id) ON DELETE CASCADE,
    FOREIGN KEY (mata_kuliah_id) REFERENCES mata_kuliahs(id) ON DELETE CASCADE,
    FOREIGN KEY (overridden_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_att_user (user_id),
    INDEX idx_att_jadwal (jadwal_id),
    INDEX idx_att_tanggal (tanggal),
    INDEX idx_att_status (status),
    INDEX idx_att_mk (mata_kuliah_id),
    UNIQUE KEY unique_attendance (user_id, jadwal_id, tanggal)
);
```

### 2.14 Tabel: `attendance_logs`
```sql
CREATE TABLE attendance_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attendance_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    action ENUM('checkin_attempt', 'checkin_success', 'checkin_failed',
                'checkout_attempt', 'checkout_success', 'checkout_failed',
                'liveness_passed', 'liveness_failed',
                'face_match', 'face_not_match',
                'geofence_valid', 'geofence_invalid',
                'mock_location_detected') NOT NULL,
    
    -- Detail data
    latitude DECIMAL(10, 8) NULL,
    longitude DECIMAL(11, 8) NULL,
    distance_to_geofence DECIMAL(8, 2) NULL,
    face_distance DECIMAL(10, 6) NULL,
    face_threshold DECIMAL(10, 6) NULL,
    liveness_challenge VARCHAR(50) NULL,      -- challenge yang diberikan
    inference_time_ms INT NULL,               -- waktu inferensi model (ms)
    
    -- Device info
    device_model VARCHAR(100) NULL,
    device_os VARCHAR(50) NULL,
    app_version VARCHAR(20) NULL,
    gps_accuracy DECIMAL(8, 2) NULL,          -- akurasi GPS (meter)
    
    -- Testing mode
    is_test_mode BOOLEAN DEFAULT FALSE,
    test_type ENUM('genuine', 'impostor') NULL,
    
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (attendance_id) REFERENCES attendances(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_log_user (user_id),
    INDEX idx_log_action (action),
    INDEX idx_log_created (created_at),
    INDEX idx_log_test (is_test_mode, test_type)
);
```

### 2.15 Tabel: `alpha_accumulations`
```sql
CREATE TABLE alpha_accumulations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    semester_id BIGINT UNSIGNED NOT NULL,
    total_alpha_menit INT DEFAULT 0,
    total_alpha_jam DECIMAL(8, 2) GENERATED ALWAYS AS (total_alpha_menit / 60.0) STORED,
    sp_status ENUM('aman', 'sp1', 'sp2', 'sp3', 'do') DEFAULT 'aman',
    last_calculated_at TIMESTAMP NULL,
    
    -- Notification flags (agar tidak kirim notifikasi berulang)
    notified_approaching_sp1 BOOLEAN DEFAULT FALSE,
    notified_sp1 BOOLEAN DEFAULT FALSE,
    notified_approaching_sp2 BOOLEAN DEFAULT FALSE,
    notified_sp2 BOOLEAN DEFAULT FALSE,
    notified_approaching_sp3 BOOLEAN DEFAULT FALSE,
    notified_sp3 BOOLEAN DEFAULT FALSE,
    notified_approaching_do BOOLEAN DEFAULT FALSE,
    notified_do BOOLEAN DEFAULT FALSE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_semester (user_id, semester_id),
    INDEX idx_alpha_status (sp_status)
);
```

### 2.16 Tabel: `sp_records`
```sql
CREATE TABLE sp_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,         -- mahasiswa
    semester_id BIGINT UNSIGNED NOT NULL,
    sp_level ENUM('sp1', 'sp2', 'sp3', 'do') NOT NULL,
    nomor_surat VARCHAR(50) NULL,             -- auto-generate
    tanggal_terbit DATE NULL,
    total_alpha_jam DECIMAL(8, 2) NOT NULL,   -- akumulasi saat SP diterbitkan
    
    -- Rincian per mata kuliah
    rincian_alpha JSON NULL,                  -- [{mk: "Matematika", alpha_jam: 5.5}, ...]
    
    -- Approval flow
    status ENUM('draft', 'menunggu_kaprodi', 'menunggu_kajur', 'final', 'dibatalkan') DEFAULT 'draft',
    generated_by BIGINT UNSIGNED NULL,        -- admin yang generate
    generated_at TIMESTAMP NULL,
    
    -- Tanda tangan Kaprodi
    signed_kaprodi_by BIGINT UNSIGNED NULL,
    signed_kaprodi_at TIMESTAMP NULL,
    
    -- Diketahui Ketua Jurusan
    signed_kajur_by BIGINT UNSIGNED NULL,
    signed_kajur_at TIMESTAMP NULL,
    
    -- Dokumen
    document_path VARCHAR(255) NULL,          -- path PDF final
    
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (signed_kaprodi_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (signed_kajur_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_sp_user (user_id),
    INDEX idx_sp_level (sp_level),
    INDEX idx_sp_status (status)
);
```

### 2.17 Tabel: `leave_requests` (Izin/Sakit)
```sql
CREATE TABLE leave_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    mata_kuliah_id BIGINT UNSIGNED NOT NULL,
    jenis ENUM('izin', 'sakit') NOT NULL,
    tanggal_mulai DATE NOT NULL,
    tanggal_selesai DATE NOT NULL,           -- bisa range untuk sakit berkepanjangan
    keterangan TEXT NULL,
    file_surat VARCHAR(255) NULL,            -- path file upload
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    approved_by BIGINT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    rejected_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (mata_kuliah_id) REFERENCES mata_kuliahs(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_leave_user (user_id),
    INDEX idx_leave_status (status)
);
```

> **Dedup di level aplikasi (bukan constraint DB).** Tidak ada unique constraint
> pada `(user_id, mata_kuliah_id, tanggal_*)`. `LeaveRequestController@store`
> menolak izin baru bila untuk `user_id`+`mata_kuliah_id` yang sama sudah ada baris
> `pending`/`approved` dengan rentang tanggal yang **beririsan** (`tanggal_mulai ≤
> selesai_baru` dan `tanggal_selesai ≥ mulai_baru`). Cek ini diulang di dalam lock
> transaksi (`lockForUpdate` pada baris `users`) agar submit paralel — termasuk
> fan-out multi-MK — tidak menghasilkan izin ganda. Detail kontrak API ada di
> [CURRENT-API.md](CURRENT-API.md#izinsakit-leave-request).

### 2.18 Tabel: `prodi_settings`
```sql
CREATE TABLE prodi_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    prodi_id BIGINT UNSIGNED NOT NULL,
    
    -- Toleransi waktu
    toleransi_masuk_menit INT DEFAULT 15,
    batas_terlambat_persen INT DEFAULT 50,    -- % dari durasi kuliah
    toleransi_pulang_menit INT DEFAULT 15,
    
    -- Threshold SP (dalam jam)
    sp1_jam_mulai INT DEFAULT 16,
    sp1_jam_akhir INT DEFAULT 31,
    sp2_jam_mulai INT DEFAULT 32,
    sp2_jam_akhir INT DEFAULT 37,
    sp3_jam_mulai INT DEFAULT 38,
    sp3_jam_akhir INT DEFAULT 45,
    do_jam_mulai INT DEFAULT 46,
    
    -- Face verification
    face_threshold DECIMAL(5, 3) DEFAULT 1.000,
    liveness_challenge_count INT DEFAULT 1,
    liveness_timeout_seconds INT DEFAULT 10,
    max_failed_attempts INT DEFAULT 5,
    
    -- Geofence
    default_radius_meter INT DEFAULT 50,
    gps_accuracy_minimum INT DEFAULT 20,
    allow_offline_attendance BOOLEAN DEFAULT TRUE,
    offline_sync_timeout_menit INT DEFAULT 30,
    
    -- Notifikasi
    sp_warning_percentage INT DEFAULT 80,    -- notif saat 80% threshold
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (prodi_id) REFERENCES prodis(id) ON DELETE CASCADE,
    UNIQUE KEY unique_prodi_setting (prodi_id)
);
```

### 2.19 Tabel: `notifications`
```sql
CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    type ENUM('sp_warning', 'sp_issued', 'approval_needed', 'approval_result',
              'enrollment_result', 'reminder', 'system') NOT NULL,
    data JSON NULL,                           -- additional data (link, ids, etc)
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP NULL,
    sent_via ENUM('push', 'in_app', 'both') DEFAULT 'both',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notif_user (user_id),
    INDEX idx_notif_read (is_read),
    INDEX idx_notif_type (type)
);
```

### 2.20 Tabel: `system_settings`
```sql
CREATE TABLE system_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL UNIQUE,
    value TEXT NULL,
    type ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string',
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Data awal:
-- test_mode: false
-- app_name: "Sistem Absensi Mahasiswa"
-- institution_name: "Politeknik Negeri Pontianak"
-- jurusan_name: "Teknik Elektro"
```

### 2.21 Tabel: `audit_trails`
```sql
CREATE TABLE audit_trails (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    model_type VARCHAR(100) NULL,             -- nama tabel/model
    model_id BIGINT UNSIGNED NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_model (model_type, model_id),
    INDEX idx_audit_created (created_at)
);
```

---

## 3. RELASI ANTAR TABEL (Summary)

| Tabel Asal | Relasi | Tabel Tujuan | Keterangan |
|------------|--------|--------------|------------|
| users | belongsToMany | roles | via user_roles |
| users | belongsTo | prodis | prodi_id |
| users | hasMany | face_embeddings | - |
| users | hasMany | attendances | - |
| users | hasMany | sp_records | - |
| users | hasMany | leave_requests | - |
| users | hasMany | notifications | - |
| tahun_ajarans | hasMany | semesters | - |
| semesters | hasMany | mata_kuliahs | - |
| prodis | hasMany | mata_kuliahs | - |
| prodis | hasOne | prodi_settings | - |
| mata_kuliahs | belongsTo | users (dosen) | dosen_id |
| mata_kuliahs | belongsToMany | users (mhs) | via mahasiswa_mata_kuliah |
| mata_kuliahs | hasMany | jadwals | - |
| jadwals | belongsTo | geofences | geofence_id |
| attendances | belongsTo | users | user_id |
| attendances | belongsTo | jadwals | jadwal_id |
| attendances | hasMany | attendance_logs | - |
| alpha_accumulations | belongsTo | users | user_id |
| alpha_accumulations | belongsTo | semesters | semester_id |
| sp_records | belongsTo | users | user_id, generated_by, signed_* |
| users (orang_tua) | belongsToMany | users (mahasiswa) | via parent_student |

---

## 4. SEEDER DATA (Data Awal untuk Development & Testing)

### 4.1 Konfigurasi Database

```
DB_DATABASE=absensi_mahasiswa_elektro
```

### 4.2 Password Default

Desain awal menggunakan password seragam, tetapi pola tersebut **dilarang dan
telah disupersede**. Implementasi current memakai random placeholder per akun,
status nonaktif, dan one-time activation melalui email terverifikasi.

### 4.3 Roles

| id | name | display_name |
|----|------|-------------|
| 1 | super_admin | Super Admin |
| 2 | ketua_jurusan | Ketua Jurusan |
| 3 | admin_jurusan | Admin Jurusan |
| 4 | kaprodi | Ketua Program Studi |
| 5 | admin_prodi | Admin Program Studi |
| 6 | dosen | Dosen |
| 7 | mahasiswa | Mahasiswa |
| 8 | orang_tua | Orang Tua/Wali |

### 4.4 Program Studi

| id | kode | nama | jenjang | jurusan |
|----|------|------|---------|---------|
| 1 | TL | Teknik Listrik | D3 | Teknik Elektro |
| 2 | TI | Teknik Informatika | D3 | Teknik Elektro |
| 3 | TE | Teknik Elektro | D3 | Teknik Elektro |

### 4.5 Users & Akun per Role

#### Super Admin (1 akun)

| Nama | Email | Role | Prodi |
|------|-------|------|-------|
| Administrator | administrator@gmail.com | super_admin | - (semua akses) |

#### Ketua Jurusan (1 akun)

| Nama | Email | Role | Prodi |
|------|-------|------|-------|
| Dr. Bambang Sutrisno, M.T. | ketua_jurusan@gmail.com | ketua_jurusan | - (level jurusan) |

#### Admin Jurusan (1 akun)

| Nama | Email | Role | Prodi |
|------|-------|------|-------|
| Siti Rahayu, S.Kom. | admin_jurusan@gmail.com | admin_jurusan | - (level jurusan) |

#### Ketua Prodi (3 akun — 1 per prodi)

| Nama | Email | Role | Prodi |
|------|-------|------|-------|
| Dr. Hendra Wijaya, M.T. | kaprodi_elektro@gmail.com | kaprodi | Teknik Elektro |
| Dr. Andi Prasetyo, M.Kom. | kaprodi_informatika@gmail.com | kaprodi | Teknik Informatika |
| Dr. Budi Santoso, M.T. | kaprodi_listrik@gmail.com | kaprodi | Teknik Listrik |

#### Admin Prodi (3 akun — 1 per prodi)

| Nama | Email | Role | Prodi |
|------|-------|------|-------|
| Rina Wati, S.T. | admin_prodi_elektro@gmail.com | admin_prodi | Teknik Elektro |
| Dewi Lestari, S.Kom. | admin_prodi_informatika@gmail.com | admin_prodi | Teknik Informatika |
| Agus Setiawan, S.T. | admin_prodi_listrik@gmail.com | admin_prodi | Teknik Listrik |

#### Dosen (5 akun contoh — prodi Teknik Informatika)

| Nama | Email | NIDN | Prodi | Pendidikan | Bidang Keahlian |
|------|-------|------|-------|------------|-----------------|
| Yusril Eka Mahendra, M.Kom. | dosen_yusril@gmail.com | 1234567890 | Teknik Informatika | S2 Teknik Informatika | Mobile Development, AI |
| Adam Kurniawan, M.Kom. | dosen_adam@gmail.com | 1234567891 | Teknik Informatika | S2 Teknik Informatika | Web Development, Cloud Computing |
| Fitri Handayani, M.Kom. | dosen_fitri@gmail.com | 1234567892 | Teknik Informatika | S2 Teknik Informatika | Data Science, Machine Learning |
| Rudi Hartono, M.T. | dosen_rudi@gmail.com | 1234567893 | Teknik Informatika | S2 Teknik Elektro | IoT, Embedded Systems |
| Sari Indah, M.Kom. | dosen_sari@gmail.com | 1234567894 | Teknik Informatika | S2 Teknik Informatika | Software Engineering, UI/UX |

#### Dosen (2 akun contoh — prodi Teknik Elektro)

| Nama | Email | NIDN | Prodi | Pendidikan | Bidang Keahlian |
|------|-------|------|-------|------------|-----------------|
| Wahyu Pratama, M.T. | dosen_wahyu@gmail.com | 2234567890 | Teknik Elektro | S2 Teknik Elektro | Power Electronics |
| Dian Permata, M.T. | dosen_dian@gmail.com | 2234567891 | Teknik Elektro | S2 Teknik Elektro | Control Systems |

#### Dosen (2 akun contoh — prodi Teknik Listrik)

| Nama | Email | NIDN | Prodi | Pendidikan | Bidang Keahlian |
|------|-------|------|-------|------------|-----------------|
| Joko Susilo, M.T. | dosen_joko@gmail.com | 3234567890 | Teknik Listrik | S2 Teknik Elektro | Instalasi Listrik |
| Mega Putri, M.T. | dosen_mega@gmail.com | 3234567891 | Teknik Listrik | S2 Teknik Elektro | Energi Terbarukan |

#### Mahasiswa (contoh 15 akun — prodi Teknik Informatika, angkatan 2024, semester 4)

| Nama | Email | NIM | Prodi | Kelas | Angkatan | Semester |
|------|-------|-----|-------|-------|----------|----------|
| Ahmad Fauzi | mahasiswa_ahmad@gmail.com | 2024001001 | TI | B | 2024 | 4 |
| Budi Prasetyo | mahasiswa_budi@gmail.com | 2024001002 | TI | B | 2024 | 4 |
| Citra Dewi | mahasiswa_citra@gmail.com | 2024001003 | TI | B | 2024 | 4 |
| Dani Saputra | mahasiswa_dani@gmail.com | 2024001004 | TI | A | 2024 | 4 |
| Eka Putri | mahasiswa_eka@gmail.com | 2024001005 | TI | A | 2024 | 4 |
| Fajar Ramadhan | mahasiswa_fajar@gmail.com | 2024001006 | TI | A | 2024 | 4 |
| Gita Lestari | mahasiswa_gita@gmail.com | 2024001007 | TI | C | 2024 | 4 |
| Hadi Wijaya | mahasiswa_hadi@gmail.com | 2024001008 | TI | C | 2024 | 4 |
| Indra Kusuma | mahasiswa_indra@gmail.com | 2024001009 | TI | C | 2024 | 4 |
| Jihan Aulia | mahasiswa_jihan@gmail.com | 2024001010 | TI | D | 2024 | 4 |
| Kiki Amelia | mahasiswa_kiki@gmail.com | 2024001011 | TI | D | 2024 | 4 |
| Lukman Hakim | mahasiswa_lukman@gmail.com | 2024001012 | TI | D | 2024 | 4 |
| Mira Susanti | mahasiswa_mira@gmail.com | 2024001013 | TI | E | 2024 | 4 |
| Nanda Pratama | mahasiswa_nanda@gmail.com | 2024001014 | TI | E | 2024 | 4 |
| Oki Firmansyah | mahasiswa_oki@gmail.com | 2024001015 | TI | E | 2024 | 4 |

#### Orang Tua/Wali (contoh 3 akun — terhubung ke mahasiswa)

| Nama | Email | Role | Anak (mahasiswa) |
|------|-------|------|-----------------|
| Pak Fauzi (Ayah Ahmad) | orangtua_fauzi@gmail.com | orang_tua | Ahmad Fauzi |
| Bu Prasetyo (Ibu Budi) | orangtua_prasetyo@gmail.com | orang_tua | Budi Prasetyo |
| Pak Saputra (Ayah Dani) | orangtua_saputra@gmail.com | orang_tua | Dani Saputra |

### 4.6 Tahun Ajaran & Semester

| Tahun Ajaran | Semester | Periode | Status |
|-------------|----------|---------|--------|
| 2025/2026 | Genap | Januari 2026 - Juni 2026 | aktif |
| 2025/2026 | Ganjil | Juli 2025 - Desember 2025 | nonaktif |

### 4.7 Mata Kuliah (Contoh: Pemrograman Mobile — Semester 4 TI)

| kode_mk | nama | sks | semester_id | prodi | dosen | kelas |
|---------|------|-----|-------------|-------|-------|-------|
| TI-401 | Pemrograman Mobile | 3 | Genap 2025/2026 | TI | Yusril | B |
| TI-401 | Pemrograman Mobile | 3 | Genap 2025/2026 | TI | Adam | A |
| TI-401 | Pemrograman Mobile | 3 | Genap 2025/2026 | TI | Adam | C |
| TI-401 | Pemrograman Mobile | 3 | Genap 2025/2026 | TI | Fitri | D |
| TI-401 | Pemrograman Mobile | 3 | Genap 2025/2026 | TI | Fitri | E |

### 4.8 Geofences (Contoh Lokasi Ruangan)

| nama | latitude | longitude | radius | gedung | lantai | prodi |
|------|----------|-----------|--------|--------|--------|-------|
| Lab Komputer 1 | -0.023400 | 109.345600 | 50 | Gedung A | 2 | TI |
| Lab Komputer 2 | -0.023500 | 109.346000 | 50 | Gedung A | 2 | TI |
| Lab Komputer 3 | -0.024000 | 109.347000 | 50 | Gedung B | 1 | TI |
| Lab Komputer 4 | -0.024500 | 109.348000 | 50 | Gedung B | 2 | TI |
| Lab Komputer 5 | -0.025000 | 109.349000 | 50 | Gedung C | 1 | TI |
| Ruang Teori 1 | -0.023600 | 109.345800 | 50 | Gedung A | 3 | TE |
| Ruang Teori 2 | -0.023700 | 109.346200 | 50 | Gedung A | 3 | TL |

### 4.9 Jadwal (Contoh: Pemrograman Mobile)

| mata_kuliah (dosen-kelas) | geofence | hari | jam_mulai | jam_selesai | ruangan |
|--------------------------|----------|------|-----------|-------------|---------|
| TI-401 Yusril - Kelas B | Lab Komputer 1 | Senin | 08:00 | 10:30 | Lab Komputer 1 |
| TI-401 Adam - Kelas A | Lab Komputer 2 | Senin | 08:00 | 10:30 | Lab Komputer 2 |
| TI-401 Adam - Kelas C | Lab Komputer 3 | Selasa | 13:00 | 15:30 | Lab Komputer 3 |
| TI-401 Fitri - Kelas D | Lab Komputer 4 | Rabu | 08:00 | 10:30 | Lab Komputer 4 |
| TI-401 Fitri - Kelas E | Lab Komputer 5 | Rabu | 13:00 | 15:30 | Lab Komputer 5 |

### 4.10 Mahasiswa ↔ Mata Kuliah (Enrollment MK)

| Mahasiswa | Mata Kuliah | Kelas |
|-----------|-------------|-------|
| Ahmad, Budi, Citra (kelas B) | TI-401 Pemrograman Mobile (Yusril) | B |
| Dani, Eka, Fajar (kelas A) | TI-401 Pemrograman Mobile (Adam) | A |
| Gita, Hadi, Indra (kelas C) | TI-401 Pemrograman Mobile (Adam) | C |
| Jihan, Kiki, Lukman (kelas D) | TI-401 Pemrograman Mobile (Fitri) | D |
| Mira, Nanda, Oki (kelas E) | TI-401 Pemrograman Mobile (Fitri) | E |

### 4.11 Prodi Settings

| Prodi | toleransi_masuk | batas_terlambat_persen | alpha_sp1 | alpha_sp2 | alpha_sp3 | face_threshold |
|-------|----------------|----------------------|-----------|-----------|-----------|----------------|
| Teknik Informatika | 15 menit | 50% | 4 | 8 | 12 | 1.0 |
| Teknik Elektro | 15 menit | 50% | 4 | 8 | 12 | 1.0 |
| Teknik Listrik | 15 menit | 50% | 4 | 8 | 12 | 1.0 |

### 4.12 Catatan Penting Seeder

1. **Credential akun**: tidak ada password universal; ikuti activation flow pada `SECURITY.md`.
2. **must_change_password**: `true` untuk semua akun (user wajib ganti password saat login pertama)
3. **enrollment_status mahasiswa**: `belum` (mahasiswa harus enrollment wajah setelah login pertama)
4. **Koordinat geofence**: Contoh menggunakan area Pontianak — sesuaikan dengan lokasi kampus sebenarnya
5. **Yang input data dosen & jadwal**: Admin Prodi dan Kaprodi masing-masing prodi
6. **Yang input data mahasiswa**: Admin Prodi masing-masing prodi (atau import dari SIAKAD)
7. **Orang tua**: Ditambahkan oleh Admin Prodi, terhubung ke mahasiswa via tabel `parent_student`

### 4.13 Tabel: `parent_student` (Relasi Orang Tua ↔ Mahasiswa)

```sql
CREATE TABLE parent_student (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NOT NULL,    -- user dengan role orang_tua
    student_id BIGINT UNSIGNED NOT NULL,   -- user dengan role mahasiswa
    hubungan ENUM('ayah', 'ibu', 'wali') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_parent_student (parent_id, student_id)
);
```
