# PRD-05: FLOW DIAGRAM (Alur Proses)

> **Catatan current:** diagram attendance langsung di bawah adalah desain awal.
> Flow authoritative selalu dimulai dengan attendance permit dan invariant server
> pada [CURRENT-ARCHITECTURE.md](CURRENT-ARCHITECTURE.md) dan
> [CURRENT-API.md](CURRENT-API.md). Offline mode memerlukan permit valid. Diagram
> biometric flow hanya berlaku untuk compatibility/non-production; production
> berhenti fail-closed. Trusted verifier (C-04/H-04) di luar scope penelitian
> ([ADR-001](ADR-001-trusted-biometric-verifier.md) ditolak).

## 1. FLOW UTAMA: PROSES ABSENSI (CHECK-IN)

```
┌─────────────────────────────────────────────────────────────────────┐
│                    MAHASISWA BUKA APLIKASI                            │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│              CEK: Apakah sudah login?                                 │
│              CEK: Apakah enrollment sudah approved?                   │
└──────┬──────────────────────────────────────────────┬───────────────┘
       │ YA                                           │ TIDAK
       ▼                                              ▼
┌──────────────┐                          ┌───────────────────────┐
│ Tampilkan    │                          │ Redirect ke Login /   │
│ Jadwal Hari  │                          │ Enrollment            │
│ Ini          │                          └───────────────────────┘
└──────┬───────┘
       │
       ▼
┌─────────────────────────────────────────────────────────────────────┐
│         MAHASISWA PILIH MATA KULIAH & TEKAN "CHECK-IN"               │
│         (Hanya MK yang sedang berlangsung yang bisa dipilih)         │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│                 STEP 1: VALIDASI LOKASI                               │
├─────────────────────────────────────────────────────────────────────┤
│ 1. Ambil koordinat GPS (latitude, longitude)                         │
│ 2. Cek akurasi GPS >= minimum (default 20m)                         │
│ 3. Cek mock location (safe_device)                                   │
│    ├── Terdeteksi fake GPS ──► TOLAK + Log anomaly                  │
│    └── Tidak terdeteksi ──► Lanjut                                  │
│ 4. Hitung jarak ke geofence (Geolocator.distanceBetween)            │
│ 5. Bandingkan dengan radius                                          │
│    ├── Jarak > radius ──► TOLAK "Di luar area perkuliahan"          │
│    └── Jarak <= radius ──► LANJUT KE STEP 2                        │
└─────────────────────┬───────────────────────────────────────────────┘
                      │ VALID
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│                 STEP 2: LIVENESS DETECTION                            │
├─────────────────────────────────────────────────────────────────────┤
│ ** Kamera: ResolutionPreset.high (720p) — kualitas visual bagus **   │
│                                                                      │
│ 1. Aktifkan kamera depan (720p preview)                              │
│ 2. Deteksi wajah (ML Kit) dari stream 720p                          │
│    ├── Tidak ada wajah ──► "Wajah tidak terdeteksi"                 │
│    ├── Lebih dari 1 wajah ──► "Hanya 1 wajah yang diizinkan"       │
│    └── 1 wajah terdeteksi ──► Lanjut                                │
│ 3. Cek masker (landmark mulut)                                       │
│    ├── Pakai masker ──► "Silakan lepas masker"                      │
│    └── Tidak pakai ──► Lanjut                                       │
│ 4. Cek kacamata hitam (eye open probability)                         │
│    ├── Mata tidak terdeteksi ──► "Silakan lepas kacamata hitam"     │
│    └── Mata terdeteksi ──► Lanjut                                   │
│ 5. Pilih 1 challenge random:                                         │
│    - "Senyum" (smilingProbability > 0.7)                            │
│    - "Toleh kiri" (headEulerAngleY < -20°)                          │
│    - "Toleh kanan" (headEulerAngleY > 20°)                          │
│    - "Kedipkan mata" (eyeOpenProb < 0.3 lalu > 0.7)                │
│    - "Anggukkan kepala" (headEulerAngleX change > 15°)              │
│ 6. Tampilkan instruksi ke user                                       │
│ 7. Timeout: 10 detik                                                 │
│    ├── Gagal/timeout ──► "Liveness gagal. Coba lagi." (log)         │
│    └── Berhasil ──► LANJUT KE STEP 3                                │
└─────────────────────┬───────────────────────────────────────────────┘
                      │ PASSED
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│                 STEP 3: FACE VERIFICATION                             │
├─────────────────────────────────────────────────────────────────────┤
│ ** Kamera: preview 720p (ResolutionPreset.high) — kualitas bagus **  │
│                                                                      │
│ 1. Ambil frame wajah dari stream 720p (proses liveness)              │
│ 2. Crop wajah (bounding box dari ML Kit)                            │
│ 3. Resize ke 112 x 112 piksel                                       │
│    (resolusi asal tidak berpengaruh — target hanya 112x112)         │
│ 4. Normalisasi: x_norm = (x - 127.5) / 127.5                       │
│ 5. Jalankan MobileFaceNet TFLite (di memori HP)                      │
│    - Input: [1, 112, 112, 3] (batch, height, width, channels)       │
│    - Output: [1, 192] (embedding vector)                            │
│    - Catat: t_mulai, t_selesai (untuk latensi)                      │
│ 6. Ambil embedding referensi (dari cache lokal / fetch server)       │
│ 7. Hitung Euclidean Distance:                                        │
│    d = sqrt(sum((e_i - t_i)^2)) untuk i = 1..192                   │
│ 8. Bandingkan dengan threshold (dari setting prodi)                  │
│    ├── d >= threshold ──► "Verifikasi wajah gagal" (log)            │
│    │   Button check-in DISABLE                                       │
│    │   User bisa coba lagi                                           │
│    └── d < threshold ──► MATCH! LANJUT KE STEP 4                    │
│                                                                      │
│ ** PENTING: Setelah proses selesai **                                │
│ - Frame wajah DIBUANG dari memori (tidak disimpan)                   │
│ - Embedding sementara DIBUANG (tidak disimpan)                       │
│ - Yang dikirim ke backend HANYA: distance, threshold, result,        │
│   inference_time, device_info (angka saja, BUKAN foto/embedding)    │
└─────────────────────┬───────────────────────────────────────────────┘
                      │ MATCH
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│                 STEP 4: TENTUKAN STATUS & SIMPAN                     │
├─────────────────────────────────────────────────────────────────────┤
│ Ambil waktu sekarang (T_now) dan jam mulai MK (T_start)             │
│ Ambil setting: toleransi_masuk, batas_terlambat_persen              │
│                                                                      │
│ CASE A: T_now <= T_start + toleransi_masuk                          │
│   → Status: HADIR                                                    │
│   → alpha_menit: 0                                                   │
│                                                                      │
│ CASE B: T_now > T_start + toleransi_masuk                           │
│         AND T_now <= T_start + (durasi * batas_terlambat_persen/100) │
│   → Status: HADIR_TERLAMBAT                                         │
│   → alpha_menit: (T_now - T_start) dalam menit                     │
│                                                                      │
│ CASE C: T_now > T_start + (durasi * batas_terlambat_persen/100)     │
│   → Status: PENDING                                                  │
│   → alpha_menit: sementara (T_now - T_start), final setelah dosen   │
│   → Kirim notifikasi ke dosen                                        │
│                                                                      │
│ Kirim data ke backend API (POST /attendance/check-in)                │
│ Tampilkan: "Check-in berhasil - [STATUS]"                           │
│ Update akumulasi alpha di backend                                    │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 2. FLOW: PROSES CHECK-OUT

```
┌─────────────────────────────────────────────────────────────────────┐
│              MAHASISWA TEKAN "CHECK-OUT"                              │
│              (Pada MK yang sudah check-in)                           │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ STEP 1: VALIDASI LOKASI (sama seperti check-in)                      │
│ STEP 2: LIVENESS DETECTION (1 challenge random)                      │
│ STEP 3: FACE VERIFICATION (embedding match)                          │
└─────────────────────┬───────────────────────────────────────────────┘
                      │ SEMUA VALID
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│                 TENTUKAN STATUS CHECK-OUT                             │
├─────────────────────────────────────────────────────────────────────┤
│ Ambil waktu sekarang (T_now) dan jam selesai MK (T_end)             │
│ Ambil setting: toleransi_pulang                                      │
│                                                                      │
│ CASE A: T_now >= T_end - toleransi_pulang                           │
│   → Check-out NORMAL                                                 │
│   → alpha_menit tambahan: 0                                         │
│                                                                      │
│ CASE B: T_now < T_end - toleransi_pulang                            │
│   → PULANG AWAL                                                      │
│   → alpha_menit tambahan: (T_end - T_now) dalam menit               │
│                                                                      │
│ CASE C: T_now > T_end + toleransi_pulang                            │
│   → TERLAMBAT CHECK-OUT (masih bisa, waktu dicatat = T_end)         │
│   → alpha_menit tambahan: 0                                         │
│                                                                      │
│ Hitung durasi_efektif = T_checkout - T_checkin                       │
│ Update total alpha_menit = alpha_checkin + alpha_checkout             │
│ Kirim data ke backend (POST /attendance/check-out)                   │
│ Tampilkan: "Check-out berhasil. Durasi: X jam Y menit"              │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 3. FLOW: AUTO-CLOSE (Backend Scheduler)

