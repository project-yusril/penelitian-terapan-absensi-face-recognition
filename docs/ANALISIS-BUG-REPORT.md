# LAPORAN ANALISIS & AUDIT SISTEM ABSENSI MAHASISWA

> **ARSIP HISTORIS.** Analisis ini merekam kondisi 16 Juni 2026 dan ID/statusnya
> telah disupersede oleh [temuan.md](temuan.md). Jangan gunakan payload, route,
> credential, atau status di dokumen ini sebagai kontrak current.

**Project:** Rancang Bangun Sistem Absensi Mahasiswa Berbasis Mobile Menggunakan Geolocation dan Face Recognition (MobileFaceNet) — DIII Teknik Informatika, Jurusan Teknik Elektro, Politeknik Negeri Pontianak

**Tanggal Analisis:** 16 Juni 2026
**Cakupan:** Backend (Laravel 11) · Frontend (Flutter) · Dokumentasi (PRD)
**Status:** Hasil audit awal — temuan diurutkan berdasarkan severity untuk diselesaikan satu per satu.

---

## 1. RINGKASAN EKSEKUTIF

Arsitektur sistem sudah cukup matang: backend memakai Laravel 11 dengan Sanctum, struktur controller per-role (Admin, Kaprodi, Kajur, Dosen, Mahasiswa, Orang Tua), service untuk akumulasi alpha & deteksi SP, dan frontend Flutter dengan Clean Architecture (data/domain/presentation) + face recognition on-device (MobileFaceNet TFLite) + geofencing + offline queue.

Namun, ditemukan **beberapa bug CRITICAL yang membuat alur utama (absensi check-in/check-out online) tidak berfungsi sama sekali**, ditambah sejumlah ketidaksesuaian kontrak API antara frontend ↔ backend, dan bug logika pada perhitungan alpha untuk SP.

### Statistik Temuan
| Severity | Jumlah |
|----------|--------|
| 🔴 CRITICAL | 5 |
| 🟠 HIGH | 6 |
| 🟡 MEDIUM | 7 |
| 🔵 LOW / Docs | 6 |
| 🟣 RESEARCH-CRITICAL (lihat Bagian 8) | 10 |

> **Update (revisi setelah membaca `penelitian.pdf`):** Ditambahkan **Bagian 8 — Kesesuaian dengan Proposal Penelitian**. Bagian ini memuat temuan yang **menggugurkan klaim/novelty penelitian** atau **membuat pengujian evaluasi (FAR/FRR, latensi, uji simultan, perbandingan konvensional) mustahil menghasilkan data**. Ini WAJIB dibaca karena berdampak langsung ke artikel ilmiah & laporan penelitian, bukan sekadar bug aplikasi.

### Top 5 yang WAJIB diselesaikan lebih dulu
1. **[C-01]** Face verification saat check-in SELALU gagal (image bytes kosong dikirim ke MobileFaceNet).
2. **[C-02]** Absensi ONLINE tidak pernah dikirim ke server (hasil halaman absensi hanya di-`pop`, tidak ada yang mem-POST).
3. **[C-03]** Mismatch field `is_mock_location` (backend) vs `mock_location_detected` (frontend) → request check-in selalu 422.
4. **[C-04]** Alur offline sync salah endpoint & salah bentuk payload → sinkronisasi offline gagal.
5. **[H-01]** Alpha "pulang awal" untuk mahasiswa berstatus `hadir` tidak ikut terhitung ke SP (bug logika SP).

---

## 2. TEMUAN CRITICAL 🔴

### [C-01] Face verification saat check-in selalu gagal — bytes gambar kosong
**File:** `frontend/lib/features/attendance/presentation/pages/attendance_page.dart` (baris 262-267)
**Juga:** `frontend/lib/features/face_recognition/domain/services/face_recognition_service.dart` (baris 25-26)

```dart
// attendance_page.dart
final result = await _faceService.verifyFace(
  Uint8List(0), // We'll use the live frame   <-- BYTES KOSONG!
  face,
  refEmbedding,
  threshold,
);
```
Di dalam `FaceRecognitionService.generateEmbedding()`:
```dart
final image = img.decodeImage(imageBytes); // imageBytes = Uint8List(0)
if (image == null) throw Exception('Failed to decode image'); // SELALU ke sini
```

**Dampak:** Karena `Uint8List(0)` (kosong) dikirim, `img.decodeImage` mengembalikan `null` dan langsung melempar exception. Verifikasi wajah pada saat check-in/check-out **tidak akan pernah berhasil** — pengguna terjebak di langkah liveness/verifikasi. Komentar `// We'll use the live frame` menandakan bagian ini belum selesai diimplementasikan.

**Perbaikan:** Ambil frame nyata dari kamera (mis. `_cameraController!.takePicture()` lalu baca bytes-nya, atau konversi `CameraImage` YUV→RGB dari stream) sebelum memanggil `verifyFace`. Jangan kirim buffer kosong.

---

### [C-02] Absensi ONLINE tidak pernah dikirim ke backend
**File:** `frontend/lib/features/attendance/presentation/pages/attendance_page.dart` (baris 356-364)
**File:** `frontend/lib/features/home/presentation/pages/home_page.dart` (baris 241-245)

