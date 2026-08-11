# SOP — Sesi Pengambilan Data Penelitian (R-05 & R-07)

> **Status 18 Juli 2026: DRAF, BELUM EXECUTABLE.** Instruksi lama belum mengikuti
> attendance permit, random activation credential, dan mixed offline contract.
> Jangan menjalankan load test pada production. Sebelum SOP dipakai, sediakan
> fixture/consent/script yang benar-benar ada, akun test terisolasi, permit per
> action/UUID, dan pengukuran eksternal dari load-test runner. Residual research
> validity mengikuti R-01 sampai R-05 di [temuan.md](temuan.md).
> Production attendance saat ini fail-closed sampai trusted verifier tersedia;
> SOP hanya boleh dijalankan pada environment penelitian non-production yang
> mengaktifkan compatibility mode secara eksplisit dan mengisolasi seluruh data test.

**Konteks:** Dua task riset di `docs/task-baru.md` belum bisa ditutup karena
butuh sesi data lapangan, bukan koding:

- **R-05** — Pipeline label *genuine* vs *impostor* untuk menghasilkan kurva
  FAR/FRR, EER, dan θ optimal MobileFaceNet.
- **R-07** — Uji beban simultan (20/30/40 pengguna konkuren) untuk mengukur
  response time & success rate per level konkurensi.

Infrastruktur kode + endpoint sudah lengkap (FIX-LOG-003). Dokumen ini berisi
SOP eksekusi sesi datanya. Estimasi total: **±1 hari kerja** (R-05 setengah hari
+ R-07 setengah hari).

---

## 1) PERSIAPAN UMUM (sekali saja)

### 1.1 Aktifkan Test Mode
Test Mode mengaktifkan logging `metadata->label`, `face_distance`,
`inference_time_ms`, dll ke tabel `attendance_logs` untuk diolah jadi
FAR/FRR/EER.

1. Login web sebagai `super_admin`.
2. Buka **Mode Pengujian** (sidebar → Sistem → Mode Pengujian).
3. Toggle switch → status `Aktif`.
4. Verifikasi: `system_settings.test_mode_enabled = '1'`.


### 1.2 Pastikan data dasar siap
- ≥ 5 mahasiswa **enrolled** (`enrollment_status = approved`) → ada
  `face_embedding` di DB. Cek di **Kaprodi → Approval Enrollment**.
- 1 jadwal aktif hari ini (atau buat jadwal dummy untuk pengujian).
- ≥ 1 geofence aktif yang mencakup lokasi pengujian.
- Threshold prodi (`face_threshold`) di **Settings → Per-Prodi**: pakai default
  `1.00` (akan dikalibrasi via sweep nanti).
- **Satu sesi = satu prodi.** Sejak R-04, filter `prodi_id` mempersempit dataset
  (bukan hanya threshold) dan atribusinya memakai prodi subjek
  (`users.prodi_id`). Pastikan seluruh peserta uji dalam satu sesi berasal dari
  prodi yang sama, atau catat prodi setiap peserta agar hasil dapat dipisahkan
  saat analisis. Angka gabungan lintas prodi tidak boleh dilaporkan sebagai
  hasil satu prodi.

### 1.3 Backup database (opsional tapi disarankan)
```powershell
cd backend
php artisan db:seed --class=BackupTestDataSeeder  # bila ada
# atau dump manual:
mysqldump -u root absensi_mahasiswa > ../backups/before-r05-r07.sql
```

---

## 2) R-05 — PIPELINE FAR/FRR (GENUINE vs IMPOSTOR)

### 2.1 Tujuan
Mengumpulkan ≥ **30 percobaan genuine** dan ≥ **30 percobaan impostor** agar
sweep θ (0.30–1.40) menghasilkan kurva FAR/FRR yang stabil.

