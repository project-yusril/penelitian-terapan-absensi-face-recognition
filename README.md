# Sistem Absensi Mahasiswa Berbasis Face Recognition & Geofencing

Sistem absensi **Jurusan Teknik Elektro, Politeknik Negeri Pontianak** dengan verifikasi wajah on-device (MobileFaceNet) dan geofencing — penelitian terapan yang membangun ekosistem lengkap: REST API + dashboard web berbasis Laravel/Inertia/Vue, aplikasi mobile mahasiswa berbasis Flutter (Android), hingga alur akademik, peringatan SP, dan analisis FAR/FRR untuk keperluan penelitian.

**Penulis:** Yusril Eka Mahendra, M.TI · **Periode penelitian:** Maret–November 2026

## Fitur Utama

| Domain | Kemampuan |
|---|---|
| **Verifikasi wajah** | Face verification on-device (MobileFaceNet, embedding 192 float), liveness challenge, enrollment & re-enrollment dengan persetujuan admin, embedding terenkripsi AES-256-GCM dengan key terpisah |
| **Geofencing** | Validasi koordinat per jadwal, baseline GPS accuracy minimum 20 m, deteksi mock location |
| **Absensi** | Check-in/check-out per sesi jadwal (multi mata kuliah per hari), attendance permit sekali pakai terikat resource/action/UUID, attendance window inklusif, timezone `Asia/Pontianak` |
| **Offline-first** | Queue offline terenkripsi per-user (Hive AES + secure storage), stale lease recovery, sync sebelum permit expiry |
| **Akademik (kelas master)** | Kelas sebagai entitas master per semester (`1A`–`5E`), riwayat mutasi mahasiswa antar kelas tercatat via pivot `mahasiswa_kelas`, dosen & kelas di-plot per jadwal, KRS diturunkan otomatis |
| **SP (Surat Peringatan)** | Akumulasi alpha berbasis menit, ambang SP1/SP2/SP3/DO (16/32/38/46 jam) per prodi, early warning otomatis, generate dokumen SP bertanda tangan digital |
| **Izin/Sakit** | Multi mata kuliah sekaligus (fan-out per MK dalam satu transaksi), dokumen private via signed URL |
| **Dashboard web** | 8 role (super admin, ketua jurusan, admin jurusan, kaprodi, admin prodi, dosen, mahasiswa, orang tua), monitoring real-time, laporan per kelas/MK/prodi, export Excel/PDF |
| **Analisis penelitian** | Mode pengujian FAR/FRR berlabel genuine/impostor, kurva FAR vs FRR + EER, evaluasi geofence, latensi per perangkat, uji simultan, visualisasi murni CSS/SVG |
| **Notifikasi** | FCM mobile (explicit opt-in, revoke token saat logout untuk keamanan perangkat bersama), outbox + reminder terjadwal |

## Arsitektur

```
┌─────────────────────┐        HTTPS/Bearer         ┌──────────────────────────┐
│  Flutter (Android)  │ ◄─────────────────────────► │  Laravel 13 (backend/)   │
│  mahasiswa & ortu   │   Sanctum token + session   │  ├─ REST API (/api)      │
│  BLoC + Dio + Hive  │                             │  ├─ Web Inertia/Vue 3    │
│  face matching      │                             │  ├─ Queue + scheduler    │
│  on-device          │                             │  └─ MySQL 8 (migrations) │
└─────────────────────┘                             └──────────────────────────┘
```

- **Backend** — Laravel 13, Inertia 3 + Vue 3 + Vite 8, MySQL 8, queue & scheduler (`serve:all`/`composer dev`), private file delivery via signed URL.
- **Mobile** — Flutter 3.44 (Android-only; iOS bukan release target), BLoC, Dio, Hive terenkripsi, MobileFaceNet on-device.
- **CI** — Backend CI (validate/audit/build/test) dan Frontend CI (analyze/test) pada setiap push/PR; Android release & device-test workflow manual.

Flask keamanan penting: produksi **fail-closed** — mutation attendance/enrollment menolak evidence scalar client (`503 TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED`) karena trusted verifier server-side berada di luar scope penelitian ([ADR-001](docs/ADR-001-trusted-biometric-verifier.md), [threat model](docs/THREAT-MODEL-ATTENDANCE.md)).

## Struktur Repositori

| Lokasi | Fungsi |
|---|---|
| `backend/` | Laravel REST API, dashboard Inertia/Vue, migrations (schema authority), queue, scheduler |
| `frontend/` | Aplikasi Flutter mahasiswa: enrollment, attendance, offline queue, izin, status SP |
| `docs/` | Spesifikasi PRD, arsitektur & API current, keamanan, deployment, audit temuan, ADR, catatan implementasi |
| `deploy/` | Contoh manifest Nginx & systemd untuk deployment Linux |
| `.github/workflows/` | Backend CI, Frontend CI, Android release, Android physical-device test harness |

## Quick Start