Pada `_submitAttendance()`, ketika **online**, kode hanya melakukan:
```dart
// Online — submit via API (handled by parent BLoC)
Navigator.pop(context, { 'success': true, 'data': data, ... });
```
Komentar berkata "handled by parent BLoC", tetapi pemanggilnya di `home_page.dart`:
```dart
onTap: () {
  if (!jadwal.isCheckedIn && jadwal.isOngoing) {
    Navigator.pushNamed(context, '/attendance', arguments: jadwal); // TIDAK di-await, hasil pop diabaikan
  }
},
```

**Dampak:** Tidak ada satu pun kode yang menerima hasil `pop` dan mem-`POST` ke `/mahasiswa/attendance/check-in`. **Seluruh absensi online tidak tersimpan.** Hanya jalur OFFLINE (masuk queue) yang akhirnya mengirim data lewat `ConnectivityService` saat kembali online — itupun masih terhambat bug C-03/C-04. Tidak ada `AttendanceRepository`/datasource untuk attendance (folder `features/attendance/data/datasources` kosong).

**Perbaikan:** Buat `AttendanceRemoteDataSource` + repository + BLoC event `SubmitCheckIn/SubmitCheckOut`, lalu di `home_page.dart` gunakan `final result = await Navigator.pushNamed(...)` dan kirim `result['data']` ke endpoint check-in/check-out.

---

### [C-03] Mismatch nama field: `is_mock_location` vs `mock_location_detected`
**Backend:** `app/Http/Controllers/Api/Mahasiswa/AttendanceController.php` (baris 27 & 194)
```php
'is_mock_location' => 'required|boolean',   // WAJIB
```
**Frontend:** `attendance_page.dart` (baris 320)
```dart
'mock_location_detected': false,  // nama beda + field is_mock_location tidak ada
```

**Dampak:** Backend mewajibkan `is_mock_location` (dan juga `liveness_passed`), tetapi frontend mengirim `mock_location_detected`. Request check-in/check-out akan **selalu ditolak 422 "Validation failed"**. Catatan: `PRD-04` justru mendokumentasikan `mock_location_detected`, jadi **backend yang menyimpang dari dokumen**, sementara frontend mengikuti dokumen.

**Perbaikan:** Samakan satu nama. Rekomendasi: ubah validasi backend menjadi `mock_location_detected` (selaras PRD-04 & frontend), atau tambahkan mapping. Pastikan juga `liveness_passed` terkirim dari frontend (saat ini terkirim `true`, OK).

---

### [C-04] Alur offline sync salah endpoint dan salah bentuk payload
**File:** `frontend/lib/core/network/connectivity_service.dart` (baris 47-63)
**File:** `backend/app/Http/Controllers/Api/Mahasiswa/OfflineSyncController.php` (baris 21-30)

Frontend mengirim **tiap item antrian satu per satu** ke `/mahasiswa/attendance/check-in` dan `/mahasiswa/attendance/check-out`:
```dart
endpoint = ApiConstants.checkInEndpoint; // atau checkOutEndpoint
...
await _dio.post(endpoint, data: data);
```
Sedangkan endpoint khusus `/mahasiswa/attendance/sync-offline` mengharuskan **array terbungkus** dengan skema field berbeda:
```php
'attendances' => 'required|array|min:1|max:20',
'attendances.*.timestamp' => 'required|date',
'attendances.*.type' => 'required|in:check_in,check_out',
```

**Dampak ganda:**
1. Item offline dikirim ke endpoint check-in/out biasa → kena bug C-03 (field `is_mock_location` hilang) → gagal 422.
2. Endpoint `sync-offline` hanya dipakai untuk `OfflineQueueItem.syncOfflineType`, padahal tipe ini **tidak pernah di-enqueue** di mana pun → endpoint sync-offline praktis **dead code**.
3. Payload offline tidak punya `timestamp`/`type` sesuai skema sync.

**Perbaikan:** Pilih satu strategi. Rekomendasi: kumpulkan semua item offline menjadi satu array dan POST ke `sync-offline` dengan field `timestamp` + `type` (`check_in`/`check_out`). Hapus pemakaian endpoint check-in/out untuk replay offline.

---

### [C-05] Absensi offline melewati seluruh validasi keamanan (geofence, wajah, mock GPS)
**File:** `backend/app/Http/Controllers/Api/Mahasiswa/OfflineSyncController.php` (baris 83-143)

`processCheckIn()` pada sync offline **tidak** memvalidasi:
- jarak geofence (haversine) — `checkin_distance` tidak diisi,
- `face_distance` terhadap `face_threshold`,
- `is_mock_location`,
- keterdaftaran mahasiswa pada mata kuliah (`enrolled`).

Hanya memeriksa jendela waktu 30 menit.

**Dampak:** Celah keamanan. Mahasiswa dapat membuat payload offline dengan `latitude/longitude/face_distance` apa pun untuk titip absen. Hasil absen offline juga tidak menghitung `alpha_menit` (selalu default 0) sehingga status terlambat/pulang awal tidak akurat untuk record offline.

**Perbaikan:** Terapkan validasi yang sama dengan check-in online (geofence + face threshold + mock + enrollment + perhitungan `alpha_menit`/status) pada jalur sync offline.

---

## 3. TEMUAN HIGH 🟠

### [H-01] Alpha "pulang awal" untuk status `hadir` tidak terhitung ke SP
**File:** `backend/app/Services/AlphaAccumulationService.php` (baris 34-37) & `AttendanceController.php` checkout (baris 238-262)

