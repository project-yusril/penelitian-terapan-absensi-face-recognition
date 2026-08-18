# PRODUCT REQUIREMENTS DOCUMENT (PRD)
# Sistem Absensi Mahasiswa Berbasis Mobile
# Menggunakan Geolocation dan Face Recognition (MobileFaceNet)

**Versi**: 1.0
**Tanggal**: 27 Mei 2026
**Author**: Yusril Eka Mahendra, M.TI
**Status**: Draft

> **Interpretasi current 11 Agustus 2026:** bagian yang menyebut standalone Vue
> SPA, Laravel 11, deployment domain terpisah, mobile Dosen, atau iOS adalah
> desain awal. Release mobile saat ini Android-only untuk mahasiswa. Attendance
> dan enrollment production fail-closed; trusted verifier (C-04/H-04) di luar scope
> penelitian ([ADR-001](ADR-001-trusted-biometric-verifier.md) ditolak). FCM
> adalah opt-in dan default off. Implementasi saat ini dijelaskan di
> [CURRENT-ARCHITECTURE.md](CURRENT-ARCHITECTURE.md), kontrak API di
> [CURRENT-API.md](CURRENT-API.md), residual risk di [SECURITY.md](SECURITY.md),
> dan status acceptance di [temuan.md](temuan.md).

---

## DAFTAR ISI PRD

| No | Dokumen | File |
|----|---------|------|
| 1 | Overview, Tujuan, Scope, Hierarki Role, Tech Stack | PRD-01-overview.md |
| 2 | Functional Requirements (Fitur per Role) | PRD-02-functional-requirements.md |
| 3 | Database Design (ERD & Schema) | PRD-03-database-design.md |
| 4 | API Design (Backend Endpoints) | PRD-04-api-design.md |
| 5 | Flow Diagram (Alur Proses) | PRD-05-flow-diagram.md |
| 6 | UI/UX Design (Mobile & Web) | PRD-06-ui-ux-design.md |
| 7 | Menu Analisis & Evaluasi Sistem | PRD-07-analisis-evaluasi.md |
| 8 | Non-Functional Requirements & Security | PRD-08-non-functional.md |

---

## 1. OVERVIEW

### 1.1 Deskripsi Produk

Sistem Absensi Mahasiswa adalah platform terintegrasi yang terdiri dari aplikasi mobile (Flutter) dan web dashboard (Laravel + Vue.js) untuk mencatat kehadiran mahasiswa secara otomatis menggunakan teknologi face recognition berbasis deep learning (MobileFaceNet) dan validasi lokasi (geolocation/geofencing). Sistem ini dirancang untuk Program Studi di bawah Jurusan Teknik Elektro, Politeknik Negeri Pontianak.

### 1.2 Tujuan Sistem

1. Menggantikan sistem presensi manual (kertas) dengan sistem digital yang akurat dan real-time
2. Memvalidasi identitas mahasiswa menggunakan face verification (MobileFaceNet)
3. Memvalidasi lokasi mahasiswa menggunakan geofencing
4. Mencegah kecurangan absensi (titip absen, fake GPS, foto palsu) melalui liveness detection
5. Menghitung akumulasi ketidakhadiran secara otomatis (berbasis menit/jam)
6. Menyediakan mekanisme early warning system untuk SP1, SP2, SP3, dan DO
7. Menghasilkan dokumen SP yang dapat ditandatangani secara digital
8. Menyediakan dashboard monitoring dan rekapitulasi untuk semua level manajemen
9. Menyediakan menu Analisis & Evaluasi Sistem untuk kebutuhan penelitian

### 1.3 Scope Sistem

#### Dalam Scope:
- Aplikasi mobile (Flutter) untuk mahasiswa dan dosen
- Web dashboard (Vue.js) untuk Super Admin, Ketua Jurusan, Admin Jurusan, Kaprodi, Admin Prodi, dan Dosen
- Backend API (Laravel) sebagai penghubung semua platform
- Face verification on-device menggunakan MobileFaceNet (TFLite)
- Geofencing dengan deteksi mock location
- Active liveness detection (challenge-response)
- Sistem SP otomatis dengan dokumen digital
- Menu Analisis & Evaluasi Sistem (khusus Super Admin)
- Push notification (FCM)
- Export data (Excel/PDF)
- Mode pengujian untuk evaluasi FAR/FRR

#### Di Luar Scope:
- Face identification (1:N) — hanya face verification (1:1)
- Integrasi dengan SIAKAD existing
- Pembayaran/keuangan
- E-learning/LMS