### Backend

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
npm ci
composer dev
```

`composer dev` menjalankan dev server, scheduler, queue listener, log viewer, dan Vite sekaligus. Tanpa scheduler yang hidup, status ALPHA dan auto-close tidak tercatat otomatis — gunakan `php artisan serve:all` (dev server + scheduler dalam satu proses) bila tidak memakai `composer dev`.

### Aplikasi Mobile (Flutter → Android)

Dengan backend HTTPS yang dapat dijangkau perangkat:

```powershell
cd frontend
flutter pub get --enforce-lockfile
flutter run --dart-define=API_BASE_URL=https://api.example.ac.id/api
```

Build debug menerima HTTP untuk loopback dan alamat LAN privat; build profile/release **wajib HTTPS**. Detail alur koneksi Wi-Fi (cari IP laptop, buka firewall, build APK debug, fallback USB `adb reverse`) ada di bagian [Menjalankan Aplikasi Melalui Wi-Fi](#menjalankan-aplikasi-melalui-wi-fi) README bahasa lengkap di bawah dan di [frontend/README.md](frontend/README.md).

## Menjalankan Aplikasi Melalui Wi-Fi

Kabel USB tidak diperlukan agar aplikasi Flutter terhubung ke backend. Flutter tidak mengakses MySQL secara langsung. Alur koneksinya:

```text
HP Android -> Wi-Fi -> Laravel API di laptop -> MySQL
```

Laptop dan HP harus berada di jaringan Wi-Fi privat yang sama, jaringan harus mengizinkan komunikasi antarklien, dan Windows Firewall harus mengizinkan port backend. HTTP debug tidak mengenkripsi kredensial, bearer token, lokasi, atau data biometrik; gunakan hotspot/router pribadi yang dipercaya serta akun dan data uji. Jangan memakai workflow HTTP ini pada Wi-Fi kampus, kafe, hotel, atau jaringan publik. Untuk data nyata gunakan backend HTTPS.

### 1. Cari alamat IPv4 laptop

Jalankan PowerShell:

```powershell
Get-NetIPConfiguration
```

Cari `IPv4Address` pada adapter Wi-Fi yang memiliki `IPv4DefaultGateway`. Contoh alamat laptop: `192.168.8.28`. Alamat dapat berubah setelah pindah jaringan atau reconnect Wi-Fi.

### 2. Atur URL backend lokal

Di `backend/.env`, sesuaikan `APP_URL` dengan IP laptop:

```dotenv
APP_URL=http://192.168.8.28:8000
```

Muat ulang konfigurasi:

```powershell
cd backend
php artisan config:clear
```

File `.env` berisi konfigurasi dan secret lokal sehingga tidak boleh dikomit.

### 3. Jalankan backend untuk jaringan LAN

```powershell
cd backend
php artisan serve:all
```

`serve:all` menjalankan dev server (default `--host=0.0.0.0 --port=8000`) **dan** scheduler sekaligus dari satu proses. HP mengakses server memakai IP nyata laptop (mis. `http://192.168.8.28:8000`), bukan `0.0.0.0`. Periksa dari laptop:

```powershell
Invoke-WebRequest http://192.168.8.28:8000/api/health -UseBasicParsing
```

Respons yang benar berstatus `200` dengan body `{"status":"ok"}`.

### 4. Profil Private & firewall

Gunakan jaringan pribadi yang dipercaya, lalu buka **PowerShell sebagai Administrator**:

```powershell
Set-NetConnectionProfile -InterfaceAlias "Wi-Fi" -NetworkCategory Private

New-NetFirewallRule `
  -DisplayName "Absensi Mahasiswa Backend Dev 8000" `
  -Direction Inbound -Action Allow -Protocol TCP `
  -LocalPort 8000 -Profile Private -RemoteAddress LocalSubnet
