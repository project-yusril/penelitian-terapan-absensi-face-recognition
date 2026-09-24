# Revisi Artikel SIMTEK — ID Pengajuan 1848

Artikel: *Rancang Bangun Sistem Absensi Mahasiswa Berbasis Mobile dengan Integrasi Geolocation dan Face Recognition Menggunakan MobileFaceNet pada Program Studi D-III Teknik Informatika Politeknik Negeri Pontianak* — Yusril Eka Mahendra\*, Nurul Fadillah, Karfindo.

Keputusan editor (19 September 2026): **revisi artikel**, batas 1 bulan. Naskah revisi diunggah 24 September 2026. Draft yang direview ada di [`../../hasil-review.docx`](../../hasil-review.docx).

## Isi folder

| File | Keterangan |
|---|---|
| `hasil_revisi_1848.docx` | Naskah yang diunggah ke jurnal: komentar reviewer asli dipertahankan, 9 balasan per catatan, bagian yang direvisi distabilo dan diberi komentar (ketentuan editor) |
| `hasil_revisi_1848-preview-komentar.pdf` | Pratinjau naskah di atas beserta balon komentar |
| `artikel-simtek-revisi-C-bersih.docx` / `-preview.pdf` | Naskah yang sama tanpa stabilo dan komentar |
| `referensi-mendeley.bib` | 14 referensi, sama dengan pustaka Mendeley penulis |
| `gambar/` | Gambar baru 300 dpi: ERD (Gambar 2), diagram alur (Gambar 3), kurva FAR (Gambar 5) |
| `scripts/` | Pembuat ulang ketiga gambar |

## Ringkasan revisi

- **Catatan penulisan (7):** penulis korespondensi bertanda `*`; sitasi dan daftar pustaka berupa field Mendeley (gaya IEEE, font 8 pt) dengan nomor urut kemunculan; gambar dikurangi dari 11 menjadi 5 dan ditata satu kolom; tabel dan caption mengikuti template; persamaan (1)–(3) memakai Equation Editor.
- **Catatan substansi (2):** limitasi dinyatakan di abstrak (bukti lokasi/wajah dihitung di perangkat, liveness belum diuji terhadap serangan foto/video/deepfake); kesimpulan tidak lagi mengklaim peningkatan akurasi tanpa data pembanding.
- **Hasil yang ditambahkan:** FAR 0% pada θ = 0,60 dari 1.485 pasangan impostor (`eksperimen/impostor_distances_20260921.txt`), uji beban 20/30/40 pengguna dengan p95 < 2 s (`eksperimen/PROTOKOL_EKSPERIMEN.md`), jumlah test 255/936 backend dan 214 Flutter (`docs/temuan.md`), serta uji coba lapangan terbatas (22–23 September 2026, 16 check-in).
- Naskah 6 halaman sesuai batas template.

## Membuat ulang gambar

```bash
cd artikel/simtek-1848/scripts
python gambar_far.py      # butuh matplotlib; membaca eksperimen/impostor_distances_20260921.txt
python gambar_alur.py     # butuh matplotlib
python gambar_erd.py      # butuh Graphviz (neato di PATH atau GRAPHVIZ_BIN=<folder bin>) dan Pillow
```

Hasil tersimpan di `gambar/` dan identik dengan gambar di naskah.

## Catatan

- Sitasi di kedua `.docx` adalah field Mendeley yang terhubung ke pustaka Mendeley penulis. Setelah mengedit naskah, gunakan **Refresh** pada plugin Mendeley di Word.
- Data mentah uji coba lapangan dan script query server production **tidak** disimpan di repo ini; naskah hanya memuat angka agregat tanpa identitas mahasiswa.
