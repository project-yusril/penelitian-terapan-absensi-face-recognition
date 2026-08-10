# PRD-07: MENU ANALISIS & EVALUASI SISTEM

> **Status:** target penelitian. Angka contoh bukan hasil pengukuran produksi.
> Protokol FAR/FRR/PAD/load-test mengikuti R-01 sampai R-05 di
> [temuan.md](temuan.md); SOP saat ini masih draft non-executable.

## Akses: Hanya Super Admin
## Lokasi: Sidebar menu terpisah (di bawah garis pemisah)

---

## 1. SUB-MENU: EVALUASI GEOFENCE

### 1.1 Tujuan
Menampilkan data evaluasi validasi lokasi (geofencing) untuk membuktikan bahwa sistem mampu memvalidasi lokasi mahasiswa secara akurat.

### 1.2 Konten Halaman

#### A. Penjelasan Rumus
```
┌─────────────────────────────────────────────────────────────────────┐
│ RUMUS PERHITUNGAN JARAK (Haversine Formula)                          │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ d = 2r × arcsin(√(sin²((φ₂-φ₁)/2) + cos(φ₁)×cos(φ₂)×sin²((λ₂-λ₁)/2)))│
│                                                                      │
│ Keterangan:                                                          │
│ • d       = Jarak antara dua titik koordinat (meter)                │
│ • r       = Radius bumi (6.371.000 meter)                           │
│ • φ₁, φ₂ = Latitude titik 1 dan titik 2 (dalam radian)            │
│ • λ₁, λ₂ = Longitude titik 1 dan titik 2 (dalam radian)           │
│                                                                      │
│ Keputusan Validasi:                                                  │
│ • VALID   jika d ≤ radius_geofence                                  │
│ • INVALID jika d > radius_geofence                                  │
│                                                                      │
│ Sumber Data:                                                         │
│ • (φ₁, λ₁) = Koordinat GPS mahasiswa (dari sensor perangkat)       │
│ • (φ₂, λ₂) = Koordinat titik pusat geofence (dari database)       │
│ • radius_geofence = Setting per lokasi (default 50 meter)           │
│                                                                      │
│ Implementasi:                                                        │
│ • Menggunakan Geolocator.distanceBetween() pada Flutter             │
│ • Atau perhitungan manual Haversine                                  │
└─────────────────────────────────────────────────────────────────────┘
```
*Rumus di-render menggunakan KaTeX*

#### B. Data Tabel
| Kolom | Deskripsi |
|-------|-----------|
| No | Nomor urut |
| Tanggal/Waktu | Timestamp absensi |
| Mahasiswa | Nama + NIM |
| Mata Kuliah | Nama MK |
| Lokasi Geofence | Nama lokasi (titik pusat) |
| Koordinat Mahasiswa | lat, lon |
| Koordinat Geofence | lat, lon |
| Radius Setting | meter |
| Jarak Terhitung | meter (hasil Haversine) |
| Akurasi GPS | meter |
| Status | Valid / Invalid |
| Mock Location | Ya / Tidak |

#### C. Chart & Statistik
- **Pie Chart**: Distribusi Valid vs Invalid
- **Histogram**: Distribusi jarak (0-10m, 10-20m, 20-30m, ..., >100m)
- **Line Chart**: Akurasi GPS rata-rata per hari
- **Card Statistik**:
  - Total percobaan: X
  - Success rate: X%
  - Rata-rata jarak: X meter
  - Rata-rata akurasi GPS: X meter
  - Mock location terdeteksi: X kali

#### D. Filter
- Rentang tanggal
- Prodi
- Lokasi geofence
- Status (valid/invalid)

---

## 2. SUB-MENU: EVALUASI FACE VERIFICATION

### 2.1 Tujuan
Menampilkan data evaluasi akurasi verifikasi wajah, termasuk perhitungan FAR dan FRR.

### 2.2 Konten Halaman

#### A. Penjelasan Rumus

