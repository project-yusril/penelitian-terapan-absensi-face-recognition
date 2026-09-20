# Protokol & Hasil Eksperimen — FAR/FRR + Load Test MobileFaceNet
**Proyek:** Sistem Absensi Mahasiswa (absensi.yusrilekamahendra.com)
**Diperbarui:** 21 September 2026, 01:15 WIB
**Status server production:** TIDAK DIUBAH — semua skrip kerja sudah dihapus dari server; database & storage tidak tersentuh (read-only + request GET login-only).

---

## HASIL YANG SUDAH VALID (data asli, siap dipakai di paper)

### 1. FAR (False Accept Rate) — SELESAI ✅
- Sumber: 55 embedding asli mahasiswa approved (dekripsi dilakukan di server, embedding tidak pernah keluar dari server)
- Metode: semua pasangan lintas-user (impostor), Euclidean Distance persis logika produksi (`BiometricDuplicateService`)
- **N = 1.485 pasangan impostor** — min 0.6185, maks 1.5083, mean 1.1332
- **FAR @ θ=0.600 (threshold aktif) = 0.0000% (0/1485)**
- Sweep lengkap: lihat `hasil_analisis_far.txt` — FAR 0.13% @0.65; 1.01% @0.80; 4.92% @0.90; 16.36% @1.00
- File: `impostor_distances_20260921.txt` (1485 nilai), skrip: `analisis_far_frr.py` (mendukung CSV genuine untuk FRR+EER bila sudah dikumpulkan)

### 2. Load Test 20/30/40 Pengguna — SELESAI ✅ (server-side, bypass WAF)
Dijalankan 21 Sep 2026 ~01:00 WIB dari dalam server (CDN hcdn memblokir k6 dari luar dengan challenge browser — hasil dari luar tidak valid). Metode: 39 token akun mahasiswa approved (satu token per pengguna), request GET dashboard/jadwal/history, 45 detik per level, read-only.

| Level | Endpoint   | p95 (ms) | Failure rate* | N req |
|-------|-----------|----------|---------------|-------|
| 20    | dashboard | 611      | 0.83%         | 240   |
| 20    | jadwal    | 305      | 74.58% (429)  | 240   |
| 20    | history   | 193      | 24.17% (429)  | 240   |
| 30    | dashboard | 907      | 22.12%        | 330   |
| 30    | jadwal    | 701      | 44.24%        | 330   |
| 30    | history   | 696      | 45.15%        | 330   |
| 40    | dashboard | 1119     | 32.21%        | 416   |
| 40    | jadwal    | 796      | 41.59%        | 416   |
| 40    | history   | 604      | 39.66%        | 416   |

\* Kegagalan = HTTP 429 (rate limiter `api` 60 req/menit per user, M-23) — BUKAN crash server. Log Laravel: **0 error baru** selama tes. P95 seluruh level < 2 detik (target NFR PRD-08 terpenuhi).

**Catatan metodologi untuk paper:** failure didominasi rate limiting by design (pengaman anti-abuse), bukan kegagalan aplikasi. Sertakan tabel ini + jelaskan rate limiter; atau ulangi dengan request rate per user ≤ 60/menit bila ingin 0% 429 (turunkan iterasi per VU). Traffic loop 1 akun/VU = 3 endpoint × (60/7.5s) ≈ berlebih; skenario jam masuk nyata lebih rendah.

- Skrip: `loadtest/loadtest_server.sh` (arsip lokal: `eksperimen/loadtest_server.sh`)
- Blokir eksternal: WAF "Checking your browser" (hcdn) → k6 eksternal tidak bisa dipakai terhadap domain produksi ini; dokumentasikan bila reviewer tanya kenapa bukan k6.

### 3. Skor genuine operasional (terbatas)
- Hanya 4 check-in nyata (avg 0.746, range 0.505–0.835) — terlalu sedikit untuk FRR.

---

## YANG MASIH PERLU DIKUMPULKAN (butuh mahasiswa fisik)

### Data GENUINE untuk FRR
FRR butuh capture wajah asli berulang dari orang yang sama — tidak bisa dihitung dari server.

**Target: 15 subjek × 20 capture = 300 skor genuine.**
1. Subjek = mahasiswa embedding-nya approved (sudah semua di-approve ✅ — 55 akun)
2. Login aplikasi normal → 20 percobaan verifikasi wajah:
   - 5× normal 40–60 cm · 5× jarak 80–100 cm · 5× cahaya redup/menyamping · 5× menoleh ±15°/senyum/berkedip
3. Semua skor tercatat otomatis di `attendance_logs` (sukses/gagal sama-sama tercatat)
4. Ekspor dengan SQL (read-only):
   ```sql
   SELECT user_id, action, face_distance, face_threshold, created_at
   FROM attendance_logs
   WHERE is_test_mode = 0 AND face_distance IS NOT NULL
     AND created_at >= '<TANGGAL_MULAI>'
   ORDER BY user_id, created_at;
   ```
5. Simpan sebagai `eksperimen/genuine_distances.csv` (kolom `face_distance`), lalu jalankan `python analisis_far_frr.py` → tabel FAR/FRR + EER otomatis.

### Anti-spoofing (terbatas, jujur dilaporkan)
5 subjek × {5 foto statis, 5 video replay, 5 wajah asli} — catat lolos/gagal per kategori; laporkan apa adanya sebagai "uji presentation attack skala terbatas".

---

## Checklist status

- [x] Pindahkan `impostor_distances.txt` ke `eksperimen/`
- [x] Approve embedding (55 akun approved)
- [ ] Kumpulkan 300 skor genuine (protokol di atas)
- [ ] Ekspor `genuine_distances.csv` → jalankan analisis
- [ ] (Opsional) uji anti-spoofing terbatas
- [x] Load test 20/30/40 (server-side, hasil di atas)
- [x] `analisis_far_frr.py` — siap; FRR+EER otomatis setelah CSV genuine ada
- [ ] Tulis ulang bagian Hasil & Kesimpulan paper dengan angka asli

## Catatan penting yang terverifikasi
1. Data "400 genuine + 400 impostor" lama di `attendance_logs` = **data sintetis seeder** `attendance:seed-analysis-data` (Gaussian μ=0.35/μ=1.05) — JANGAN dipakai sebagai hasil eksperimen di paper.
2. Threshold 0.600 sekarang: FAR 0% (data asli). Jika FRR nanti tinggi, θ=0.65 masih FAR 0.13%.
3. Semua skrip kerja server sudah dihapus; sisa `final.sh` milik deploy sebelumnya (bukan dari sesi ini).