### 2.2 Definisi label
| Label | Skenario | Hasil yang diharapkan |
|-------|----------|----------------------|
| `genuine` | Mahasiswa A login → verifikasi wajah A sendiri | distance ≤ θ (match) |
| `impostor` | Mahasiswa B login → verifikasi pakai wajah A (foto/orang lain) | distance > θ (no match) |

### 2.3 Prosedur eksekusi

**A. Genuine (≥ 30 sample, target 50):**
1. Mahasiswa enrolled membuka aplikasi → **Absensi → Check-in**.
2. Lakukan liveness + face capture seperti biasa.
3. Karena Test Mode aktif, backend otomatis menandai log
   (`is_test_mode = true`) lalu menampilkannya di tabel **Mode Pengujian →
   Log Verifikasi Belum Berlabel**.
4. Klik tombol **Genuine** pada baris log yang sesuai.

> Alternatif batch (untuk skrip otomatis): kirim header
> `X-Test-Label: genuine` atau sertakan `metadata.label = "genuine"` di
> payload check-in — backend menulis langsung ke `metadata->label`.

**B. Impostor (≥ 30 sample, target 50):**
1. Login mahasiswa A.
2. Saat verifikasi wajah, arahkan kamera ke **foto cetak** atau **wajah orang
   lain** (mahasiswa B yang juga sudah enrolled).
3. Ulangi minimal 30 kali dengan kombinasi A↔B berbeda. Percobaan yang
   ditolak (`face_not_match`) ikut tersimpan dengan `face_distance` di kolom
   sehingga tetap masuk sweep FAR/FRR.
4. Klik tombol **Impostor** pada baris log di tabel Mode Pengujian (atau
   pakai header `X-Test-Label: impostor` untuk batch).


> **Catatan privasi:** R-01 sudah memastikan foto wajah disimpan di disk
> privat dengan signed URL. Pastikan dokumen informed-consent ditandatangani
> peserta uji sebelum sesi (template di `docs/forms/consent-uji.docx`).

### 2.4 Verifikasi hasil & ambil θ optimal
1. Login web → **Sistem → Analisis**.
2. Periksa bagian "Kurva FAR vs FRR" — harus sudah muncul (sebelumnya placeholder).
3. Cek field response endpoint **per prodi** (R-04 — tanpa `prodi_id` hasilnya
   gabungan seluruh prodi dan tidak boleh dilaporkan sebagai hasil satu prodi):
   ```bash
   curl -H "Authorization: Bearer {token_super_admin}" ^
        "http://localhost:8000/api/admin/analysis/face-verification?prodi_id={id_prodi}"
   ```
   Pastikan terisi: `eer`, `optimal_threshold`, `sweep[]`. Periksa juga
   `test_data.genuine_count`/`impostor_count` benar-benar sesuai jumlah sampel
   prodi tersebut — bila sama dengan total seluruh sesi, berarti filter tidak
   terpakai. `prodi_id` yang salah menghasilkan `422`, bukan dataset kosong.
4. **Tulis hasil di laporan:**
   - Prodi, total genuine, impostor, EER, θ optimal. **Setiap angka wajib
     menyebut prodi asal dan θ yang dipakai.**
   - Tabel sweep θ (0.30, 0.35, …, 1.40) dengan FAR & FRR per titik.
   - Bila sesi mencakup lebih dari satu prodi, laporkan per prodi secara
     terpisah; jangan gabungkan dataset yang ambangnya berbeda.
   - Bandingkan dengan θ default `ProdiSetting.face_threshold`.
5. (Opsional) Update `ProdiSetting.face_threshold` ke θ optimal lewat
   **Settings → Per-Prodi**.

### 2.5 Kriteria sukses
- ≥ 30 sample per label.
- EER < 5% (target produksi yang wajar; lebih tinggi → tulis sebagai
  keterbatasan).
- Sweep tidak monoton (FAR turun saat θ kecil; FRR naik saat θ besar).

---

## 3) R-07 — UJI SIMULTAN (CONCURRENT LOAD)

