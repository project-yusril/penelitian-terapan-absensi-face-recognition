# ANALISIS GAP DASHBOARD WEB vs DOKUMEN PRD

> **ARSIP HISTORIS.** Gap dashboard di dokumen ini adalah snapshot lama dan
> sebagian besar halaman telah dibangun. Gunakan
> [CURRENT-ARCHITECTURE.md](CURRENT-ARCHITECTURE.md) dan [temuan.md](temuan.md).

**Tanggal:** 18 Juni 2026
**Konteks:** Dashboard web admin lama (SPA terpisah di `dashboard/dist`) hilang. Kita
membangun ulang dashboard menyatu di backend (Laravel + Inertia + Vue + Tailwind).
Dokumen ini memetakan **fitur mana yang SUDAH ada UI web-nya** dan **mana yang belum**,
dibandingkan dengan PRD-02, PRD-02B, dan `routes/api.php`.

> **Kesimpulan singkat:** Backend API hampir lengkap (semua endpoint ada). Yang
> kurang adalah **halaman web (UI) di dashboard**. Dashboard hasil rebuild baru
> menutup **6 modul** dari total ~20 modul web yang dibutuhkan PRD. Aplikasi mobile
> (Flutter) TIDAK terdampak.

---

## A. SUDAH DIBANGUN (Dashboard rebuild) ✅

| Modul | Halaman Web | API | Status |
|-------|-------------|-----|--------|
| Auth (login/logout session) | `Auth/Login` | — (session) | ✅ |
| Dashboard Admin | `Dashboard` | — (web controller) | ✅ |
| Manajemen Pengguna (mahasiswa+dosen+staf) | `Users/Index` | `api/admin/users` | ✅ CRUD |
| Program Studi | `Prodi/Index` | `api/admin/prodi` | ✅ CRUD |
| Mata Kuliah | `MataKuliah/Index` | `api/admin/mata-kuliah` | ✅ CRUD |
| Jadwal Perkuliahan | `Jadwal/Index` | `api/admin/jadwal` | ✅ CRUD |
| Monitoring Kehadiran | `Attendance/Index` | — (web controller) | ✅ read-only |

---

## B. BELUM DIBANGUN — API SUDAH ADA, UI WEB BELUM ❌

> Semua ini punya endpoint API yang siap dipakai, hanya perlu halaman web + web controller.

### B.1 Manajemen Akademik (Admin) — PRD FR-AKAD
| Fitur | FR | API tersedia | UI Web |
|-------|----|-----|--------|
| CRUD Tahun Ajaran | FR-AKAD-001 | `api/admin/tahun-ajaran` | ❌ belum |
| CRUD Semester | FR-AKAD-002 | `api/admin/semester` | ❌ belum |
| CRUD Geofence (+peta) | FR-AKAD-005 | `api/admin/geofence` | ❌ belum |
| Enroll/Unenroll mhs ke MK | FR-AKAD-006 | `api/admin/mata-kuliah/{id}/enroll` | ❌ belum |
| Import/Export mahasiswa (Excel) | FR-AKAD-006 | `api/admin/users/import` & `/export` | ❌ belum |

### B.2 Konfigurasi Sistem — PRD FR-CONFIG
| Fitur | FR | API tersedia | UI Web |
|-------|----|-----|--------|
| Setting sistem global | FR-CONFIG-003 | `api/admin/settings` | ❌ belum |
| Setting per-prodi (toleransi, threshold SP, face θ, geofence) | FR-CONFIG-001/002/004 | `api/admin/prodi/{id}/settings` | ❌ belum |

### B.3 Surat Peringatan (SP) — PRD FR-SP
| Fitur | FR | API tersedia | UI Web |
|-------|----|-----|--------|
| Daftar & generate SP (Admin) | FR-SP-003 | `api/admin/sp-records` (+generate) | ❌ belum |
| Kirim SP ke Kaprodi | FR-SP-003 | `api/admin/sp-records/{id}/send-to-kaprodi` | ❌ belum |
| Download PDF SP | FR-SP-003 | `api/admin/sp-records/{id}/download` | ❌ belum |
| TTD digital Kaprodi | FR-SP-004 | `api/kaprodi/sp-records/{id}/sign` | ❌ belum |
| TTD digital Ketua Jurusan | FR-SP-004 | `api/kajur/sp-records/{id}/sign` | ❌ belum |
| Riwayat SP | FR-SP-005 | `api/*/sp-records/{id}` | ❌ belum |

### B.4 Approval Workflow — PRD FR-ENROLL / FR-IZIN
| Fitur | FR | API tersedia | UI Web |
|-------|----|-----|--------|
| Approval Enrollment wajah (Kaprodi) | FR-ENROLL-002 | `api/kaprodi/enrollments` (+approve/reject) | ❌ belum |
| Approval Re-enrollment (Kaprodi) | FR-ENROLL-003 | `api/kaprodi/re-enrollments` | ❌ belum |
| Approval Izin/Sakit (Kaprodi) | FR-IZIN-002 | `api/kaprodi/leave-requests` | ❌ belum |