Saat check-out lebih awal, `alpha_menit` ditambah (`checkoutAlphaTambahan`) namun **status tetap `hadir`**. Padahal `recalculate()` hanya menjumlahkan `alpha_menit` untuk status:
```php
->whereIn('status', ['hadir_terlambat', 'alpha', 'pending'])
->sum('alpha_menit');
```
Status `hadir` **dikecualikan** dari SUM.

**Dampak:** Mahasiswa yang check-in tepat waktu lalu pulang sangat awal mengumpulkan `alpha_menit` > 0, tetapi alpha itu **tidak pernah dihitung** untuk akumulasi SP (bug logika utama SP). Komentar di kode (CASE 3 "pulang awal") menyiratkan seharusnya ikut dihitung.

**Perbaikan:** Jumlahkan `alpha_menit` dari **semua** record (tanpa filter status), atau ubah agar record `hadir` dengan `alpha_menit > 0` ikut dijumlahkan. Mis. hapus klausa `whereIn('status', ...)` karena `hadir` murni sudah `alpha_menit = 0`.

---

### [H-02] Mismatch field geofence: `radius` (backend) vs `radius_meter` (frontend)
**File:** `frontend/lib/features/home/data/datasources/home_remote_datasource.dart` (baris 42)
**Backend kolom:** migration `geofences` → `radius` (baris 16), model mengembalikan key `radius`. PRD-04 juga `radius`.

```dart
geofenceRadius: _toDouble(geofence?['radius_meter'], fallback: 50), // key salah → selalu fallback 50
```

**Dampak:** Radius custom per-geofence (mis. 30m atau 100m) **diabaikan** di aplikasi; selalu memakai 50m untuk validasi geofence di `AttendancePage`. Bisa menyebabkan check-in valid/invalid yang salah.

**Perbaikan:** Ubah frontend membaca `geofence['radius']` (atau samakan penamaan di seluruh sistem menjadi `radius_meter`).

---

### [H-03] Home schedule tidak pernah tahu status sudah check-in
**File:** `frontend/lib/features/home/data/datasources/home_remote_datasource.dart` (baris 17-48)
**Backend:** `JadwalController::today()` mengembalikan koleksi jadwal **tanpa** `attendance_status`/`attendance_id`/`checkin_time`.

`_parseJadwal` membaca `json['attendance_status']`, `json['attendance_id']`, `json['checkin_time']` yang **tidak ada** di response `/mahasiswa/jadwal/today` → selalu null.

**Dampak:** `isCheckedIn`/`isCheckedOut` selalu false. Kartu jadwal di Home tidak pernah menampilkan "Sudah Check-in"/"Sudah Check-out", dan logika check-out (`isCheckout: jadwal.isCheckedIn && !jadwal.isCheckedOut` di `main.dart`) tidak pernah aktif → mahasiswa tak bisa check-out via Home.

**Perbaikan:** Gunakan endpoint yang mengandung status absensi (mis. `/mahasiswa/attendance/today` digabung jadwal), atau tambahkan join `attendance_status`/`attendance_id` pada response `jadwal/today`. PRD-04 `GET /attendance/today` memang dirancang membawa `attendance_status` & `attendance_id`.

---

### [H-04] `face_threshold` per-prodi tidak pernah sampai ke device
**File:** `backend/app/Http/Controllers/Api/Mahasiswa/EnrollmentController.php` `getMyEmbedding()` (baris 149-153)
**File:** `frontend/lib/features/face_recognition/presentation/bloc/face_bloc.dart` (baris 76)

`getMyEmbedding` hanya mengembalikan `embedding`, `version`, `created_at`. Frontend membaca `data['threshold']` → null → fallback 1.0.

**Dampak:** Nilai `face_threshold` yang dikonfigurasi admin di `ProdiSetting` tidak pernah dipakai untuk verifikasi on-device; selalu 1.0. Menghilangkan kemampuan tuning akurasi (FAR/FRR) per prodi yang justru jadi inti penelitian.

**Perbaikan:** Tambahkan `'threshold' => $prodiSetting?->face_threshold ?? 1.0` pada response `getMyEmbedding`.

---

### [H-05] Double recalculation alpha pada setiap check-in/out
**File:** `backend/app/Http/Controllers/Api/Mahasiswa/AttendanceController.php` (baris 163-164, 273-274)
**File:** `backend/app/Services/SpDetectionService.php` (baris 27)

```php
app(\App\Services\AlphaAccumulationService::class)->recalculate($user->id); // (1)
app(\App\Services\SpDetectionService::class)->evaluate($user->id);          // (2)
```
Padahal `evaluate()` di baris 27 sudah memanggil `recalculate()` lagi:
```php
$accumulation = $this->alphaService->recalculate($userId, $semesterId);
```

**Dampak:** `recalculate()` (yang melakukan agregasi DB + updateOrCreate) berjalan **dua kali** setiap absen. Pemborosan query, dan potensi race pada beban tinggi (uji simultan adalah bagian evaluasi penelitian).

**Perbaikan:** Hapus pemanggilan `recalculate()` standalone di controller, cukup `evaluate()` (yang sudah memanggilnya).

---

### [H-06] Duplikasi baris pemanggilan `evaluateSpLevel`
**File:** `backend/app/Services/AlphaAccumulationService.php` (baris 54-55)
```php
$spLevel = $this->evaluateSpLevel($userId, $totalAlphaJam);
$spLevel = $this->evaluateSpLevel($userId, $totalAlphaJam); // duplikat persis
```

