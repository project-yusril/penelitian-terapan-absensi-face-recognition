# Sistem Absensi Mahasiswa

Sistem absensi Jurusan Teknik Elektro Politeknik Negeri Pontianak dengan dashboard Laravel/Inertia/Vue dan aplikasi mahasiswa Flutter Android. Kontrol utama mencakup geofence, verifikasi wajah, attendance permit sekali pakai, offline queue terenkripsi, serta workflow akademik dan SP.

> **Status release 18 Agustus 2026:** belum production-ready. Trusted biometric
> verifier dinyatakan di luar scope release penelitian ini; mutation attendance dan
> enrollment karena itu tetap diblokir fail-closed di production. Android adalah
> satu-satunya target mobile release; iOS tidak didukung. Lihat
> [`docs/temuan.md`](docs/temuan.md) dan [ADR-001](docs/ADR-001-trusted-biometric-verifier.md).

## Dokumentasi

Mulai dari [`docs/README.md`](docs/README.md). Dokumen tersebut menjelaskan sumber kebenaran, status PRD, dokumen historis, dan referensi operasional.

Referensi utama:

- [Arsitektur saat ini](docs/CURRENT-ARCHITECTURE.md)
- [Kontrak API saat ini](docs/CURRENT-API.md)
- [Keamanan dan residual risk](docs/SECURITY.md)
- [Matriks role, permission, dan prodi](docs/ROLE-PERMISSION-MATRIX.md)
- [Deployment dan release](docs/DEPLOYMENT.md)
- [Audit dan backlog aktif](docs/temuan.md)
- [Indeks kebutuhan produk](docs/PRD-INDEX.md)

## Struktur

| Lokasi | Fungsi |
|---|---|
| `backend/` | Laravel REST API, web Inertia/Vue, queue, scheduler, dan database migrations |
| `frontend/` | Aplikasi Flutter mahasiswa untuk enrollment dan attendance |
| `docs/` | Spesifikasi, arsitektur, operasi, audit, dan arsip keputusan |
| `.github/workflows/` | Backend CI, Frontend CI, Android release, dan Android physical-device test harness |
| `deploy/` | Contoh manifest Nginx dan systemd untuk deployment Linux |

## Quick Start

Backend:

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
npm ci
composer dev
```

`composer dev` menjalankan dev server, scheduler, queue listener, log viewer, dan Vite sekaligus. Tanpa `composer dev`, jalankan `php artisan serve:all` (dev server + scheduler) dari `backend/` — scheduler wajib hidup agar status ALPHA dan auto-close tercatat otomatis (lihat DEPLOYMENT.md).

Flutter, dengan backend HTTPS yang dapat dijangkau perangkat:

```powershell
cd frontend
flutter pub get --enforce-lockfile
flutter run --dart-define=API_BASE_URL=https://api.example.ac.id/api
```

Build debug menerima HTTP untuk loopback dan alamat LAN privat. Build profile/release wajib menggunakan HTTPS.

## Menjalankan Aplikasi Melalui Wi-Fi

Kabel USB tidak diperlukan agar aplikasi Flutter terhubung ke database. Flutter tidak mengakses MySQL secara langsung. Alur koneksinya adalah:

```text
HP Android -> Wi-Fi -> Laravel API di laptop -> MySQL
```

Laptop dan HP harus berada di jaringan Wi-Fi privat yang sama, jaringan harus mengizinkan komunikasi antarklien, dan Windows Firewall harus mengizinkan port backend. HTTP debug tidak mengenkripsi kredensial, bearer token, lokasi, atau data biometrik; gunakan hotspot/router pribadi yang dipercaya serta akun dan data uji. Jangan memakai workflow HTTP ini pada Wi-Fi kampus, kafe, hotel, atau jaringan publik. Untuk data nyata gunakan backend HTTPS.

### 1. Cari alamat IPv4 laptop

Jalankan PowerShell:

```powershell
Get-NetIPConfiguration
```

Cari `IPv4Address` pada adapter Wi-Fi yang memiliki `IPv4DefaultGateway`. Contoh alamat laptop:

```text
192.168.8.28
```

Alamat dapat berubah setelah pindah jaringan atau reconnect Wi-Fi. Gunakan alamat yang sedang aktif, bukan menyalin contoh tanpa memeriksanya.

### 2. Atur URL backend lokal

Di `backend/.env`, sesuaikan `APP_URL` dengan IP laptop:

```dotenv
APP_URL=http://192.168.8.28:8000
```

Muat ulang konfigurasi Laravel:

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

`serve:all` menjalankan dev server (default `--host=0.0.0.0 --port=8000`) **dan** scheduler sekaligus dari satu proses, sehingga ALPHA/auto-close tercatat otomatis. Penjelasan parameter:

- `--host=0.0.0.0` membuat server mendengarkan semua interface IPv4 laptop, termasuk Wi-Fi. Nilai ini adalah alamat bind server, bukan alamat yang ditulis di Flutter atau browser.
- `--port=8000` membuka Laravel pada TCP port `8000`.
- HP mengakses server memakai IP nyata laptop, misalnya `http://192.168.8.28:8000`, bukan `http://0.0.0.0:8000`.

Periksa backend dari laptop:

```powershell
Invoke-WebRequest http://192.168.8.28:8000/api/health -UseBasicParsing
```