---

## 2. HIERARKI ROLE & HAK AKSES

### 2.1 Struktur Organisasi

```
Super Admin (Owner/Peneliti)
│   → Kontrol penuh seluruh sistem
│
└── Ketua Jurusan (Teknik Elektro)
    │   → Decision maker, approve SP/DO, tanda tangan digital
    │
    ├── Admin Jurusan
    │   → Rekap administrasi lintas prodi, CRUD data level jurusan
    │
    ├── Prodi Teknik Listrik
    │   ├── Kaprodi → Monitoring prodi, tanda tangan SP
    │   ├── Admin Prodi → CRUD data akademik prodi
    │   ├── Dosen → Approve pending, override kehadiran
    │   ├── Mahasiswa → Absensi, riwayat, enrollment
    │   └── Orang Tua/Wali → Monitoring kehadiran anak
    │
    ├── Prodi Teknik Informatika
    │   ├── Kaprodi
    │   ├── Admin Prodi
    │   ├── Dosen
    │   ├── Mahasiswa
    │   └── Orang Tua/Wali
    │
    └── Prodi Teknik Elektro
        ├── Kaprodi
        ├── Admin Prodi
        ├── Dosen
        ├── Mahasiswa
        └── Orang Tua/Wali
```

### 2.2 Detail Hak Akses per Role

> **Sumber kebenaran authorization:** tabel di bawah adalah *product intent*.
> Hak akses yang benar-benar ditegakkan kode — guard per route, aturan scope
> query, hierarki assignability, dan guard non-role — ada di
> [ROLE-PERMISSION-MATRIX.md](ROLE-PERMISSION-MATRIX.md) (MS-01). Bila keduanya
> berbeda, matriks yang berlaku dan selisihnya harus dicatat sebagai temuan.
>
> Satu selisih yang sudah diketahui: `admin_jurusan` di bawah digambarkan
> lintas prodi, tetapi schema belum memiliki entitas jurusan sehingga
> implementasinya fail-closed ke satu prodi seperti `admin_prodi` (keputusan
> C-02).

#### Super Admin
| Fitur | Create | Read | Update | Delete |
|-------|--------|------|--------|--------|
| Semua data sistem | ✅ | ✅ | ✅ | ✅ |
| Manage semua user & role | ✅ | ✅ | ✅ | ✅ |
| Konfigurasi global sistem | ✅ | ✅ | ✅ | ✅ |
| Menu Analisis & Evaluasi | - | ✅ | - | - |
| Mode Pengujian (FAR/FRR) | ✅ | ✅ | ✅ | ✅ |
| Backup & restore data | ✅ | ✅ | - | - |

**Platform**: Web Dashboard

#### Ketua Jurusan
| Fitur | Create | Read | Update | Delete |
|-------|--------|------|--------|--------|
| Overview 3 prodi | - | ✅ | - | - |
| Monitoring SP seluruh jurusan | - | ✅ | - | - |
| Approve/tanda tangan DO | - | ✅ | ✅ | - |
| Diketahui pada dokumen SP | - | ✅ | ✅ | - |
| Laporan jurusan | - | ✅ | - | - |
| Perbandingan antar prodi | - | ✅ | - | - |

**Platform**: Web Dashboard

#### Admin Jurusan
| Fitur | Create | Read | Update | Delete |
|-------|--------|------|--------|--------|
| Data level jurusan | ✅ | ✅ | ✅ | ✅ |
| Rekap lintas prodi | - | ✅ | - | - |
| Export laporan jurusan | - | ✅ | - | - |
| Manage data umum (tahun ajaran) | ✅ | ✅ | ✅ | ✅ |

**Platform**: Web Dashboard

#### Kaprodi
| Fitur | Create | Read | Update | Delete |
|-------|--------|------|--------|--------|
| Monitoring mahasiswa prodi | - | ✅ | - | - |
| Tanda tangan SP1/SP2/SP3 | - | ✅ | ✅ | - |
| Rekap kehadiran prodi | - | ✅ | - | - |
| Laporan semester prodi | - | ✅ | - | - |
| Overview dosen prodi | - | ✅ | - | - |

**Platform**: Web Dashboard

