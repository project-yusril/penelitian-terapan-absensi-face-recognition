# Matriks Role, Permission, dan Prodi (MS-01)

> **Status: current, sumber kebenaran authorization.** Dokumen ini menutup
> MS-01 pada [temuan.md](temuan.md). Dipakai sebagai daftar acuan saat
> mengaudit kelengkapan negative test lintas role dan lintas prodi (Definition
> of Done "Authorization matrix memiliki negative tests").
>
> Pembaruan terakhir: 11 Agustus 2026.

## Cara Dokumen Ini Diturunkan

Matriks ini **tidak ditulis tangan dari ingatan**. Isinya diturunkan dari tabel
route aktual dan dapat direproduksi:

```bash
cd backend
php artisan route:list --json
```

Guard role berasal dari middleware `CheckRole` (API) dan `EnsureUserRole`
(web). Bila sebuah route berada dalam beberapa group bertingkat, role efektif
adalah **irisan** seluruh guard pada route tersebut — bukan gabungannya.
Contoh: `/analysis` berada di group dashboard enam role lalu dipersempit lagi
menjadi `super_admin`, sehingga role efektifnya `super_admin` saja.

Bila route berubah, jalankan ulang perintah di atas dan perbarui dokumen ini.
Aturan pemeliharaan ada di [README.md](README.md).

## Tiga Lapis Enforcement

Guard role saja **tidak cukup** dan tidak boleh dijadikan satu-satunya bukti
authorization. Setiap request sensitif melewati tiga lapis:

| Lapis | Mekanisme | Menjawab pertanyaan |
|---|---|---|
| 1. Guard role | `role:` / `web.role:` middleware | Boleh menyentuh endpoint ini? |
| 2. Object policy | `AuthorizationService::assertCan*`, service workflow terkunci | Boleh menyentuh **record ini**? |
| 3. Query scope | `AuthorizationService::scope*` | Baris mana yang boleh terlihat? |

C-02, C-03, dan C-07 semuanya adalah kasus lapis 1 lolos tetapi lapis 2 atau 3
tidak ada. Karena itu penambahan route baru wajib menyebut lapis mana yang
menjaganya.

## Role Canonical

Delapan role sesuai `RoleSeeder`. Enam pertama adalah pengguna dashboard web;
`mahasiswa` dan `orang_tua` hanya memakai API mobile.

| Role | Cakupan data | Permukaan |
|---|---|---|
| `super_admin` | Global, seluruh prodi | Web + API |
| `ketua_jurusan` | Prodi aktor (`prodi_id`) | Web + API |
| `admin_jurusan` | Prodi aktor (`prodi_id`) | Web + API |
| `kaprodi` | Prodi aktor (`prodi_id`) | Web + API |
| `admin_prodi` | Prodi aktor (`prodi_id`) | Web + API |
| `dosen` | Mata kuliah yang diampu | Web + API |
| `mahasiswa` | Diri sendiri | API |
| `orang_tua` | Anak yang tertaut | API |

> **Catatan `admin_jurusan`.** Schema belum memiliki entitas jurusan, sehingga
> `admin_jurusan` sementara dibatasi ke satu prodi seperti `admin_prodi`
> (keputusan C-02). Ini fail-closed dan disengaja; jangan "diperbaiki" menjadi
> akses global tanpa entitas jurusan yang sebenarnya.

## Aturan Scope Query

Sumber: `App\Services\AuthorizationService`. Filter dari request **hanya boleh
mempersempit** scope, tidak pernah memperluasnya (H-21).

| Scope | `super_admin` | Role tingkat prodi | `dosen` | Role lain |
|---|---|---|---|---|
| `scopeUsers` | Semua | `prodi_id` aktor | Mahasiswa pada MK yang diampu | Ditolak semua |
| `scopeProdis` | Semua | Prodi aktor saja | Prodi dari MK yang diampu | Ditolak semua |
| `scopeMataKuliahs` | Semua | `prodi_id` aktor | `dosen_id` aktor | Ditolak semua |
| `scopeAttendances` | Semua | Lewat `mataKuliah.prodi_id` | Lewat `mataKuliah.dosen_id` | Ditolak semua |