Respons yang benar memiliki HTTP status `200` dan body `{"status":"ok"}`.

### 4. Gunakan profil Private dan izinkan port 8000

Gunakan jaringan pribadi yang dipercaya, lalu buka **Windows PowerShell sebagai Administrator**. Ubah profil adapter Wi-Fi menjadi `Private`:

```powershell
Set-NetConnectionProfile -InterfaceAlias "Wi-Fi" -NetworkCategory Private
```

Tambahkan rule yang hanya aktif pada profil `Private` dan hanya menerima perangkat di subnet lokal:

```powershell
New-NetFirewallRule `
  -DisplayName "Absensi Mahasiswa Backend Dev 8000" `
  -Direction Inbound `
  -Action Allow `
  -Protocol TCP `
  -LocalPort 8000 `
  -Profile Private `
  -RemoteAddress LocalSubnet
```

Rule tersebut membatasi akses ke subnet lokal. Periksa rule dengan:

```powershell
Get-NetFirewallRule -DisplayName "Absensi Mahasiswa Backend Dev 8000"
```

Hapus rule jika development LAN tidak lagi digunakan:

```powershell
Remove-NetFirewallRule -DisplayName "Absensi Mahasiswa Backend Dev 8000"
```

### 5. Policy Android debug

HTTP cleartext hanya diizinkan oleh varian Android debug. `AppConfig` tetap membatasi URL HTTP ke loopback, alias emulator, atau alamat privat RFC 1918 seperti `192.168.x.x`, `10.x.x.x`, dan `172.16.x.x` sampai `172.31.x.x`.

```text
frontend/android/app/src/debug/res/xml/network_security_config.xml
```

IP laptop tidak disimpan di source. Jika IP berubah, cukup build/install ulang dengan nilai `API_BASE_URL` baru. Konfigurasi profile/release tetap menolak HTTP; deployment production harus memakai HTTPS.

### 6. Jalankan Flutter melalui Wi-Fi

Dengan HP terhubung saat proses development:

```powershell
cd frontend
flutter devices
flutter run -d <DEVICE_ID> --dart-define=API_BASE_URL=http://192.168.8.28:8000/api
```

Ganti `<DEVICE_ID>` dengan ID dari `flutter devices` dan ganti IP contoh dengan IP laptop. Segmen `/api` boleh disertakan; konfigurasi aplikasi akan menormalkannya.

Untuk membuat APK yang tetap memakai Wi-Fi setelah kabel dicabut:

```powershell
cd frontend
flutter build apk --debug --dart-define=API_BASE_URL=http://192.168.8.28:8000/api
```

APK tersedia di:

```text
frontend/build/app/outputs/flutter-apk/app-debug.apk
```

Install APK ke HP, kemudian kabel boleh dicabut. Selama backend aktif, firewall terbuka, IP tidak berubah, dan HP berada pada jaringan yang sama, aplikasi akan tetap terhubung ke Laravel dan database.

### Mode USB sebagai fallback

`adb reverse` hanya fallback development ketika jaringan Wi-Fi memblokir komunikasi antarklien:

```powershell
adb reverse tcp:8000 tcp:8000
flutter run -d <DEVICE_ID> --dart-define=API_BASE_URL=http://127.0.0.1:8000/api
```

Pada mode ini `127.0.0.1` bekerja karena port HP dijembatani ke laptop melalui USB. Setelah kabel dicabut, mode ini berhenti bekerja. Untuk penggunaan tanpa kabel, selalu build dengan IP Wi-Fi laptop.

### Troubleshooting koneksi Wi-Fi

1. Pastikan backend menampilkan `Server running on [http://0.0.0.0:8000]`.
2. Pastikan `Get-NetTCPConnection -LocalPort 8000 -State Listen` menampilkan `0.0.0.0`.
3. Pastikan HP dan laptop memperoleh IP pada subnet yang sama, misalnya `192.168.8.x`.
4. Pastikan Windows Firewall memiliki rule TCP `8000` untuk `LocalSubnet`.
5. Pastikan `API_BASE_URL` memakai IP laptop, bukan `127.0.0.1`, `localhost`, atau `0.0.0.0`.
6. Pastikan aplikasi yang terpasang dibangun ulang dengan `API_BASE_URL` berisi IP laptop terbaru.
7. Jika jaringan mengaktifkan client isolation, gunakan hotspot/router pribadi yang dipercaya atau fallback USB. Jangan membuka HTTP development server pada Wi-Fi publik.
8. Pastikan backend dijalankan dengan `php artisan serve:all` (bukan `serve` saja) — tanpa scheduler, status ALPHA dan auto-close tidak tercatat otomatis.

## Verifikasi

```powershell
cd backend
composer test

cd ..\frontend
flutter test
flutter analyze --fatal-warnings --fatal-infos
```

Workflow CI mendefinisikan gate yang sama pada setiap push/PR: Backend CI (`composer validate`/`check-platform-reqs`/`audit`, npm audit/build, `php artisan test`) dan Frontend CI (`flutter analyze --fatal-warnings --fatal-infos`, `flutter test`). Status remote run dan required-check enforcement tetap mengikuti L-09 di `docs/temuan.md`.

Status kesiapan produksi tidak ditentukan oleh build saja. Gunakan acceptance dan blocker pada [`docs/temuan.md`](docs/temuan.md).