#### Admin Prodi
| Fitur | Create | Read | Update | Delete |
|-------|--------|------|--------|--------|
| Tahun Ajaran | ✅ | ✅ | ✅ | ✅ |
| Semester | ✅ | ✅ | ✅ | ✅ |
| Mata Kuliah | ✅ | ✅ | ✅ | ✅ |
| Jadwal Perkuliahan | ✅ | ✅ | ✅ | ✅ |
| Lokasi Geofence | ✅ | ✅ | ✅ | ✅ |
| Data Mahasiswa | ✅ | ✅ | ✅ | ✅ |
| Data Dosen | ✅ | ✅ | ✅ | ✅ |
| Approval Enrollment Wajah | - | ✅ | ✅ | - |
| Approval Re-enrollment | - | ✅ | ✅ | - |
| Rekap Kehadiran | - | ✅ | - | - |
| Setting Toleransi | ✅ | ✅ | ✅ | - |
| Setting Threshold SP | ✅ | ✅ | ✅ | - |
| Setting Radius Geofence | ✅ | ✅ | ✅ | - |
| Generate Dokumen SP | ✅ | ✅ | - | - |
| Export Data | - | ✅ | - | - |

**Platform**: Web Dashboard

#### Dosen
| Fitur | Create | Read | Update | Delete |
|-------|--------|------|--------|--------|
| Jadwal mengajar | - | ✅ | - | - |
| Approve pending kehadiran | - | ✅ | ✅ | - |
| Override manual kehadiran | ✅ | ✅ | ✅ | - |
| Rekap kehadiran per kelas | - | ✅ | - | - |
| Riwayat pertemuan | - | ✅ | - | - |
| Approve izin/sakit | - | ✅ | ✅ | - |

**Platform**: Web Dashboard + Mobile App (Flutter)

#### Mahasiswa
| Fitur | Create | Read | Update | Delete |
|-------|--------|------|--------|--------|
| Check-in absensi | ✅ | - | - | - |
| Check-out absensi | ✅ | - | - | - |
| Enrollment wajah | ✅ | ✅ | - | - |
| Request re-enrollment | ✅ | ✅ | - | - |
| Upload izin/sakit | ✅ | ✅ | - | - |
| Lihat jadwal | - | ✅ | - | - |
| Lihat riwayat kehadiran | - | ✅ | - | - |
| Lihat status SP | - | ✅ | - | - |
| Lihat notifikasi | - | ✅ | - | - |

**Platform**: Mobile App (Flutter)

#### Orang Tua / Wali
| Fitur | Create | Read | Update | Delete |
|-------|--------|------|--------|--------|
| Lihat kehadiran anak | - | ✅ | - | - |
| Lihat status SP anak | - | ✅ | - | - |
| Lihat jadwal anak | - | ✅ | - | - |
| Terima notifikasi (alpha, SP) | - | ✅ | - | - |

**Platform**: Mobile App (Flutter)

---

## 3. TECH STACK

### 3.1 Mobile Application
| Komponen | Teknologi |
|----------|-----------|
| Framework | Flutter 3.x |
| Bahasa | Dart |
| Face Detection | Google ML Kit Face Detection |
| Face Embedding | MobileFaceNet (.tflite) - output 192-dim |
| Geolocation | Geolocator package |
| Mock Location Detection | safe_device package |
| Camera | camera package |
| State Management | Riverpod / Bloc |
| HTTP Client | Dio |
| Local Storage | SharedPreferences / Hive |
| Push Notification | Firebase Cloud Messaging (FCM) |
| Maps | google_maps_flutter |

### 3.2 Backend API
| Komponen | Teknologi |
|----------|-----------|
| Framework | Laravel 11.x |
| Bahasa | PHP 8.2+ |
| Database | MySQL 8.0 |
| Authentication | Laravel Sanctum (token-based) |
| File Storage | Laravel Storage (local/S3) |
| Queue | Laravel Queue (Redis/Database) |
| Notification | Laravel Notifications + FCM |
| PDF Generation | DomPDF / Snappy |
| Excel Export | Laravel Excel (Maatwebsite) |
| Scheduler | Laravel Task Scheduling |
| API Documentation | Swagger / L5-Swagger |

### 3.3 Web Dashboard (Frontend)
| Komponen | Teknologi |
|----------|-----------|
| Framework | Vue 3 (Composition API) |
| Build Tool | Vite |
| CSS Framework | Tailwind CSS 3.x |
| UI Components | Headless UI / Custom components |
| Charts | ApexCharts (vue3-apexcharts) |
| Table | TanStack Table / Custom |
| HTTP Client | Axios |
| State Management | Pinia |
| Router | Vue Router 4 |
| Form Validation | VeeValidate + Yup |
| Math Rendering | KaTeX (untuk rumus di menu evaluasi) |
| Maps | Leaflet / Google Maps |
| PDF Viewer | vue-pdf-embed |
| Date Picker | VueDatePicker |
| Notification | Vue Toastification |
| Icons | Heroicons / Lucide |