```
┌─────────────────────────────────────────────────────────────────────┐
│ RUMUS EUCLIDEAN DISTANCE                                             │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ d(e, t) = √(Σᵢ₌₁ⁿ (eᵢ - tᵢ)²)                                   │
│                                                                      │
│ Keterangan:                                                          │
│ • d(e,t)  = Jarak antara 2 embedding wajah                         │
│ • e       = Embedding hasil pemindaian (192 float) - saat absensi   │
│ • t       = Embedding referensi (192 float) - saat enrollment       │
│ • n       = Jumlah dimensi embedding = 192                          │
│ • eᵢ     = Nilai dimensi ke-i dari embedding scan                  │
│ • tᵢ     = Nilai dimensi ke-i dari embedding referensi             │
│                                                                      │
│ Keputusan Verifikasi:                                                │
│ • MATCH     jika d(e,t) < θ (threshold)                            │
│ • NOT MATCH jika d(e,t) ≥ θ (threshold)                            │
│                                                                      │
│ Sumber Data:                                                         │
│ • e = Output MobileFaceNet dari wajah real-time                     │
│ • t = Embedding tersimpan di database (saat enrollment)             │
│ • θ = Threshold dari prodi_settings (default 1.0)                   │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│ RUMUS FALSE ACCEPT RATE (FAR)                                        │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ FAR(θ) = N_FA(θ) / N_impostor                                      │
│                                                                      │
│ Keterangan:                                                          │
│ • FAR(θ)     = Tingkat kesalahan penerimaan pada threshold θ        │
│ • N_FA(θ)    = Jumlah impostor yang KELIRU DITERIMA                 │
│                (d < θ padahal bukan orang yang sama)                 │
│ • N_impostor = Total percobaan impostor                             │
│                                                                      │
│ Sumber Data:                                                         │
│ • Dari mode pengujian: mahasiswa A coba verifikasi di akun B        │
│ • N_FA = jumlah yang hasilnya MATCH (seharusnya NOT MATCH)          │
│ • N_impostor = total percobaan impostor yang dilakukan              │
│                                                                      │
│ Interpretasi:                                                        │
│ • FAR rendah = sistem aman dari titip absen                         │
│ • Target: FAR < 0.01 (kurang dari 1%)                              │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│ RUMUS FALSE REJECT RATE (FRR)                                        │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ FRR(θ) = N_FR(θ) / N_genuine                                       │
│                                                                      │
│ Keterangan:                                                          │
│ • FRR(θ)    = Tingkat kesalahan penolakan pada threshold θ          │
│ • N_FR(θ)   = Jumlah genuine yang KELIRU DITOLAK                    │
│               (d ≥ θ padahal orang yang sama)                       │
│ • N_genuine = Total percobaan genuine                               │
│                                                                      │
│ Sumber Data:                                                         │
│ • Dari mode pengujian: mahasiswa A verifikasi di akun A sendiri     │
│ • N_FR = jumlah yang hasilnya NOT MATCH (seharusnya MATCH)          │
│ • N_genuine = total percobaan genuine yang dilakukan                │
│                                                                      │
│ Interpretasi:                                                        │
│ • FRR rendah = sistem nyaman digunakan (jarang salah tolak)         │
│ • Target: FRR < 0.05 (kurang dari 5%)                              │
└─────────────────────────────────────────────────────────────────────┘
```

#### B. Data Tabel - Semua Percobaan
| Kolom | Deskripsi |
|-------|-----------|
| No | Nomor urut |
| Tanggal/Waktu | Timestamp |
| Mahasiswa (Akun) | Akun yang login |
| Jenis Percobaan | Genuine / Impostor |
| Euclidean Distance | Nilai d(e,t) |
| Threshold | Nilai θ yang digunakan |
| Hasil Sistem | Match / Not Match |
| Hasil Seharusnya | Match (genuine) / Not Match (impostor) |
| Benar/Salah | ✅ Benar / ❌ Salah |