"Role tingkat prodi" = `ketua_jurusan`, `admin_jurusan`, `kaprodi`,
`admin_prodi`. Aktor tingkat prodi **tanpa** `prodi_id` selalu fail-closed
(`WHERE 1 = 0`), bukan jatuh ke akses global.

### Atribusi prodi tidak seragam — dan itu disengaja

| Konteks | Atribusi | Alasan |
|---|---|---|
| Authorization (`scopeAttendances`) | `mataKuliah.prodi_id` | Kepemilikan akademik record |
| Dataset analisis (R-04) | `users.prodi_id` (prodi subjek) | Harus sama dengan sumber `face_threshold` runtime |

Keduanya benar untuk tujuannya masing-masing. Jangan menyatukan keduanya tanpa
memutuskan ulang mana yang otoritatif per konteks.

## Hierarki Role dan Assignability

Sumber: `AuthorizationService::ASSIGNABLE`. Role yang tidak terdaftar tidak
dapat membuat atau mengubah user sama sekali.

| Aktor | Boleh menetapkan role |
|---|---|
| `super_admin` | Seluruh delapan role |
| `admin_jurusan` | `admin_prodi`, `dosen`, `mahasiswa`, `orang_tua` |
| `admin_prodi` | `dosen`, `mahasiswa`, `orang_tua` |
| Lainnya | — (tidak dapat mengelola user) |

Invarian tambahan (C-02): target dengan role setara atau lebih tinggi
dilindungi, self-mutation sensitif ditolak, dan `prodi_id` eksplisit `null`
tidak dapat dipakai untuk lolos dari scope prodi.

## Matriks Guard Route

Jumlah route dihitung per entri tabel route, termasuk varian method.

| Role | Route API | Route Web |
|---|---:|---:|
| `super_admin` | 100 | 91 |
| `admin_prodi` | 71 | 69 |
| `admin_jurusan` | 71 | 68 |
| `mahasiswa` | 19 | 0 |
| `kaprodi` | 15 | 43 |
| `dosen` | 9 | 26 |
| `ketua_jurusan` | 4 | 31 |
| `orang_tua` | 4 | 0 |

### Per domain

| Role efektif | Domain |
|---|---|
| Enam role dashboard | `dashboard`, `attendance`, `notifications`, `profile`, `logout`, `two-factor`, `push-subscriptions`, `private` |
| `super_admin`, `ketua_jurusan`, `admin_jurusan`, `kaprodi`, `admin_prodi` | `reports`, `sp` |
| `super_admin`, `admin_jurusan`, `admin_prodi` | Master akademik web (`users`, `prodi`, `mata-kuliah`, `jadwal`, `geofence`, `semester`, `tahun-ajaran`) dan seluruh `api/admin/*` |
| `super_admin`, `admin_jurusan` | `audit-trail` |
| `super_admin`, `admin_prodi`, `kaprodi` | `settings` |
| `super_admin`, `kaprodi` | `enrollments`, `re-enrollments`, `leave-requests`, `api/kaprodi/*` |
| `super_admin`, `ketua_jurusan` | `api/kajur/*` (dashboard, sp-records, sign) |
| `super_admin`, `dosen` | `dosen/*`, `api/dosen/*` |
| `super_admin` saja | `analysis`, `test-mode`, `maintenance`, `api/healthz` |
| `mahasiswa` saja | `api/mahasiswa/*` |
| `orang_tua` saja | `api/orang-tua/*` |