### 3.4 Infrastructure
| Komponen | Teknologi |
|----------|-----------|
| Server | VPS (Ubuntu 22.04) |
| Web Server | Nginx |
| SSL | Let's Encrypt |
| Process Manager | Supervisor (untuk queue worker) |
| Caching | Redis |
| Push Notification | Firebase Cloud Messaging |

---

## 4. ARSITEKTUR SISTEM

```
┌─────────────────────────────────────────────────────────────────┐
│                        MOBILE APP (Flutter)                       │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────────────┐│
│  │ Geolocator│  │ ML Kit   │  │MobileFace│  │  Camera + Live-  ││
│  │ + safe_  │  │ Face     │  │Net TFLite│  │  ness Detection  ││
│  │ device   │  │ Detection│  │ (192-dim)│  │  (Challenge)     ││
│  └────┬─────┘  └────┬─────┘  └────┬─────┘  └────────┬─────────┘│
│       │              │              │                  │          │
│       └──────────────┴──────────────┴──────────────────┘          │
│                              │                                    │
│                    ┌─────────▼─────────┐                         │
│                    │   Dio HTTP Client  │                         │
│                    └─────────┬─────────┘                         │
└──────────────────────────────┼───────────────────────────────────┘
                               │ HTTPS (REST API)
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│                     BACKEND (Laravel 11)                          │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌────────────────┐  │
│  │ Sanctum  │  │Controller│  │ Service  │  │  Notification  │  │
│  │ Auth     │  │ + Routes │  │ Layer    │  │  (FCM + Queue) │  │
│  └──────────┘  └──────────┘  └──────────┘  └────────────────┘  │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌────────────────┐  │
│  │ Eloquent │  │ SP Auto  │  │ PDF/Excel│  │  Scheduler     │  │
│  │ ORM      │  │ Calculator│  │ Export   │  │  (Cron Jobs)   │  │
│  └────┬─────┘  └──────────┘  └──────────┘  └────────────────┘  │
│       │                                                          │
└───────┼──────────────────────────────────────────────────────────┘
        │                          │ HTTPS
        ▼                          ▼
┌──────────────┐    ┌─────────────────────────────────────────────┐
│   MySQL 8.0  │    │          WEB DASHBOARD (Vue 3 + Vite)        │
│              │    │  ┌──────────┐  ┌──────────┐  ┌───────────┐  │
│  - Users     │    │  │ Pinia    │  │ ApexChart│  │ Tailwind  │  │
│  - Attendance│    │  │ Store    │  │ + KaTeX  │  │ CSS       │  │
│  - Schedules │    │  └──────────┘  └──────────┘  └───────────┘  │
│  - Geofences │    │  ┌──────────┐  ┌──────────┐  ┌───────────┐  │
│  - Embeddings│    │  │ Vue      │  │ Axios    │  │ Headless  │  │
│  - SP Records│    │  │ Router   │  │ Client   │  │ UI        │  │
│  - Logs      │    │  └──────────┘  └──────────┘  └───────────┘  │
│              │    └─────────────────────────────────────────────┘
└──────────────┘
```

---

## 5. ENVIRONMENT & DEPLOYMENT

### 5.1 Development
- Backend: `php artisan serve:all` (dev server + scheduler sekaligus, default localhost:8000) atau `composer dev`
- Frontend Web: `npm run dev` (localhost:5173)
- Mobile: Flutter debug mode (Android emulator / physical device)
- Database: MySQL local

### 5.2 Production
- VPS dengan Nginx sebagai reverse proxy
- Backend Laravel: `/var/www/absensi-api`
- Frontend Vue: `/var/www/absensi-dashboard` (static build)
- MySQL: same server atau managed database
- SSL: Let's Encrypt (auto-renew)
- Queue Worker: Supervisor
- Cron: Laravel Scheduler (setiap menit) — Linux via `schedule:run`; Windows dev/on-prem via Windows Task Scheduler yang menjalankan `schedule:work` (lihat DEPLOYMENT.md). Development lokal cukup `serve:all` (dev server + scheduler satu proses).

### 5.3 Domain Structure
- API: `api.absensi.domain.com`
- Dashboard: `dashboard.absensi.domain.com`
- Mobile: APK distribution (internal/Play Store)