**Dampak:** Tidak salah hasil, tetapi memanggil query `User::find` + `getSpThresholds` (query `ProdiSetting`) dua kali. Indikasi copy-paste error.

**Perbaikan:** Hapus salah satu baris.

---

## 4. TEMUAN MEDIUM 🟡

### [M-01] Potensi truncation/negatif pada `diffInMinutes` (kompatibilitas Carbon 2/3)
**File:** `AttendanceController.php` (baris 108, 113, 126, 240, 252)
Pada Carbon 3 (bisa terbawa Laravel 11), `diffInMinutes` mengembalikan **float bertanda (signed)**. Kolom `alpha_menit`/`durasi_efektif_menit` bertipe `integer`. Jika urutan argumen membuat hasil negatif (mis. now sebelum jamMulai) atau float, nilai bisa salah/terpotong.
**Perbaikan:** Bungkus dengan `(int) round(abs(...))` dan pastikan urutan argumen konsisten. Pin versi Carbon di `composer.json`.

### [M-02] `OfflineSyncController` tidak menangani pelanggaran unique constraint
**File:** `OfflineSyncController.php` (baris 114-127). Tabel `attendances` punya unique `(user_id, jadwal_id, tanggal)`. Jika race/duplikat saat sync, `create()` melempar `QueryException` → 500 (tidak ditangkap per-item).
**Perbaikan:** Bungkus per-item dengan try/catch dan kembalikan status `failed` alih-alih 500 untuk seluruh batch.

### [M-03] `refresh` token memakai nama device hardcoded
**File:** `AuthController.php` (baris 143) `createToken('mobile-app')` — kehilangan nama device asli yang dikirim saat login.
**Perbaikan:** Simpan/teruskan `device_name`.

### [M-04] Single-device policy menghapus semua token saat login
**File:** `AuthController.php` (baris 41) `$user->tokens()->delete();` — desain OK untuk mobile, namun mematikan sesi web admin bila user yang sama login mobile. Pastikan ini memang diinginkan untuk role admin yang juga akses panel.

### [M-05] `today()` & dashboard memakai `Semester::where('status','aktif')` tanpa guard null konsisten
**File:** `DashboardController.php` (baris 64) memanggil `$summary->total > 0` — jika `selectRaw` mengembalikan `total = null` (tidak ada baris), perbandingan `null > 0` = false (aman), tetapi `persentase_kehadiran` lalu 0; OK. Namun jika `semesterAktif` null, `whereHas` memakai `semester_id = null` → bisa mengembalikan data tak terduga. Tambahkan early-return bila tak ada semester aktif.

### [M-06] Validasi dimensi embedding terlalu longgar
**File:** `EnrollmentController.php` (baris 20) `'embedding' => 'required|array|min:128|max:512'`. MobileFaceNet di frontend menghasilkan **tepat 192** (`face_recognition_service.dart` baris 38). Embedding berdimensi beda (mis. 128) akan lolos simpan namun gagal cocok saat verifikasi (`Embedding dimensions mismatch`).
**Perbaikan:** Kunci `size:192` agar konsisten dengan model.

### [M-07] Inkonsistensi pembanding jarak wajah (euclidean) vs istilah "threshold"
**File:** `face_recognition_service.dart` (baris 92) `isMatch: distance < threshold` dengan euclidean distance & default 1.0. Pastikan nilai threshold hasil training MobileFaceNet (L2 ternormalisasi) memang ~1.0; jika embedding tidak dinormalisasi L2, skala jarak bisa jauh berbeda dari 1.0 sehingga semua "match"/"tidak match". Perlu normalisasi L2 embedding sebelum hitung jarak.

---

## 5. TEMUAN LOW & DOKUMENTASI 🔵

### [D-01] PRD-04 memakai base URL & path yang TIDAK sesuai implementasi
- Dokumen: `https://api.absensi.domain.com/api/v1`; implementasi: prefix `/api` (tanpa `v1`). Frontend memakai `http://10.0.2.2:8000/api`.
- Path dokumen vs nyata berbeda banyak, antara lain:
  | PRD-04 | Implementasi nyata |
  |--------|--------------------|
  | `/enrollment/submit` | `/mahasiswa/enrollment` |
  | `/enrollment/my-embedding` | `/mahasiswa/enrollment/embedding` |
  | `/attendance/active-schedule` | `/mahasiswa/jadwal/active` |
  | `/leaves`, `/leaves/my` | `/mahasiswa/leave-requests` |
  | `/sp/my` | `/mahasiswa/sp-records` |
  | `/dosen/my-classes` | `/dosen/mata-kuliah` |
  | `/auth/update-profile` | `/profile` (PUT) |
  | `/auth/update-fcm-token` | `/fcm-token` |
  | `/sp/records/{id}/sign-kaprodi` | `/kaprodi/sp-records/{id}/sign` |
**Dampak:** Dokumen API usang; menyulitkan onboarding & pengujian. Frontend mengikuti route nyata (baik), tetapi penulisan skripsi yang merujuk PRD-04 akan keliru.
**Perbaikan:** Perbarui PRD-04 agar sesuai `routes/api.php`.

### [D-02] Endpoint terdokumentasi tapi belum ada di route
- `/attendance/summary`, `/analysis/far-frr`, `/analysis/documentation`, `/enrollment/pending` (di-handle via `/kaprodi/enrollments`). Perlu disinkronkan.