#### C. Tabel Hasil FAR & FRR
```
┌─────────────────────────────────────────────────────────────────────┐
│ HASIL EVALUASI FAR & FRR                                             │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ Threshold (θ) yang digunakan: 1.0                                   │
│                                                                      │
│ ┌──────────────────────────────────────────────────────────────┐    │
│ │ Percobaan Genuine:                                            │    │
│ │   Total percobaan (N_genuine): 200                           │    │
│ │   Berhasil (True Accept): 195                                │    │
│ │   Gagal / Keliru ditolak (N_FR): 5                           │    │
│ │   FRR = 5 / 200 = 0.025 = 2.5%                             │    │
│ └──────────────────────────────────────────────────────────────┘    │
│                                                                      │
│ ┌──────────────────────────────────────────────────────────────┐    │
│ │ Percobaan Impostor:                                           │    │
│ │   Total percobaan (N_impostor): 150                          │    │
│ │   Berhasil ditolak (True Reject): 148                        │    │
│ │   Keliru diterima (N_FA): 2                                  │    │
│ │   FAR = 2 / 150 = 0.0133 = 1.33%                           │    │
│ └──────────────────────────────────────────────────────────────┘    │
│                                                                      │
│ Accuracy = (True Accept + True Reject) / Total = 343/350 = 98%     │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

#### D. Chart
- **Scatter Plot**: Distribusi distance (genuine vs impostor)
- **Histogram**: Distribusi distance genuine (biru) vs impostor (merah)
- **Line Chart**: FAR & FRR vs berbagai threshold (untuk cari threshold optimal)
- **Card Statistik**: FAR, FRR, Accuracy, Total percobaan

---

## 3. SUB-MENU: EVALUASI LATENSI

### 3.1 Tujuan
Menampilkan data performa waktu inferensi model MobileFaceNet pada berbagai perangkat.

### 3.2 Konten Halaman

#### A. Penjelasan Rumus
```
┌─────────────────────────────────────────────────────────────────────┐
│ RUMUS WAKTU INFERENSI                                                │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ t_infer = t_selesai - t_mulai                                       │
│                                                                      │
│ Keterangan:                                                          │
│ • t_infer   = Durasi inferensi model (milidetik)                    │
│ • t_mulai   = Timestamp saat model mulai dijalankan                 │
│ • t_selesai = Timestamp saat embedding selesai dihasilkan           │
│                                                                      │
│ Sumber Data:                                                         │
│ • Dicatat oleh aplikasi mobile setiap kali proses face verification │
│ • Disimpan di attendance_logs.inference_time_ms                      │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│ RUMUS RATA-RATA LATENSI                                              │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ rata-rata_latensi = (Σₖ₌₁ᴺ t_infer,k) / N                         │
│                                                                      │
│ Keterangan:                                                          │
│ • N           = Jumlah percobaan inferensi                          │
│ • t_infer,k   = Waktu inferensi pada percobaan ke-k                │
│ • Σ (sigma)   = Penjumlahan seluruh waktu inferensi                 │
│                                                                      │
│ Statistik Tambahan:                                                  │
│ • Min = nilai t_infer terkecil                                      │
│ • Max = nilai t_infer terbesar                                      │
│ • P95 = persentil ke-95 (95% data di bawah nilai ini)              │
│ • Std Dev = standar deviasi (ukuran variasi)                        │
│                                                                      │
│ Sumber Data:                                                         │
│ • Dari semua record attendance_logs yang memiliki inference_time_ms │
│ • Dikelompokkan per device_model untuk perbandingan                 │
└─────────────────────────────────────────────────────────────────────┘
```

#### B. Data Tabel
| Kolom | Deskripsi |
|-------|-----------|
| No | Nomor urut |
| Tanggal/Waktu | Timestamp |
| Mahasiswa | Nama + NIM |
| Device Model | Model HP |
| OS Version | Versi Android |
| Inference Time (ms) | Waktu inferensi |
| Kategori Device | Low-end / Mid-range / High-end |

#### C. Tabel Summary per Device
| Device Model | Kategori | Jumlah Test | Min (ms) | Max (ms) | Rata-rata (ms) | P95 (ms) | Std Dev |
|---|---|---|---|---|---|---|---|
| Samsung A14 | Low-end | 50 | 180 | 350 | 245 | 320 | 42 |
| Samsung A54 | Mid-range | 80 | 95 | 200 | 132 | 175 | 28 |
| Samsung S24 | High-end | 30 | 45 | 110 | 72 | 98 | 18 |

#### D. Chart
- **Box Plot**: Distribusi latensi per device
- **Bar Chart**: Rata-rata latensi per device model
- **Line Chart**: Trend latensi over time
- **Histogram**: Distribusi waktu inferensi keseluruhan

---

## 4. SUB-MENU: EVALUASI KEHADIRAN & SP

### 4.1 Tujuan
Menampilkan analisis kehadiran dan efektivitas sistem early warning SP.

### 4.2 Konten Halaman

#### A. Penjelasan Rumus
```
┌─────────────────────────────────────────────────────────────────────┐
│ RUMUS PERSENTASE KEHADIRAN                                           │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ Persentase Kehadiran (%) = (Jumlah Hadir / Total Pertemuan) × 100% │
│                                                                      │
│ Keterangan:                                                          │
│ • Jumlah Hadir    = Sesi dimana status = hadir/hadir_terlambat      │
│ • Total Pertemuan = Jumlah sesi terjadwal dalam semester            │
│                                                                      │
│ Sumber Data:                                                         │
│ • Jumlah Hadir: COUNT dari tabel attendances WHERE status IN        │
│   ('hadir', 'hadir_terlambat') untuk user & semester tertentu       │
│ • Total Pertemuan: SUM dari jadwals.total_pertemuan untuk semua MK  │
│   yang diambil mahasiswa di semester tersebut                        │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│ RUMUS AKUMULASI ALPHA & PENENTUAN SP                                 │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ Total Alpha (jam) = Σ(alpha_menit per sesi) / 60                    │
│                                                                      │
│ Penentuan Status SP:                                                 │
│ • 0 - 15 jam      → AMAN                                           │
│ • 16 - 31 jam     → SP1                                            │
│ • 32 - 37 jam     → SP2                                            │
│ • 38 - 45 jam     → SP3                                            │
│ • ≥ 46 jam        → DO (Drop Out)                                  │
│                                                                      │
│ Sumber Data:                                                         │
│ • alpha_menit per sesi: dari tabel attendances.alpha_menit          │
│ • Dijumlahkan per mahasiswa per semester                            │
│ • Tersimpan di tabel alpha_accumulations                            │
│                                                                      │
│ Perhitungan alpha_menit per sesi:                                    │
│ • Hadir tepat waktu: 0 menit                                       │
│ • Terlambat: (waktu_checkin - jam_mulai) menit                     │
│ • Pulang awal: (jam_selesai - waktu_checkout) menit                │
│ • Alpha penuh: (jam_selesai - jam_mulai) menit = durasi MK         │
│ • Izin/Sakit (approved): 0 menit                                   │
└─────────────────────────────────────────────────────────────────────┘
```

#### B. Chart & Statistik
- **Stacked Bar**: Distribusi status SP per prodi
- **Line Chart**: Trend akumulasi alpha rata-rata per minggu
- **Pie Chart**: Distribusi status (Aman/SP1/SP2/SP3/DO)
- **Progress Bar per Mahasiswa**: Visual akumulasi alpha vs threshold
- **Heatmap**: Kehadiran per mahasiswa per minggu (warna = persentase)
- **Card Statistik**:
  - Total mahasiswa AMAN: X
  - Total SP1: X
  - Total SP2: X
  - Total SP3: X
  - Total DO: X
  - Rata-rata persentase kehadiran: X%

---

## 5. SUB-MENU: UJI SIMULTAN

### 5.1 Tujuan
Menampilkan hasil pengujian performa sistem saat digunakan oleh banyak mahasiswa secara bersamaan.

### 5.2 Konten Halaman

#### A. Penjelasan
```
┌─────────────────────────────────────────────────────────────────────┐
│ PARAMETER UJI SIMULTAN                                               │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ Pengujian dilakukan dengan skenario:                                 │
│ • 20 mahasiswa absen bersamaan                                      │
│ • 30 mahasiswa absen bersamaan                                      │
│ • 40 mahasiswa absen bersamaan                                      │
│                                                                      │
│ Parameter yang diukur:                                               │
│ • Response Time = waktu dari request dikirim sampai response        │
│   diterima (ms)                                                      │
│ • Success Rate = (jumlah berhasil / total percobaan) × 100%        │
│ • Failure Rate = (jumlah gagal / total percobaan) × 100%           │
│ • Timeout Rate = (jumlah timeout / total percobaan) × 100%         │
│ • Total Process Time = waktu dari buka app sampai absen selesai    │
│                                                                      │
│ Sumber Data:                                                         │
│ • Dari attendance_logs saat mode pengujian aktif                    │
│ • Dikelompokkan berdasarkan waktu (window 5 menit)                 │
│ • Concurrent users dihitung dari jumlah request dalam window        │
└─────────────────────────────────────────────────────────────────────┘
```

#### B. Tabel Hasil
| Skenario | Concurrent Users | Avg Response Time (ms) | Max Response Time (ms) | Success Rate | Failure Rate | Timeout Rate |
|----------|-----------------|----------------------|---------------------|-------------|-------------|-------------|
| Uji 1 | 20 | 450 | 1200 | 100% | 0% | 0% |
| Uji 2 | 30 | 680 | 1800 | 98% | 2% | 0% |
| Uji 3 | 40 | 950 | 2500 | 95% | 3% | 2% |

#### C. Chart
- **Line Chart**: Response time vs jumlah concurrent users
- **Bar Chart**: Success/Failure/Timeout rate per skenario
- **Box Plot**: Distribusi response time per skenario

---

## 6. SUB-MENU: PERBANDINGAN KONVENSIONAL VS SISTEM

### 6.1 Tujuan
Membandingkan performa sistem digital dengan metode presensi konvensional (kertas).

### 6.2 Konten Halaman

#### A. Penjelasan
```
┌─────────────────────────────────────────────────────────────────────┐
│ PARAMETER PERBANDINGAN                                               │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ 1. Waktu Proses Absensi                                             │
│    • Konvensional: diukur dengan stopwatch (dari mulai panggil      │
│      nama sampai semua tercatat)                                    │
│    • Sistem: dari buka app sampai absen berhasil                    │
│                                                                      │
│ 2. Akurasi Pencatatan                                                │
│    • Konvensional: dibandingkan dengan observasi langsung            │
│    • Sistem: otomatis (face verified + geofence valid)              │
│                                                                      │
│ 3. Potensi Human Error                                               │
│    • Konvensional: salah catat, lupa centang, salah hitung          │
│    • Sistem: 0 (otomatis)                                           │
│                                                                      │
│ 4. Waktu Rekapitulasi                                                │
│    • Konvensional: waktu dari selesai absen sampai data bisa direkap│
│    • Sistem: real-time (langsung tersedia di dashboard)             │
│                                                                      │
│ Sumber Data Konvensional:                                            │
│ • Diinput manual oleh Super Admin berdasarkan observasi lapangan    │
│ • Menggunakan form input di halaman ini                             │
└─────────────────────────────────────────────────────────────────────┘
```

#### B. Form Input Data Konvensional (Super Admin)
- Tanggal observasi
- Mata kuliah
- Jumlah mahasiswa
- Waktu proses absensi (menit:detik)
- Jumlah kesalahan pencatatan
- Waktu rekapitulasi (jam)
- Catatan

#### C. Tabel Perbandingan
| Parameter | Konvensional | Sistem Digital | Peningkatan |
|-----------|-------------|---------------|-------------|
| Waktu absensi (40 mhs) | 8-12 menit | 1-2 menit | 80% lebih cepat |
| Akurasi pencatatan | 95% | 99.5% | +4.5% |
| Human error | 3-5 per sesi | 0 | 100% eliminasi |
| Waktu rekapitulasi | 2-3 hari | Real-time | Instant |
| Deteksi kecurangan | Tidak bisa | Otomatis | - |
| Early warning SP | Manual (terlambat) | Otomatis (real-time) | - |

---

## 7. SUB-MENU: DOKUMENTASI TEKNIS

### 7.1 Tujuan
Menampilkan penjelasan lengkap semua rumus, variabel, dan proses teknis yang digunakan dalam sistem.

### 7.2 Konten (Accordion/Tab)

#### Tab 1: Pra-Pemrosesan Citra
```
Konversi YUV ke RGB:
R = round(y + v × 1436/1024 - 179)
G = round(y - u × 46549/131072 + 44 - v × 93604/131072 + 91)
B = round(y + u × 1814/1024 - 227)