```
┌─────────────────────────────────────────────────────────────────────┐
│         SCHEDULER: Setiap menit cek jadwal yang sudah selesai        │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ Query: Semua attendance yang:                                        │
│   - checkin_time IS NOT NULL                                         │
│   - checkout_time IS NULL                                            │
│   - jadwal.jam_selesai + toleransi_pulang < NOW()                   │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ Untuk setiap record:                                                 │
│   - Set checkout_time = jadwal.jam_selesai                          │
│   - Set is_auto_closed = true                                        │
│   - Hitung durasi_efektif                                            │
│   - Tidak ada alpha tambahan (benefit of the doubt)                  │
│   - Log: "Auto-closed attendance #{id}"                             │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 4. FLOW: ALPHA PENUH (Tidak Hadir)

```
┌─────────────────────────────────────────────────────────────────────┐
│  SCHEDULER: Setiap menit, hanya proses jadwal yang jam_selesai +     │
│             toleransi_pulang sudah lewat (ALPHA muncul segera        │
│             setelah kelas selesai, bukan menunggu akhir hari)        │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ Query: Semua mahasiswa yang terdaftar di MK pada jadwal tsb          │
│        TAPI tidak punya record attendance untuk hari ini             │
│        DAN tidak punya leave_request approved untuk hari ini         │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ Untuk setiap mahasiswa yang tidak hadir:                             │
│   - Create attendance record dengan status = ALPHA                   │
│   - alpha_menit = durasi_matakuliah (jam_selesai - jam_mulai)       │
│   - Update alpha_accumulations                                       │
│   - Cek apakah masuk threshold SP baru                              │
│     ├── YA ──► Trigger notifikasi SP                                │
│     └── TIDAK ──► Cek apakah mendekati threshold (80%)              │
│                   ├── YA ──► Kirim warning notification              │
│                   └── TIDAK ──► Selesai                              │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 5. FLOW: EARLY WARNING & SP