### [L-01] Frontend `forgot-password` & layar `/notifications` masih placeholder
**File:** `main.dart` (baris 128, 146-152) — "Fitur lupa password belum tersedia", padahal backend punya endpoint `forgot-password`/`reset-password`. Halaman notifikasi penuh juga placeholder `Text('Notifikasi')`.

### [L-02] `home_remote_datasource` membaca `data['sp_threshold']` & `summary['pending']` yang tidak dikirim backend
**File:** `home_remote_datasource.dart` (baris 62, 67) — `pending` & `sp_threshold` tak ada di `DashboardController` → fallback. Tambahkan field tersebut di backend agar progress SP & jumlah pending akurat.

### [L-03] `LogInterceptor` membocorkan request/response body penuh ke log
**File:** `api_client.dart` (baris 25-29) — `requestBody:true, responseBody:true` akan mencetak embedding & token ke log produksi. Nonaktifkan di release build.

### [L-04] Rotasi InputImage hardcoded `rotation270deg`
**File:** `attendance_page.dart` (baris 204) — rotasi kamera depan berbeda antar device/orientasi; hardcode bisa membuat deteksi wajah gagal di sebagian HP. Hitung rotasi dari `sensorOrientation`.

### [L-05] Enum tipe notifikasi vs nilai yang dipakai service
**File:** migrasi `notifications` (enum) vs `SpDetectionService` (`sp_warning`,`sp_issued` — valid). Perlu cek migrasi `2026_05_28_000001_add_types_to_notifications_table` memuat seluruh tipe yang dipakai listener (`approval_needed`, dll.) agar insert tidak gagal di MySQL strict.

---

## 6. CATATAN POSITIF (Sudah Benar)
- Middleware alias `role` & `enrollment.approved` terdaftar benar di `bootstrap/app.php`.
- Rate limiter `login`, `attendance`, `export`, `upload`, `api` terdefinisi di `AppServiceProvider`.
- Haversine di backend (`calculateDistance`) benar (radius bumi 6.371.000 m, urutan lat/lon konsisten).
- Threshold SP (sp1=16, sp2=32, sp3=38, do=46 jam) konsisten antara backend default & `app_constants.dart` frontend.
- Penanganan exception global → JSON untuk route `api/*` sudah rapi.
- Struktur Clean Architecture frontend & pemisahan controller per-role backend baik.

---

## 7. REKOMENDASI URUTAN PENYELESAIAN
1. **C-01** (face bytes kosong) — tanpa ini absensi mustahil.
2. **C-03** (samakan `is_mock_location`) — perbaikan cepat, buka jalur request.
3. **C-02** (kirim absensi online) — bangun datasource/repo/BLoC attendance.
4. **C-04 & C-05** (perbaiki & amankan offline sync).
5. **H-01** (perbaiki SUM alpha pulang awal) — inti akurasi SP.
6. **H-02, H-03, H-04** (mismatch field geofence/threshold/status home).
7. **H-05, H-06** (rapikan double recalculation).
8. Lanjut MEDIUM → LOW → sinkronisasi dokumen (D-01/D-02).

> Setiap item sudah dilengkapi file, baris, dampak, dan saran perbaikan agar bisa dikerjakan bertahap. Silakan tentukan item mana yang ingin diselesaikan lebih dulu, dan kita kerjakan satu per satu.

---

## 8. KESESUAIAN DENGAN PROPOSAL PENELITIAN (`penelitian.pdf`) 🟣

Bagian ini membandingkan **klaim/metodologi pada proposal** dengan **implementasi nyata di kode**. Temuan di sini bersifat *research-critical*: bukan sekadar bug aplikasi, tetapi hal yang bisa **menggugurkan novelty, melanggar klaim privasi, atau membuat data evaluasi (FAR/FRR, latensi, uji simultan, perbandingan konvensional) tidak bisa dihasilkan**. Ini yang akan langsung memengaruhi artikel jurnal & laporan penelitian.

### Ringkasan Kesesuaian
| Klaim di Proposal | Status di Kode | Severity |
|-------------------|----------------|----------|
| On-device inference MobileFaceNet (embedding 192) | ✅ Model & dimensi 192 ada, TAPI verifikasi tak jalan (C-01) | 🔴 |
| Euclidean distance + threshold θ | ⚠️ Ada, tapi tanpa normalisasi L2 & threshold tak sinkron | 🟣 R-02 |
| Privasi: "simpan embedding, BUKAN citra wajah mentah" | ❌ DILANGGAR — foto wajah disimpan ke disk | 🟣 R-01 |
| Mitigasi spoofing lokasi (mock GPS via `safe_device`) | ❌ Mock selalu `false` (hardcoded), deteksi tak jalan | 🟣 R-03 |
| Mitigasi spoofing wajah (liveness/anti-spoofing) | ⚠️ Liveness ada tapi tak diverifikasi nyata ke embedding (C-01) | 🟣 R-04 |
| Evaluasi FAR/FRR untuk tuning threshold | ❌ Tak ada pelabelan genuine/impostor → FAR/FRR selalu null | 🟣 R-05 |
| Pengukuran latensi inferensi (avg, P95, per-device) | ❌ `inference_time_ms` tak pernah masuk ke `attendance_logs` | 🟣 R-06 |
| Uji simultan 20/30/40 mahasiswa (response time, success rate) | ❌ `concurrent_level` tak pernah ditulis → endpoint kosong | 🟣 R-07 |
| Perbandingan dengan presensi konvensional | ⚠️ Endpoint ada, tapi input manual & `avg_duration` rawan null | 🟣 R-08 |
| Check-in & check-out untuk durasi kehadiran efektif | ⚠️ Logika ada, tapi check-out tak bisa dipicu dari UI (H-03) | 🟣 R-09 |
| Early warning SP (SP1/SP2/SP3) real-time | ⚠️ Ada, tapi akurasi terganggu bug H-01 (alpha pulang awal) | 🟣 R-10 |