> **Asimetri yang disengaja.** `api/mahasiswa/*` dan `api/orang-tua/*` **tidak**
> menyertakan `super_admin`. Endpoint tersebut bekerja atas identitas pemanggil
> sendiri (absensi, enrollment, anak tertaut), sehingga super admin yang
> memanggilnya akan menghasilkan state atas nama dirinya, bukan pengawasan.
> Pengawasan lintas mahasiswa dilakukan lewat `api/admin/*` dan `api/kaprodi/*`.

## Guard Tambahan di Luar Role

| Guard | Route | Fungsi |
|---|---|---|
| `biometric.trusted` | 12 route: permit, check-in/out, sync-offline, enrollment, re-enrollment, embedding, approval enrollment/re-enrollment (API + web) | C-04/H-04: production selalu `503 TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED` sampai trusted verifier tersedia |
| `enrollment.approved` | 5 route: permit, check-in, check-out, sync-offline, embedding | Menahan absensi sampai embedding disetujui |
| `user.active` | Seluruh group terproteksi (API + web) | H-19: akun nonaktif ditolak walau token lama masih ada |
| `2fa` | Group web | Menegakkan TOTP saat aktif |
| `throttle:login` | `login`, `forgot-password`, `reset-password` (API + web) | M-21: brute force per IP+identitas |
| `throttle:api` | Seluruh group API terautentikasi | M-23: 60/menit per user |
| `throttle:auth-sensitive` | `POST /auth/change-password` | M-23: 5/menit per user, memverifikasi `current_password` |
| `throttle:attendance` / `upload` / `export` / `biometric-probe` | Route terkait | Batas khusus per domain; `biometric-probe` juga membatasi per IP (M-14) |

## Checklist Audit Negative Test

Dipakai untuk menilai kelengkapan, bukan sekadar keberadaan, negative test.

| Kelas serangan | Harus menghasilkan | Sudah tertutup |
|---|---|---|
| Role rendah memanggil endpoint role tinggi | 403 | Ya — C-02, C-07 |
| Aktor prodi A memutasi record prodi B | 403, record tidak berubah | Ya — C-03 |
| Aktor prodi A membaca daftar/laporan prodi B | Terfilter atau 403 | Ya — H-21 |
| Aktor prodi A membaca **analisis penelitian** prodi B | 403 | Ya — MS-01, `AnalysisProdiScopeTest` |
| Self-escalation ke role setara/lebih tinggi | 403 | Ya — C-02 |
| `prodi_id` eksplisit `null` untuk lolos scope | 403 | Ya — C-02 |
| Aktor tingkat prodi tanpa `prodi_id` | Fail-closed, bukan global | Ya — C-02, MS-01 |
| Token akun nonaktif | 403 dan token dicabut | Ya — H-19 |
| Transisi state SP di luar urutan | 409 | Ya — C-07 |
| Brute force credential | 429 | Ya — M-21, M-23 |

### Sisa yang belum tertutup

- **Belum ada test yang menegakkan matriks ini sebagai invarian.** Saat ini
  kelengkapan diperiksa manual terhadap tabel di atas. Test yang membaca tabel
  route lalu menegaskan setiap route non-publik memiliki guard role akan
  membuat route baru tanpa guard gagal otomatis; ini belum ada.
- **MS-03** (browser/device E2E enam role) belum berjalan, sehingga matriks ini
  baru terbukti pada level request, belum pada level sesi browser nyata.

## Riwayat

- 11 Agustus 2026 — dokumen dibuat (MS-01). Penyusunannya menemukan bahwa
  `api/admin/analysis/*` terbuka untuk `admin_jurusan`/`admin_prodi` tanpa scope
  aktor, sehingga role tingkat prodi dapat membaca statistik kehadiran,
  distribusi SP, dan sebaran verifikasi wajah prodi lain. Ditutup pada hari yang
  sama lewat `ScopesAnalysisDataset::resolveAnalysisProdiScope` beserta regression
  test; lihat [temuan.md](temuan.md) bagian MS-01.