```

Rule hanya aktif pada profil `Private` dan hanya menerima subnet lokal. Hapus saat tidak dipakai: `Remove-NetFirewallRule -DisplayName "Absensi Mahasiswa Backend Dev 8000"`.

### 5. Policy Android debug

HTTP cleartext hanya diizinkan varian debug (`frontend/android/app/src/debug/res/xml/network_security_config.xml`). `AppConfig` membatasi URL HTTP ke loopback, alias emulator, atau alamat privat RFC 1918. IP tidak disimpan di source; cukup build ulang dengan `API_BASE_URL` baru.

### 6. Jalankan Flutter melalui Wi-Fi

```powershell
cd frontend
flutter devices
flutter run -d <DEVICE_ID> --dart-define=API_BASE_URL=http://192.168.8.28:8000/api
```

APK debug yang tetap terhubung tanpa kabel:

```powershell
flutter build apk --debug --dart-define=API_BASE_URL=http://192.168.8.28:8000/api
# output: frontend/build/app/outputs/flutter-apk/app-debug.apk
```

### Fallback USB

```powershell
adb reverse tcp:8000 tcp:8000
flutter run -d <DEVICE_ID> --dart-define=API_BASE_URL=http://127.0.0.1:8000/api
```

Mode ini berhenti bekerja setelah kabel dicabut; untuk penggunaan tanpa kabel selalu build dengan IP Wi-Fi laptop.

### Troubleshooting koneksi

1. Backend menampilkan `Server running on [http://0.0.0.0:8000]`.
2. `Get-NetTCPConnection -LocalPort 8000 -State Listen` menampilkan `0.0.0.0`.
3. HP dan laptop di subnet yang sama (mis. `192.168.8.x`).
4. Firewall memiliki rule TCP `8000` untuk `LocalSubnet`.
5. `API_BASE_URL` memakai IP laptop — bukan `127.0.0.1`, `localhost`, atau `0.0.0.0`.
6. Aplikasi dibangun ulang dengan IP terbaru.
7. Client isolation aktif? Gunakan hotspot/router pribadi atau fallback USB.
8. Backend dijalankan dengan `serve:all` (bukan `serve` saja) agar ALPHA/auto-close tercatat.

## Verifikasi & CI

```powershell
cd backend
composer test

cd ..\frontend
flutter test
flutter analyze --fatal-warnings --fatal-infos
```

Hasil verifikasi tooling terakhir (20 Agustus 2026): backend **229 test / 862 assertion PASS**, Flutter **189 test lulus**, `flutter analyze` bersih, `npm run build` lulus, Pint tanpa style issue, 0 known vulnerability (composer & npm). Workflow CI menjalankan gate yang sama pada setiap push/PR: Backend CI (`composer validate`/`check-platform-reqs`/`audit`, npm audit/build, `php artisan test`) dan Frontend CI (`flutter analyze --fatal-warnings --fatal-infos`, `flutter test`); `android-release.yml` dan `android-device-tests.yml` manual.

## Status Penelitian

> **Status release 18 Agustus 2026:** **memenuhi tujuan penelitian** (face recognition on-device + geofencing terbukti bekerja), tetapi **belum production-ready**.

- Trusted biometric verifier server-side dinyatakan **di luar scope penelitian** ([ADR-001](docs/ADR-001-trusted-biometric-verifier.md) ditolak); production mutation attendance/enrollment tetap fail-closed dan residual risk diterima serta didokumentasikan.
- Android adalah satu-satunya target mobile release; iOS tidak didukung.
- R-02 (load test), R-03 (metodologi FAR/FRR lapangan), dan pengambilan data lapangan asli R-05 masih terbuka; dataset uji awal (920 log) sudah dapat di-seed via `php artisan attendance:seed-analysis-data`.
- Backlog risiko lengkap, status remediation, dan evidence: [`docs/temuan.md`](docs/temuan.md).

## Dokumentasi

Mulai dari [`docs/README.md`](docs/README.md) — menjelaskan hierarki sumber kebenaran (executable truth > dokumen current > PRD > catatan implementasi > historis) dan aturan pemeliharaan dokumentasi.

Referensi utama:

| Dokumen | Isi |
|---|---|
| [CURRENT-ARCHITECTURE.md](docs/CURRENT-ARCHITECTURE.md) | Arsitektur komponen, struktur data akademik (kelas master), trust boundary, state data terkini |
| [CURRENT-API.md](docs/CURRENT-API.md) | Kontrak API executable, termasuk production biometric containment |
| [SECURITY.md](docs/SECURITY.md) | Kontrol keamanan aktif, secret management, residual risk |
| [ROLE-PERMISSION-MATRIX.md](docs/ROLE-PERMISSION-MATRIX.md) | Matriks role/permission/prodi canonical & tiga lapis enforcement |
| [THREAT-MODEL-ATTENDANCE.md](docs/THREAT-MODEL-ATTENDANCE.md) | Aktor ancaman, kontrol server, batas klaim client |
| [DEPLOYMENT.md](docs/DEPLOYMENT.md) | Environment, CI, backend production, Android release matrix, rollback/restore |
| [temuan.md](docs/temuan.md) | Audit menyeluruh: temuan, status remediation, acceptance, evidence |
| [PRD-INDEX.md](docs/PRD-INDEX.md) | Indeks 9 dokumen PRD (overview s.d. non-functional) |
| [rencana2.md](docs/rencana2.md) | Catatan implementasi & verifikasi RENCANA 2 (restrukturisasi kelas master) |
| [SOP-R05-R07.md](docs/SOP-R05-R07.md) | SOP pengujian penelitian (R-05 s.d. R-07) |
| [ADR-001](docs/ADR-001-trusted-biometric-verifier.md) | Keputusan arsitektur: trusted biometric verifier (ditolak, di luar scope) |

Dokumen historis (task plan, analisis lama, fix log) hanya merekam kondisi pada tanggal pembuatannya dan tidak boleh menjadi dasar keputusan baru.

## Teknologi

| Layer | Teknologi |
|---|---|
| Backend | PHP 8.3, Laravel 13, Inertia 3, Vue 3, Vite 8, MySQL 8, Sanctum |
| Mobile | Flutter 3.44 / Dart 3.12, BLoC, Dio, Hive (AES), MobileFaceNet, FCM |
| Infra & CI | GitHub Actions (backend/frontend CI, Android release & device test), Nginx/systemd (contoh deploy) |
| Kualitas | PHPUnit/Pest (229+ test), Flutter test (189+), Pint, `composer audit` + `npm audit`, analyzers fail-closed |