---

### [R-01] PELANGGARAN KLAIM PRIVASI — citra wajah mentah ikut disimpan 🔴
**Proposal (hal. 19, Metode Pengumpulan Data poin 3):** *"Data yang disimpan berupa embedding wajah, **bukan citra wajah mentah, untuk menjaga aspek privasi data**."*
**Kode (kontradiksi):** `Mahasiswa/EnrollmentController.php` baris 44 & 58:
```php
$fotoPath = $request->file('foto')->store('enrollment', 'public');
...
$user->update([ 'foto_enrollment' => $fotoPath, ]);
```
Foto enrollment **disimpan permanen** di `storage/app/public/enrollment`, bahkan diserve sebagai URL publik (`Kaprodi/EnrollmentController.php` baris 35-37: `Storage::disk('public')->url(...)`). Hal serupa pada re-enrollment (`store('re-enrollment','public')`).

**Dampak:** Bertentangan langsung dengan klaim privasi yang ditulis di proposal & menjadi nilai jual penelitian ("menjaga privasi"). Ini akan jadi temuan serius bila ditanya penguji/reviewer jurnal. Selain itu menyimpan biometrik mentah punya implikasi etik/hukum (data pribadi sensitif).

**Pilihan perbaikan (tentukan kebijakan):**
1. Jika klaim privasi dipertahankan → **jangan simpan foto**; foto hanya dipakai sementara untuk verifikasi kaprodi lalu dihapus, atau hanya simpan embedding.
2. Jika foto memang perlu untuk approval kaprodi → revisi kalimat di proposal agar jujur (mis. "foto disimpan terenkripsi & terbatas hanya untuk proses verifikasi enrollment, dihapus setelah disetujui"), dan tambahkan mekanisme penghapusan + akses terbatas (bukan disk `public`).

---

### [R-02] Euclidean distance tanpa normalisasi L2 + threshold tidak sinkron antar-komponen 🟣
**Proposal (2.2.3.3–2.2.4):** embedding 192-d, normalisasi input `(x-127.5)/127.5`, pencocokan `d(e,t)=√Σ(eᵢ-tᵢ)²`, keputusan `match = d < θ`.
**Kode:** `face_recognition_service.dart` menghitung euclidean langsung pada embedding output model **tanpa L2-normalize**. Akibatnya skala jarak tergantung magnitude embedding (bisa jauh dari rentang ~0–2 yang lazim untuk embedding ternormalisasi).

**Lebih parah — ada TIGA nilai threshold default yang berbeda di sistem:**
| Lokasi | Nilai θ default |
|--------|-----------------|
| `face_bloc.dart` / `face_recognition_service.dart` (device) | **1.0** |
| `AttendanceController.php` (backend check-in) baris 91 | **1.00** |
| `Admin/AnalysisController.php::faceVerification` (FAR/FRR) baris 85 | **0.6** |

**Dampak:** Evaluasi FAR/FRR memakai θ=0.6, sementara keputusan match aktual memakai θ=1.0. Hasil penelitian (kurva FAR/FRR, penentuan θ optimal) jadi **tidak konsisten dengan threshold yang benar-benar dipakai sistem**. Tanpa L2-normalize, nilai θ pun tidak punya dasar teoretis yang kuat.

**Perbaikan:** (1) L2-normalize embedding sebelum hitung jarak; (2) satukan sumber kebenaran θ (idealnya dari `ProdiSetting.face_threshold`, sekaligus selesaikan H-04); (3) samakan θ default untuk evaluasi & produksi, lalu tentukan θ optimal lewat sweep FAR/FRR.

---

### [R-03] Mitigasi spoofing LOKASI tidak berjalan — `mock_location_detected` hardcoded `false` 🟣
**Proposal (3.5.c & Gambar 4 poin b):** sistem **wajib** mendeteksi mock location; *"Jika sistem mendeteksi indikasi manipulasi lokasi, maka proses absensi langsung ditolak"*. Package `safe_device` disebut eksplisit di alat & bahan (hal. 18).
**Kode:** `attendance_page.dart` baris 320: `'mock_location_detected': false` — **nilai dipaku mati**, `safe_device` tidak pernah dipanggil untuk mengecek `isMockLocation`/`isJailBroken`. Backend (`AttendanceController` baris 67) sebenarnya siap menolak bila `is_mock_location == true`, tetapi karena frontend selalu kirim `false`, **deteksi tak pernah aktif** (selain juga kena mismatch nama field C-03).

**Dampak:** Salah satu **mitigasi kecurangan inti (anti-spoofing lokasi)** yang jadi gap & novelty penelitian **tidak terimplementasi**. Mahasiswa bisa pakai fake GPS tanpa terdeteksi.