Penjelasan variabel:
• y = komponen luminance (kecerahan piksel)
• u, v = komponen chrominance (informasi warna)
• Koefisien (1436/1024, dll) = faktor konversi standar YUV→RGB
• Konstanta (-179, -227) = offset penyesuaian chroma

Proses:
1. Kamera HP menghasilkan frame dalam format YUV420
2. Setiap piksel dikonversi ke RGB menggunakan rumus di atas
3. Nilai RGB dibatasi pada rentang [0, 255]
```

#### Tab 2: Normalisasi Input
```
x_norm = (x - 127.5) / 127.5

Penjelasan:
• x = nilai piksel asli (0-255)
• 127.5 = titik tengah rentang piksel
• x_norm = nilai ternormalisasi (-1 sampai +1)

Tujuan:
• Mengubah distribusi data agar centered di 0
• Mempercepat konvergensi saat training model
• Standar input untuk MobileFaceNet
```

#### Tab 3: MobileFaceNet Architecture
```
Input: Tensor [1, 112, 112, 3]
  - 1 = batch size
  - 112 × 112 = resolusi citra (piksel)
  - 3 = channel RGB

Arsitektur:
  - Depthwise Separable Convolution
  - Global Depthwise Convolution (GDC)
  - Linear bottleneck + Inverted residuals