### 3.1 Tujuan
Mengukur **response time** dan **success rate** sistem saat 20 / 30 / 40
pengguna check-in serempak. Hasil dipakai untuk bab "Kinerja & Skalabilitas"
laporan.

### 3.2 Pilihan tools
| Opsi | Kelebihan | Kekurangan |
|------|-----------|------------|
| **k6** (rekomendasi) | Script JavaScript, output JSON & metrics jelas | Perlu install Go-binary |
| Apache JMeter | UI grafis | Berat, butuh JDK |
| Skenario manual (5–10 device fisik) | Realistis | Sulit replikasi 40 user |

SOP ini pakai **k6**.

### 3.3 Persiapan token mahasiswa
Buat 40 token (1 per user uji). Gunakan endpoint `/api/auth/login`:

```powershell
$tokens = @()
for ($i = 1; $i -le 40; $i++) {
    $email = "uji$i@stmik.ac.id"
    # Ambil credential unik dari secret store test; jangan hard-code atau memakai NIM/NIDN.
    $body = @{ login = $email; password = $env:LOAD_TEST_PASSWORD; device_name = "load-$i" } | ConvertTo-Json
    $r = Invoke-RestMethod -Uri "http://localhost:8000/api/auth/login" -Method Post -ContentType "application/json" -Body $body
    $tokens += $r.data.token
}
$tokens | Out-File -FilePath ../tokens.txt
```

> Asumsi: 40 mahasiswa uji sudah di-seed dengan email `uji1@…uji40@stmik.ac.id`
> via `database/seeders/LoadTestSeeder.php`. Bila belum, tambahkan seeder ini
> sebelum sesi.

### 3.4 Skrip k6 (`docs/load-test/r07.js`)

```javascript
import http from 'k6/http';
import { check, sleep } from 'k6';

const tokens = open('../tokens.txt').split('\n').filter(t => t.length > 0);
const concurrentLevel = __ENV.LEVEL || 20;

export const options = {
  scenarios: {
    burst: {
      executor: 'shared-iterations',
      vus: parseInt(concurrentLevel),
      iterations: parseInt(concurrentLevel),
      maxDuration: '60s',
    },
  },
};

export default function () {
  const t = tokens[__VU - 1];
  const payload = JSON.stringify({
    jadwal_id: 1, mata_kuliah_id: 1,
    latitude: -6.200000, longitude: 106.816666,
    gps_accuracy: 5, mock_location_detected: false,
    liveness_passed: true,
    face_distance: 0.5,
    inference_time_ms: 320,
    device_model: 'k6-test',
    device_os: 'linux',
    app_version: '1.0.0',
    client_uuid: `r07-${__VU}-${Date.now()}`,
    metadata: { concurrent_level: parseInt(concurrentLevel), success: true, latency_ms: 0 },
  });

  const start = Date.now();
  const res = http.post(
    'http://localhost:8000/api/mahasiswa/attendance/check-in',
    payload,
    { headers: { Authorization: `Bearer ${t}`, 'Content-Type': 'application/json' } }
  );
  const latency = Date.now() - start;

  check(res, {
    'status 200/201': (r) => r.status === 200 || r.status === 201,
    'latency < 3s': () => latency < 3000,
  });
  sleep(1);
}
```

### 3.5 Eksekusi 3 level

```powershell
# Pastikan php artisan serve sedang jalan di terminal lain.
cd docs/load-test

k6 run -e LEVEL=20 r07.js > result-20.txt
k6 run -e LEVEL=30 r07.js > result-30.txt
k6 run -e LEVEL=40 r07.js > result-40.txt
```

### 3.6 Verifikasi & analisis

1. Buka web **Sistem → Analisis** → bagian "Uji Simultan (Concurrent)".
   Tabel per-level harus terisi (avg/max latency, success rate).
2. Endpoint:
   ```
   GET /api/admin/analysis/simultaneous-test
   ```
   Field `per_concurrent_level` = `{20: {...}, 30: {...}, 40: {...}}`.