**Perbaikan:** Panggil `SafeDevice.isMockLocation` (dan idealnya `isJailBroken`, `isRealDevice`) sebelum submit, isi field sesuai hasil nyata, kirim dengan nama field yang benar (selesaikan bareng C-03).

---

### [R-04] Mitigasi spoofing WAJAH (liveness) tidak benar-benar terikat ke verifikasi 🟣
**Proposal (2.1, 2.3 poin 3, 3.5):** anti-spoofing wajah (foto/gambar palsu) jadi nilai pembeda.
**Kode:** `LivenessDetectionService` memang melakukan challenge (smile/blink/turn/nod) berbasis ML Kit — ini bagus. **Namun:**
- karena C-01 (bytes kosong), embedding wajah saat absensi **tidak pernah benar-benar dihitung dari frame liveness**, sehingga tidak ada jaminan wajah yang lolos liveness = wajah yang diverifikasi;
- `liveness_passed` dikirim **hardcoded `true`** (`attendance_page.dart` baris 314), bukan dari hasil akhir challenge;
- liveness berbasis 1 frame ML Kit rentan terhadap video replay (proposal menyebut anti-spoofing sebagai target, perlu disebut sebagai limitasi bila hanya challenge-response).

**Dampak:** Klaim "mitigasi spoofing wajah" lemah secara metodologis. Untuk laporan, minimal harus jujur menyebut tingkat anti-spoofing yang dicapai (challenge-response, bukan PAD/Presentation Attack Detection penuh).

**Perbaikan:** Ikat hasil liveness ke embedding frame yang sama; kirim `liveness_passed` dari status nyata; dokumentasikan batas kemampuan anti-spoofing.

---

### [R-05] FAR/FRR tak akan pernah terisi — tidak ada pelabelan genuine/impostor 🟣
**Proposal (2.2.4, 3.4.c):** FAR & FRR adalah **metrik evaluasi utama** untuk menentukan threshold optimal.
**Kode:** `Admin/AnalysisController.php::faceVerification` baris 78-79 menghitung FAR/FRR dari:
```php
AttendanceLog::whereJsonContains('metadata->label', 'genuine')->get();
AttendanceLog::whereJsonContains('metadata->label', 'impostor')->get();
```
Namun **tidak ada satu pun kode yang menulis `metadata->label = 'genuine'/'impostor'`** ke `attendance_logs`. Pencatatan log absensi (`logAttempt`) tidak pernah menambahkan label ini, dan tidak ada endpoint/skenario uji yang meng-input data impostor.

**Dampak:** Endpoint FAR/FRR **selalu mengembalikan `far=null, frr=null`**. Artinya **data inti untuk bab evaluasi penelitian tidak bisa diperoleh** dari sistem apa adanya.

**Perbaikan:** Bangun mode/skenario uji khusus (mis. "test mode" yang sudah ada `Admin/TestModeController.php`) untuk merekam percobaan genuine vs impostor beserta `face_distance` dan label, lalu hitung kurva FAR/FRR pada rentang θ untuk menentukan θ optimal (EER).

---

### [R-06] Latensi inferensi tak pernah tersimpan ke DB → analisis latensi kosong 🟣
**Proposal (2.2.5.1–2.2.5.2, 3.4.c):** ukur waktu inferensi (avg, min, max, P95) per perangkat.
**Kode:**
- Frontend **mengirim** `inference_time_ms` di payload (`attendance_page.dart` baris 316) — bagus.
- Tetapi backend `AttendanceController::checkIn/checkOut` **tidak menyimpan** `inference_time_ms` ke `attendances` maupun ke `attendance_logs` (lihat `logAttempt` — metadata tak memuatnya). Kolom `attendance_logs.inference_time_ms` & `device_model` **ada di migrasi** tapi **tak pernah diisi**.
- `Admin/AnalysisController.php::latency` baris 122 query `whereNotNull('inference_time_ms')` → **selalu kosong**.

**Dampak:** Endpoint latensi **selalu mengembalikan data kosong**. Padahal latensi on-device adalah **klaim performa utama** MobileFaceNet di proposal.

**Perbaikan:** Pada `checkIn/checkOut`, validasi & simpan `inference_time_ms` + `device_model` ke `attendance_logs` (dan/atau ke `attendances`). Baru endpoint latency bisa menghasilkan avg/P95/per-device.

---

### [R-07] Uji simultan (20/30/40 mhs) tak menghasilkan data — `concurrent_level` tak pernah ditulis 🟣
**Proposal (3.4.e, 3.5.f):** uji simultan untuk mengukur response time & success rate pada beban 20, 30, 40 pengguna.
**Kode:** `Admin/AnalysisController.php::simultaneousTest` baris 221 membaca `metadata->concurrent_level`, `metadata->latency_ms`, `metadata->success` dari `attendance_logs`. **Tidak ada kode** yang pernah menulis `concurrent_level`/`success` ke metadata log.

**Dampak:** Endpoint uji simultan **selalu kosong**. Skenario evaluasi skalabilitas (bagian penting metode) tidak punya pipeline data.

**Perbaikan:** Tambahkan mekanisme (header/param uji atau test mode) agar setiap request absensi pada sesi uji mencatat `concurrent_level` + `success` + `latency_ms` ke metadata, atau lakukan load test terpisah (mis. k6/JMeter) dan simpan hasilnya. Pertimbangkan juga bahwa H-05 (double recalculation) memperberat beban saat uji simultan.