Output: Tensor [1, 192]
  - 192 = dimensi embedding wajah
  - Setiap wajah direpresentasikan sebagai vektor 192 angka float
  - Vektor ini unik untuk setiap individu

Format model: TensorFlow Lite (.tflite)
  - Ukuran model: ~5 MB
  - Optimized untuk mobile inference
```

#### Tab 4: Euclidean Distance & Threshold
```
d(e, t) = √(Σᵢ₌₁¹⁹² (eᵢ - tᵢ)²)

match = (d(e,t) < θ)

Penjelasan lengkap:
• Setiap dimensi embedding dibandingkan satu per satu
• Selisih dikuadratkan untuk menghilangkan nilai negatif
• Dijumlahkan semua 192 dimensi
• Diakarkan untuk mendapat jarak sebenarnya
• Dibandingkan dengan threshold θ

Penentuan threshold optimal:
• Terlalu kecil → banyak false reject (user sah ditolak)
• Terlalu besar → banyak false accept (impostor diterima)
• Optimal: titik dimana FAR dan FRR seimbang (EER)
```

#### Tab 5: Geofencing (Haversine)
```
Penjelasan lengkap rumus Haversine + implementasi Flutter
```

#### Tab 6: Perhitungan SP
```
Penjelasan lengkap mekanisme akumulasi alpha + threshold SP
```

#### Tab 7: Metrik Evaluasi (FAR, FRR, Latensi)
```
Penjelasan lengkap semua metrik + cara pengukuran
```