```
┌─────────────────────────────────────────────────────────────────────┐
│          SETIAP KALI ALPHA_ACCUMULATION BERUBAH                      │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ Hitung total_alpha_jam = total_alpha_menit / 60                      │
│ Ambil setting SP dari prodi_settings                                 │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ EVALUASI STATUS:                                                     │
│                                                                      │
│ total_alpha_jam < 16                    → AMAN                       │
│ total_alpha_jam >= 16 AND <= 31         → SP1                        │
│ total_alpha_jam >= 32 AND <= 37         → SP2                        │
│ total_alpha_jam >= 38 AND <= 45         → SP3                        │
│ total_alpha_jam >= 46                   → DO                         │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ APAKAH STATUS BERUBAH DARI SEBELUMNYA?                               │
├──────────┬──────────────────────────────────────────────────────────┤
│ TIDAK    │ Cek warning: apakah mendekati threshold berikutnya?       │
│          │ (80% dari batas SP berikutnya)                            │
│          │ ├── YA ──► Kirim notifikasi sesuai level:                 │
│          │ │   • Mendekati SP1: push ke mahasiswa                    │
│          │ │   • Mendekati SP2: push mahasiswa + in-app admin prodi  │
│          │ │   • Mendekati SP3: push mhs + in-app admin + kaprodi   │
│          │ │   • Mendekati DO:  push mhs + in-app admin + kaprodi   │
│          │ │                    + in-app ketua jurusan (URGENT)      │
│          │ └── TIDAK ──► Selesai                                     │
├──────────┼──────────────────────────────────────────────────────────┤
│ YA       │ 1. Update sp_status di alpha_accumulations                │
│          │ 2. Kirim notifikasi sesuai level SP baru:                 │
│          │    • SP1: push mhs + in-app admin prodi + kaprodi         │
│          │           + in-app dosen pengampu MK terkait              │
│          │    • SP2: push mhs + in-app admin prodi + kaprodi         │
│          │           + in-app ketua jurusan                          │
│          │    • SP3: push mhs + in-app admin prodi + kaprodi         │
│          │           + in-app ketua jurusan + admin jurusan          │
│          │    • DO:  push mhs + in-app admin prodi + kaprodi         │
│          │           + in-app ketua jurusan + admin jurusan (URGENT) │
│          │ 3. Highlight di dashboard                                  │
│          │ 4. Admin bisa generate dokumen SP                         │
└──────────┴──────────────────────────────────────────────────────────┘
```