---

### [R-08] Perbandingan konvensional rawan data null & input 100% manual 🟣
**Proposal (3.4.f):** bandingkan waktu proses, akurasi, human error, ketersediaan real-time vs metode kertas.
**Kode:** `conventionalComparison` baris 268 menghitung `AVG(TIMESTAMPDIFF(SECOND, checkin_time, checkout_time))`. Karena check-out praktis tak bisa dipicu dari UI (H-03) & online submit tak jalan (C-02), `checkout_time` mayoritas NULL → `avg_duration` **null**. Data konvensional sepenuhnya bergantung input manual via `storeConventionalData` (tidak ada otomatisasi pengukuran).

**Dampak:** Tabel perbandingan untuk artikel berisiko kosong/parsial di sisi sistem.

**Perbaikan:** Selesaikan dulu C-02 & H-03 agar durasi efektif & kehadiran tersimpan; siapkan template/SOP pencatatan data konvensional.

---

### [R-09] Check-out (durasi kehadiran efektif) tak bisa dipicu dari UI 🟣
**Proposal (3.5.f poin 4 & Gambar 4):** uji check-in & check-out untuk **durasi kehadiran efektif** mahasiswa.
**Kode:** Logika `checkOut` + `durasi_efektif_menit` di backend sudah benar, tetapi:
- Home tidak tahu status check-in (H-03) sehingga tombol/aksi check-out tak pernah muncul;
- bahkan jika muncul, online submit tak terkirim (C-02).

**Dampak:** Metrik "durasi kehadiran efektif" sulit/ tak terkumpul. Bergantung penyelesaian H-03 + C-02.

**Perbaikan:** Sama dengan H-03 & C-02.

---

### [R-10] Early warning SP real-time akurasinya terganggu bug akumulasi alpha 🟣
**Proposal (1.5 Novelty, 2.2.1):** early warning SP (SP1/SP2/SP3) berbasis akumulasi ketidakhadiran adalah **kontribusi pembeda** penelitian.
**Kode:** Mesin SP (`SpDetectionService`, `AlphaAccumulationService`) sudah ada & jalan, **tetapi**:
- H-01: alpha "pulang awal" pada status `hadir` tak ikut dihitung → akumulasi bisa **under-count**;
- H-05/H-06: double & duplicate recalculation (kinerja, bukan akurasi);
- C-05: absen offline tak menghitung alpha → akumulasi tak akurat untuk record offline;
- Catatan konsep: proposal (2.2.1) mendefinisikan early warning berbasis **persentase kehadiran**, sedangkan sistem memakai **akumulasi jam alpha** (16/32/38/46 jam). Ini **dua basis berbeda** — perlu diselaraskan/dijelaskan di laporan agar konsisten dengan rumus persentase kehadiran yang ditulis di proposal.

**Dampak:** Hasil early warning bisa berbeda dari definisi di proposal; akurasi SP terpengaruh H-01/C-05.

**Perbaikan:** Selesaikan H-01 & C-05; selaraskan definisi (persentase kehadiran vs akumulasi jam) antara proposal dan implementasi, atau jelaskan pemetaannya secara eksplisit di laporan.

---

### Catatan Positif terkait Penelitian
- Dimensi embedding **192** (proposal) konsisten dengan output model di `face_recognition_service.dart`.
- Normalisasi input `(x-127.5)/127.5` & ukuran 112×112 sesuai proposal.
- Liveness challenge (smile/blink/turn/nod) via ML Kit sudah ada (tinggal diikat ke verifikasi).
- Kerangka endpoint analisis (geofence, face, latency, attendance-sp, simultaneous, conventional) **sudah disiapkan** — hanya butuh pipeline data agar terisi.
- Haversine & deteksi geofence backend benar; cocok untuk analisis distribusi jarak (`AnalysisController::geofence`).

---

## 9. REKOMENDASI URUTAN (REVISI, MENCAKUP RESEARCH-CRITICAL)
Agar penelitian bisa menghasilkan **data evaluasi yang valid**, urutan idealnya:
1. **C-01 → C-03 → R-03** : hidupkan verifikasi wajah + mock-location nyata (anti-spoofing).
2. **C-02 → H-03 → R-09** : online submit + status check-in/out (durasi efektif).
3. **R-06** : simpan `inference_time_ms`/`device_model` → data latensi.
4. **R-02 + H-04** : L2-normalize + satukan threshold (θ).
5. **R-05** : pipeline label genuine/impostor → FAR/FRR & θ optimal.
6. **R-07** : pipeline `concurrent_level` → uji simultan.
7. **H-01 → C-05 → R-10** : akurasi akumulasi alpha & early warning SP.
8. **R-01** : putuskan kebijakan privasi foto (selaraskan dgn klaim proposal).
9. **R-08** : lengkapi data perbandingan konvensional.
10. Sisanya: MEDIUM/LOW + sinkronisasi PRD (D-01/D-02).

> Bagian 8 ini fokus pada hal yang membuat **laporan & artikel penelitian bisa dipertanggungjawabkan**. Tanpa R-01..R-10, aplikasi mungkin "jalan" untuk demo, tapi **bab Hasil & Pembahasan (FAR/FRR, latensi, uji simultan, perbandingan) tidak akan punya data**. Silakan pilih, kita kerjakan satu per satu bro.