### B.5 Modul Dosen — PRD FR-DOSEN
| Fitur | FR | API tersedia | UI Web |
|-------|----|-----|--------|
| Daftar MK diampu + mahasiswa | FR-REKAP-004 | `api/dosen/mata-kuliah` | ❌ belum |
| Approve/Reject pending kehadiran | FR-DOSEN-001 | `api/dosen/attendance/{id}/approve|reject` | ❌ belum |
| Override manual kehadiran | FR-DOSEN-002 | `api/dosen/attendance/{id}/override` | ❌ belum |
| Rekap kehadiran per MK | FR-REKAP-006 | `api/dosen/attendance/rekap/{mkId}` | ❌ belum |

### B.6 Monitoring & Laporan — PRD FR-REKAP
| Fitur | FR | API tersedia | UI Web |
|-------|----|-----|--------|
| Rekap per mahasiswa | FR-REKAP-005 | `api/admin/reports/by-mahasiswa` | ❌ belum |
| Rekap per mata kuliah | FR-REKAP-006 | `api/admin/reports/by-mata-kuliah` | ❌ belum |
| Rekap per kelas | FR-REKAP-007 | `api/admin/reports/by-kelas` | ❌ belum |
| Rekap per prodi/jurusan | FR-REKAP-003 | `api/admin/reports/by-prodi|by-jurusan` | ❌ belum |
| Export PDF/Excel | FR-REKAP-008 | `api/admin/reports/export/pdf|excel` | ❌ belum |
| Audit Trail | — | `api/admin/audit-trail` | ❌ belum |

### B.7 Notifikasi In-App (Web) — PRD FR-NOTIF-002
| Fitur | FR | API tersedia | UI Web |
|-------|----|-----|--------|
| Bell icon + badge counter | FR-NOTIF-002 | `api/notifications/unread-count` | ❌ belum |
| Halaman daftar notifikasi + mark-read | FR-NOTIF-002 | `api/notifications` (+read/read-all) | ❌ belum |

### B.8 Mode Pengujian & Analisis (Super Admin) — PRD FR-TEST / penelitian
| Fitur | FR | API tersedia | UI Web |
|-------|----|-----|--------|
| Toggle Test Mode + log + label | FR-TEST-001..003 | `api/admin/test-mode/*` | ❌ belum |
| Analisis FAR/FRR (sweep θ, EER) | penelitian | `api/admin/analysis/face-verification` | ❌ belum |
| Analisis Geofence | penelitian | `api/admin/analysis/geofence` | ❌ belum |
| Analisis Latensi | penelitian | `api/admin/analysis/latency` | ❌ belum |
| Uji Simultan | FR-TEST-004 | `api/admin/analysis/simultaneous-test` | ❌ belum |
| Perbandingan konvensional | penelitian | `api/admin/analysis/conventional-comparison` | ❌ belum |

### B.9 Dashboard Per-Peran — PRD FR-REKAP-001..004
| Dashboard | FR | API tersedia | UI Web |
|-----------|----|-----|--------|
| Dashboard Kaprodi | FR-REKAP-002 | `api/kaprodi/dashboard` | ❌ belum (sekarang semua role lihat dashboard admin generik) |
| Dashboard Ketua Jurusan | FR-REKAP-003 | `api/kajur/dashboard` | ❌ belum |
| Dashboard Dosen | FR-REKAP-004 | `api/dosen/dashboard` | ❌ belum |

---

## C. CATATAN PENTING

1. **Backend API tidak hilang** — semua controller API (`app/Http/Controllers/Api/...`)
   masih utuh. Yang hilang dulu hanya hasil build SPA front-end dashboard.
2. **Mobile (Flutter) tidak terdampak** sama sekali.
3. Dashboard rebuild memakai **session auth** (`web` guard) + Inertia, terpisah dari
   token Sanctum mobile. Untuk halaman web baru, ada 2 pilihan teknis:
   - **(Rekomendasi)** Web controller baru yang langsung query Eloquent + render Inertia
     (konsisten dengan 6 modul yang sudah dibuat). Tidak memanggil API via HTTP.
   - Memanggil API internal (lebih rumit karena beda guard).

---

## D. REKOMENDASI URUTAN PENGERJAAN (kalau dilanjutkan)

Berdasarkan dampak operasional & kebutuhan penelitian:

1. **Akademik dasar** — Tahun Ajaran, Semester, Geofence (melengkapi master data;
   Jadwal & MK sudah ada tapi butuh Semester/Geofence yang bisa dikelola).
2. **Approval workflow** — Enrollment, Re-enrollment, Leave Request (Kaprodi).
   Tanpa ini, mahasiswa tidak bisa di-approve untuk mulai absen dari web.
3. **Konfigurasi** — System settings + Prodi settings (toleransi, threshold SP, θ).
4. **SP** — daftar, generate, kirim, TTD Kaprodi/Kajur, download PDF.
5. **Dosen** — approval pending, override, rekap.
6. **Laporan & Export** — rekap + PDF/Excel.
7. **Notifikasi in-app** — bell + halaman.
8. **Dashboard per-peran** — Kaprodi, Kajur, Dosen.
9. **Test Mode & Analisis** — untuk bab Hasil penelitian (R-05/R-07 butuh ini).
10. **Audit Trail**.

> Total ±14 modul web tambahan. Bisa dikerjakan bertahap. Beri tahu saya mau mulai
> dari nomor berapa, atau "kerjakan semua berurutan".