3. Tulis di laporan:
   - Tabel level vs avg latency vs P95 vs success rate.
   - Bottleneck (CPU/DB/RAM) dari log `php artisan serve` & `mysqld`.
   - Apakah sistem memenuhi SLA (mis. P95 < 2s, success ≥ 95%).

### 3.7 Kriteria sukses
- 3 level (20/30/40) terekam.
- Success rate ≥ 95% di level 20, ≥ 90% di level 40.
- P95 latency ≤ 2s.

---

## 4) PASCA-SESI

1. **Nonaktifkan Test Mode** kembali (Mode Pengujian → Nonaktifkan).
2. (Opsional) Bersihkan data uji:
   ```sql
   DELETE FROM attendance_logs WHERE metadata->>'$.label' IN ('genuine','impostor');
   DELETE FROM attendance_logs WHERE metadata->>'$.concurrent_level' IS NOT NULL;
   ```
3. Update `docs/task-baru.md`:
   - `[x] R-05 ✅ SELESAI` + ringkasan EER & θ optimal.
   - `[x] R-07 ✅ SELESAI` + ringkasan latency per level.
4. Update tabel ringkasan progress: 35/35.
5. Tambahkan grafik sweep & tabel level ke bab Hasil & Pembahasan.

---

## 5) TROUBLESHOOTING

| Gejala | Kemungkinan penyebab | Solusi |
|--------|----------------------|--------|
| `eer = null` di response analisis | < 1 sample genuine atau impostor | Tambah sample, cek query `whereJsonContains` di `Admin/AnalysisController` |
| `genuine_count`/`impostor_count` jauh lebih kecil dari jumlah sampel sesi | Filter `prodi_id` aktif dan sebagian peserta berasal dari prodi lain (R-04) | Cek prodi tiap peserta; jalankan per prodi, jangan gabungkan dataset yang ambangnya berbeda |
| `403` saat membuka analisis | Aktor bukan `super_admin` sehingga scope dipaksa ke prodinya sendiri, atau aktor tingkat prodi tanpa `prodi_id` (M-24) | Jalankan sesi analisis sebagai `super_admin`, atau pastikan aktor punya `prodi_id` dan hanya meminta prodinya sendiri |
| Semua check-in gagal saat load test | Rate limiter. `throttle:attendance` 10/menit **per user** membatasi check-in; `throttle:api` 60/menit per user membatasi endpoint terautentikasi lain (M-23) | Naikkan limit di `AppServiceProvider::configureRateLimiting()` untuk durasi sesi, lalu **kembalikan setelah sesi**. Karena keying per user, menambah akun uji lebih realistis daripada menaikkan limit. Jangan mencari `app/Http/Kernel.php` — file itu tidak ada pada Laravel 11+ |
| `inference_time_ms = null` | Mobile tidak mengirim field | Pastikan FIX-LOG-003 R-06 sudah deploy ke device uji |
| `concurrent_level` tidak ter-grouping | `metadata` JSON path salah | Verifikasi MySQL ≥ 5.7 mendukung `JSON_EXTRACT` |
| Foto wajah enrollment kosong | Disk privat tidak writable | Cek izin folder `storage/app/face` |

---

## 6) KONTAK & ESKALASI

- **Bug pipeline:** Cek `storage/logs/laravel.log` setelah eksekusi.
- **Kelainan threshold:** Periksa `ProdiSetting.face_threshold` & L2-normalize
  embedding (M-07).
- **Kelainan data konvensional (R-08):** Pakai `POST /api/admin/analysis/conventional-data`
  untuk input manual.

---

> Ditulis 19 Juni 2026 — selaras dengan FIX-LOG-003 (16 Juni 2026).
> Setelah sesi selesai, semua task riset di `docs/task-baru.md` Bagian E
> tertutup penuh dan laporan penelitian siap finalisasi.