---

## 6. FLOW: GENERATE & APPROVAL DOKUMEN SP

```
┌─────────────────────────────────────────────────────────────────────┐
│     ADMIN PRODI KLIK "GENERATE SP" PADA MAHASISWA                    │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ Sistem generate draft dokumen SP:                                    │
│ - Ambil data mahasiswa (nama, NIM, prodi, kelas)                    │
│ - Ambil total akumulasi alpha                                        │
│ - Ambil rincian per mata kuliah                                      │
│ - Generate nomor surat otomatis                                      │
│ - Status: DRAFT                                                      │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ Admin review draft → Kirim ke Kaprodi                                │
│ Status: MENUNGGU_KAPRODI                                             │
│ Notifikasi ke Kaprodi                                                │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ KAPRODI review → Tanda tangan digital → Approve                     │
│ Status: MENUNGGU_KAJUR                                               │
│ Notifikasi ke Ketua Jurusan                                          │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ KETUA JURUSAN review → Tanda tangan "Diketahui" → Approve           │
│ Status: FINAL                                                        │
│ Generate PDF final dengan kedua tanda tangan                         │
│ Notifikasi ke mahasiswa (bisa download PDF)                          │
│ Notifikasi ke Admin Prodi (SP sudah final)                          │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 7. FLOW: ENROLLMENT WAJAH

```
┌─────────────────────────────────────────────────────────────────────┐
│         MAHASISWA LOGIN PERTAMA KALI                                  │
│         (Setelah ganti password default)                             │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ Tampilkan halaman enrollment wajah                                   │
│ Instruksi:                                                           │
│ - Pastikan pencahayaan cukup                                         │
│ - Lepas masker dan kacamata hitam                                    │
│ - Posisikan wajah di dalam frame                                     │
│ - Ikuti instruksi yang muncul                                        │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 1. Aktifkan kamera depan (ResolutionPreset.high — 720p)              │
│    → Preview kualitas bagus, tidak buram                             │
│ 2. Real-time face detection (ML Kit) dari stream 720p                │
│ 3. Validasi:                                                         │
│    - 1 wajah terdeteksi ✓                                           │
│    - Wajah menghadap depan (euler angle ~0) ✓                       │
│    - Tidak pakai masker ✓                                            │
│    - Mata terdeteksi (tidak pakai kacamata hitam) ✓                 │
│    - Pencahayaan cukup ✓                                            │
│ 4. Liveness challenge (1 random)                                     │
│ 5. Jika liveness passed:                                             │
│    a. FOTO ENROLLMENT:                                               │
│       - controller.takePicture() → resolusi penuh HP                │
│       - Compress JPG quality 85% (~100-200KB)                       │
│       - Foto ini untuk biodata/identitas visual                      │
│    b. EMBEDDING:                                                     │
│       - Ambil frame dari stream 720p                                │
│       - Crop wajah (bounding box ML Kit)                            │
│       - Resize 112x112, normalisasi                                 │
│       - MobileFaceNet → embedding [192]                             │
│       - (Resolusi asal tidak berpengaruh — target hanya 112x112)    │
│    c. Kirim embedding + foto ke backend (multipart)                  │
│    d. Backend simpan foto di storage (users.foto_enrollment)         │
│    e. Status: PENDING                                                │
│    f. Tampilkan: "Enrollment berhasil. Menunggu approval admin."    │
└─────────────────────────────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ ADMIN PRODI melihat enrollment pending di dashboard                  │
│ → Lihat foto enrollment + data mahasiswa                             │
│ → Approve / Reject                                                   │
│ → Notifikasi ke mahasiswa                                            │
│ → Jika approved: mahasiswa bisa mulai absensi                       │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 8. FLOW: IZIN/SAKIT

```
┌─────────────────────────────────────────────────────────────────────┐
│ MAHASISWA submit izin/sakit via mobile app                           │
│ - Pilih jenis (izin/sakit)                                           │
│ - Pilih mata kuliah, ATAU aktifkan "Berlaku untuk semua MK"          │
│ - Pilih tanggal (range)                                              │
│ - Upload surat (foto/scan)                                           │
│ - Keterangan (opsional)                                              │
│ Status: PENDING                                                      │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
        ┌─────────────┴───────────────┐
        │                             │
        ▼                             ▼
┌───────────────────┐   ┌─────────────────────────────────────────────┐
│ Single MK         │   │ Semua/Beberapa MK (fan-out)                  │
│ - 1 LeaveRequest  │   │ - `all_mata_kuliah=true` atau `mata_kuliah_  │
│   untuk MK itu    │   │   ids[]`                                     │
│                   │   │ - Backend buat 1 LeaveRequest per MK enrolled│
│                   │   │   yang punya jadwal aktif pada rentang       │
│                   │   │ - MK tanpa jadwal / sudah ada izin aktif     │
│                   │   │   dilewati (dilaporkan di `skipped`)         │
│                   │   │ - Semua baris dibuat dalam satu transaksi;   │
│                   │   │   model tetap per-MK (alpha/SP tak berubah)  │
└─────────┬─────────┘   └─────────────────────┬───────────────────────┘
          └───────────────┬───────────────────┘
                          ▼
┌─────────────────────────────────────────────────────────────────────┐
│ Notifikasi ke Dosen pengampu MK (per baris LeaveRequest)             │
│ Dosen review di web/mobile:                                          │
│ - Lihat surat yang diupload                                         │
│ - Approve → status attendance berubah ke IZIN/SAKIT, alpha = 0      │
│ - Reject → status tetap ALPHA + alasan penolakan                    │
│ Notifikasi ke mahasiswa                                              │
│ (setiap baris di-approve/reject independen per MK)                   │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 9. FLOW: DOSEN OVERRIDE MANUAL

```
┌─────────────────────────────────────────────────────────────────────┐
│ DOSEN pilih mahasiswa yang ingin di-override                         │
│ (Use case: HP mahasiswa rusak, tapi hadir di kelas)                 │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 1. Pilih mahasiswa                                                   │
│ 2. Pilih mata kuliah + tanggal                                       │
│ 3. Set status: HADIR / ALPHA / IZIN                                 │
│ 4. Wajib isi alasan (text)                                          │
│ 5. Submit                                                            │
│                                                                      │
│ Sistem:                                                              │
│ - Update/create attendance record                                    │
│ - Set is_overridden = true                                           │
│ - Set overridden_by = dosen_id                                       │
│ - Recalculate alpha_accumulation                                     │
│ - Log ke audit_trail                                                 │
│ - Notifikasi ke admin prodi (monitoring)                            │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 10. FLOW: OFFLINE ATTENDANCE SYNC

```
┌─────────────────────────────────────────────────────────────────────┐
│ MAHASISWA absen saat OFFLINE                                         │
│ (Semua validasi dilakukan on-device)                                 │
└─────────────────────┬───────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 1. Geofence validation (koordinat geofence di-cache lokal)           │
│ 2. Liveness detection (on-device, ML Kit)                            │
│ 3. Face verification (on-device, MobileFaceNet + cached embedding)   │
│ 4. Semua data disimpan di local queue:                               │
│    - timestamp, koordinat, distance, face_distance                   │
│    - liveness_result, device_info                                    │
│ 5. Tampilkan: "Absensi tersimpan offline. Akan sync saat online."   │
└─────────────────────┬───────────────────────────────────────────────┘
                      │ Saat koneksi kembali
                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│ AUTO-SYNC ke backend:                                                │
│ - POST /attendance/sync-offline                                      │
│ - Backend validasi: timestamp masih dalam range jadwal?              │
│   ├── YA ──► Simpan sebagai attendance valid (flag: offline_synced)  │
│   └── TIDAK ──► Reject (timestamp di luar jadwal)                   │
│ - Batas sync: dalam 30 menit setelah jadwal selesai                 │
│ - Notifikasi ke mahasiswa: "Sync berhasil/gagal"                    │
└─────────────────────────────────────────────────────────────────────┘
```
