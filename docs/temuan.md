# Temuan Audit Menyeluruh Absensi Mahasiswa

Tanggal audit: 17 Juli 2026  
Scope: `backend/`, `frontend/`, seluruh dokumentasi di `docs/`, konfigurasi platform, migration, seeder, test, dan dependency manifest/lockfile.  
Metode: review statis lintas backend Laravel, web Inertia/Vue, Flutter, database, security, business logic, serta verifikasi build/test/tooling yang tersedia.

> **Status update 18 Juli 2026:** evidence awal dipertahankan sebagai histori
> penemuan. Status/remediation terbaru berada pada setiap item. Hierarki dokumen,
> arsitektur, API, security, dan deployment current tersedia di
> [README dokumentasi](README.md).

## Kesimpulan Eksekutif

Status project saat ini: **belum layak dipakai sebagai sistem absensi produksi atau sumber keputusan akademik resmi** karena masih ada Critical/High terbuka, physical-device/iOS verification belum lengkap, dan trust evidence attendance masih parsial.

Sebagian besar fitur yang dijelaskan PRD sudah memiliki implementasi dan banyak temuan awal telah ditutup. Risiko terbuka terbesar saat ini adalah:

1. Server masih mempercayai koordinat, hasil liveness, dan face distance yang dibuat client walaupun permit sudah menutup replay/binding abuse (C-04/H-04).
2. Monitoring, dashboard, laporan, dan setting masih membutuhkan actor-based scope canonical (H-21).
3. Checkout UI dan converter kamera telah diimplementasikan tetapi acceptance integration/physical-device belum lengkap (H-13/H-16).
4. iOS belum memiliki build, signing, CI, dan physical-device evidence (H-17).
5. Reliability/concurrency, idempotency retry, reporting scope/performance, observability, dan research validity masih memiliki temuan Medium/Low/R terbuka.

Audit awal mengonsolidasikan **59 temuan utama** dan 5 task validitas penelitian. Tabel berikut adalah distribusi severity awal, bukan jumlah temuan yang masih terbuka:

| Severity | Jumlah | Arti |
|---|---:|---|
| Critical | 7 | Eksploitasi langsung, takeover, privilege escalation, atau integritas inti sistem runtuh |
| High | 21 | Kerusakan fungsi utama, kebocoran sensitif, atau korupsi data serius |
| Medium | 22 | Inkonsistensi, reliability, performance, security hardening, atau test gap penting |
| Low | 9 | Kualitas, aksesibilitas, dokumentasi, dan operasional |

## Hasil Verifikasi Tooling

| Pemeriksaan | Hasil |
|---|---|
| `composer validate --strict` | Manifest valid, tetapi warning karena `barryvdh/laravel-dompdf: *` |
| `php artisan test` | **Lulus: 182 test, 653 assertion** pada PHP 8.3.30 (9 Agustus 2026; sebelumnya 171/624 pada 26 Juli 2026) |
| `npm run build` | **Lulus**, Vite membangun frontend Inertia/Vue |
| `npm audit --package-lock-only --omit=dev` | **0 known vulnerabilities** pada dependency npm production |
| `flutter test` | **144 test lulus** (9 Agustus 2026), termasuk test comparator/formatters production baru (L-06), permit contract, queue lease, dan camera converter |
| `flutter analyze` | **Bersih, No issues found** (9 Agustus 2026); CI kini `--fatal-warnings --fatal-infos` (L-05) |
| Composer advisory audit | Belum dapat dinyatakan bersih; akses audit Packagist sempat timeout |
| Git/history audit | Tidak dapat dilakukan karena workspace root bukan repository Git |

Build yang lulus tidak menghapus temuan runtime/business logic. Banyak bug berada pada authorization dan state transition yang tidak diperiksa oleh build.

## Temuan Critical

### [C-01] Reset password memungkinkan account takeover

- Bukti: `backend/routes/api.php:81-85`, `backend/app/Http/Controllers/Api/ForgotPasswordController.php:25-48`, `backend/app/Http/Controllers/Api/ResetPasswordController.php:20-34`.
- Mobile memang membaca token tersebut di `frontend/lib/features/auth/presentation/pages/forgot_password_page.dart:51-90`.
- Masalah: endpoint publik mengembalikan `reset_token` mentah. Rule `exists:users,email` juga memungkinkan enumerasi email.
- Dampak: siapa pun yang mengetahui email dapat mengganti password korban tanpa akses ke email korban.
- Perbaikan: kirim token/link hanya melalui kanal terverifikasi; response harus generik untuk email ada/tidak ada; token single-use dan short-lived; revoke seluruh token/session setelah reset.
- Acceptance: response API tidak pernah mengandung token; test membuktikan email tidak dapat dienumerasi; token lama dan semua sesi tidak berlaku setelah reset.
- Task: [X] **C-01 Tutup account takeover pada forgot/reset password dan tambah regression test.**
- Status 17 Juli 2026: selesai. Forgot-password sekarang selalu memberi response generik bertiming konstan, token hanya dikirim melalui queued encrypted email notification, token dikonsumsi atomik serta memiliki expiry/single-use, dan reset mencabut seluruh token Sanctum serta database session pengguna.
- Verifikasi: 12 test `AuthenticationTest` lulus (28 assertion), full backend suite 61 test lulus (90 assertion), Flutter 59 test lulus, dan web production build lulus. Runtime backend test menggunakan PHP 8.4.23 terverifikasi karena mismatch PHP manifest/lockfile tetap merupakan task H-01 terpisah.

### [C-02] Admin terbatas dapat menjadi `super_admin` dan mengelola user global

- Bukti API: `backend/routes/api.php:116-125`, `backend/app/Http/Controllers/Api/Admin/UserController.php:19-77`, `:105-122`, `:139-177`, `:183-217`, `:379-391`.
- Bukti web: `backend/routes/web.php:80-91`, `backend/app/Http/Controllers/Web/UserController.php:40-53`, `:85-126`, `:130-166`.
- Masalah: `admin_prodi`, `admin_jurusan`, dan role manajemen lain menerima CRUD user global dan dapat mengirim role apa pun yang ada, termasuk `super_admin`.
- Dampak: privilege escalation penuh, reset password administrator lain, deactivation, delete, dan akses seluruh sistem.
- Perbaikan: permission granular, policy per operasi, hierarchy role, scope prodi, larangan self-escalation dan perubahan terhadap role setara/lebih tinggi.
- Acceptance: seluruh percobaan role escalation dan akses lintas prodi menghasilkan 403 serta memiliki negative feature test.
- Task: [X] **C-02 Terapkan policy user/role yang mencegah privilege escalation dan akses lintas prodi.**
- Status 17 Juli 2026: selesai. API dan web memakai hierarchy/assignability terpusat, target dengan role setara/lebih tinggi dilindungi, self-mutation sensitif ditolak, dan `admin_prodi`/`admin_jurusan` fail-closed ke `prodi_id` aktor. Karena schema belum memiliki entitas jurusan, `admin_jurusan` sementara dibatasi ke satu prodi daripada diberi akses global.
- Verifikasi: negative tests mencakup assignment `super_admin`, API/web escalation, admin prodi/admin jurusan lintas prodi, explicit-null `prodi_id`, delete, dan toggle status; seluruh backend suite lulus.

### [C-03] IDOR lintas prodi pada approval dan tindakan Kaprodi

- Bukti API: `backend/app/Http/Controllers/Api/Kaprodi/EnrollmentController.php:47-92`, `LeaveRequestController.php:34-78`, `ReEnrollmentController.php:35-90`, `SpController.php:40-55`.
- Bukti web: `backend/app/Http/Controllers/Web/ApprovalController.php:67-89`, `:121-150`, `:186-219`.
- Masalah: daftar sudah difilter, tetapi action mutasi memakai `findOrFail()` atau route-model binding global tanpa scope prodi.
- Dampak: Kaprodi prodi A dapat approve/reject enrollment, re-enrollment, izin, atau SP milik prodi B jika mengetahui ID.
- Perbaikan: policy object-level pada setiap action dan scoped query berdasarkan `prodi_id` aktor.
- Acceptance: list, detail, approve, reject, sign, cancel, dan override memiliki scope yang sama; test lintas prodi wajib 403.
- Task: [X] **C-03 Tutup seluruh IDOR Kaprodi dan approval dengan policy object-level.**
- Status 17 Juli 2026: selesai. Enrollment, re-enrollment, leave, SP, attendance override, list/detail/download, dan web approval memakai object-level prodi scope yang fail-closed. Ownership attendance/leave juga memeriksa konsistensi prodi mahasiswa dengan mata kuliah.
- Verifikasi: negative API dan web tests membuktikan Kaprodi prodi A menerima 403 saat mengubah enrollment prodi B dan record tetap tidak berubah; full backend suite lulus.

### [C-04] Server mempercayai bukti anti-fraud yang dibuat klien

- Bukti online: `backend/app/Http/Controllers/Api/Mahasiswa/AttendanceController.php:27-46`, `:79-127`.
- Bukti offline: `backend/app/Http/Controllers/Api/Mahasiswa/OfflineSyncController.php:36-54`, `:95-135`.
- Bukti mobile: `frontend/lib/features/attendance/presentation/pages/attendance_page.dart:377-414`, `:449-450`.
- Masalah: server menerima koordinat, boolean mock-location/liveness, dan angka face-distance tanpa bukti yang dapat diverifikasi independen.
- Dampak: modified APK atau HTTP client dapat mengirim koordinat pusat geofence, `liveness_passed=true`, `mock_location_detected=false`, dan `face_distance=0`.
- Perbaikan: buat threat model; server-issued nonce/challenge berumur pendek; replay protection; binding user/jadwal/action/device; attestation sebagai sinyal tambahan; pertimbangkan verifikasi capture/matching server-side dengan kontrol privasi.
- Acceptance: payload arbitrer tanpa challenge sah ditolak; replay ditolak; challenge salah user/jadwal/action ditolak.
- Task: [ ] **C-04 Desain ulang trust model absensi agar server tidak mempercayai klaim klien.**
- Status 17 Juli 2026: **parsial, belum boleh ditandai selesai**. Server sekarang menerbitkan opaque one-time permit yang terikat user, jadwal, action, attendance, occurrence, client UUID, expiry, dan random liveness challenge. Missing permit, replay, wrong user/jadwal/action/UUID, wrong challenge, serta token expired ditolak. Namun koordinat, hasil face matching, dan hasil liveness masih berupa scalar/boolean dari client. Modified APK dengan sesi sah masih dapat meminta permit lalu mengirim nilai palsu.
- Blocker penyelesaian: implementasikan challenge-bound capture artifact yang diverifikasi server atau trusted verifier, hardware-backed device signature, dan platform attestation sebagai sinyal tambahan. Jangan menandai C-04 `[X]` hanya berdasarkan nonce/permit.
- Status 26 Juli 2026: **masih terbuka, tidak ada perubahan trust model**. Bagian acceptance "buat threat model" telah diselesaikan dan didokumentasikan di [THREAT-MODEL-ATTENDANCE.md](THREAT-MODEL-ATTENDANCE.md), yang memetakan kontrol yang benar-benar ditegakkan server, lima nilai yang masih merupakan klaim client (`latitude`/`longitude`, `mock_location_detected`, `liveness_passed`, `face_distance`, `gps_accuracy`/`location_age_ms`), tiga skenario serangan yang belum termitigasi (absensi tanpa hadir fisik, proxy attendance, presentation attack), batas klaim yang boleh dibuat, dan urutan remediasi. Sisa acceptance C-04 (capture artifact terverifikasi, attestation, hardware signature) belum dikerjakan karena merupakan perubahan arsitektur, bukan patch.

### [C-05] Offline sync dapat memalsukan absensi historis dan lintas jadwal

- Bukti: `backend/app/Http/Controllers/Api/Mahasiswa/OfflineSyncController.php:36-54`, `:56-70`, `:137-148`, `:204-246`, `:274-303`.
- Masalah: timestamp hanya divalidasi sebagai tanggal; tidak ada lower bound, future bound, validasi hari, enrollment mata kuliah, semester aktif, atau signed offline permit. Checkout juga menerima `attendance_id` dan `jadwal_id` yang tidak harus cocok.
- Dampak: backfill absensi lama, absensi pada mata kuliah yang tidak diikuti, serta checkout attendance A memakai geofence/waktu jadwal B.
- Perbaikan: signed offline permit dengan user/jadwal/nonce/issued-at/expiry; validasi enrollment, hari, jadwal/MK/semester aktif; cocokkan attendance dengan jadwal; gunakan window setting, bukan angka hardcoded.
- Acceptance: timestamp lampau/future/terlalu awal, jadwal salah, hari salah, mata kuliah bukan milik user, dan replay semuanya ditolak.
- Task: [X] **C-05 Amankan offline sync dengan permit bertanda tangan dan invariant jadwal lengkap.**
- Status 17 Juli 2026: selesai untuk acceptance keamanan. Offline sync mewajibkan opaque permit tersimpan sebagai hash dan dikonsumsi atomik; permit terikat user/jadwal/action/attendance/tanggal/UUID serta memiliki capture dan sync expiry. Server memvalidasi akun/enrollment aktif, enrollment MK, prodi, jadwal/MK/semester/tahun ajaran aktif, hari, window waktu, future skew, `allow_offline_attendance`, dan checkout harus cocok dengan attendance/jadwal/MK/tanggal serta tidak mendahului check-in.
- Verifikasi: test menolak timestamp historis/future, missing/replayed/wrong-bound permit, dan full backend suite 72 test/120 assertion lulus. Retry idempotent setelah response hilang masih dicatat terpisah sebagai M-05/M-06 dan tidak mengizinkan replay berubah.

### [C-06] Queue offline tidak terisolasi per user

- Bukti: `frontend/lib/core/offline/offline_queue_service.dart:7-16`, `offline_queue_item.dart:8-26`, `auth_repository_impl.dart:46-53`, `connectivity_service.dart:68-71`.
- Masalah: satu Hive box global; item tidak menyimpan owner; logout tidak membersihkan queue.
- Dampak: payload offline user A dapat disinkronkan menggunakan token user B pada perangkat bersama dan dicatat sebagai absensi B.
- Perbaikan: namespace dan enkripsi queue per immutable user/session; simpan owner; verifikasi sebelum sync; purge queue/key saat logout atau gunakan flow resolusi eksplisit.
- Acceptance: queue A tidak pernah terlihat atau terkirim saat B login; automated test mencakup logout/login lintas akun.
- Task: [X] **C-06 Isolasi dan enkripsi offline queue per user serta amankan logout.**
- Status 17 Juli 2026: selesai. Queue baru memakai Hive AES-256 box terpisah per immutable `user.id`, key per user disimpan pada OS secure storage, dan setiap item membawa `ownerUserId`. Queue tidak dibuka sebelum auth berhasil; enqueue/read/sync memverifikasi owner. Login/logout/startup dan lifecycle box diserialkan, logout mem-pause serta menunggu sync aktif, lalu menghapus box dan key sebelum akun lain dapat aktif.
- Kebijakan migrasi: box legacy `offline_queue` bersifat plaintext dan ownerless sehingga tidak dapat diatribusikan dengan aman; box tersebut dihapus saat upgrade dan tidak pernah disinkronkan ke user pertama yang login.
- Verifikasi: automated tests membuktikan queue A tidak terlihat oleh B, key/box A dipurge saat logout, owner salah ditolak, activation B menunggu purge A, dan active owner tidak dapat diganti langsung. Full Flutter suite 63 test lulus.

### [C-07] Workflow SP dapat ditandatangani role yang salah dan lintas prodi

- Bukti: `backend/routes/web.php:156-166`, `backend/app/Http/Controllers/Web/SpController.php:94-169`, `backend/resources/js/Pages/Sp/Index.vue:87-119`.
- Masalah: seluruh role dalam group dapat memanggil seluruh endpoint; `canSign` hanya menyembunyikan tombol. Server tidak memvalidasi role penanda tangan, prodi, atau urutan state secara ketat.
- Dampak: pemalsuan tanda tangan/tahapan surat akademik, finalisasi langsung, cancel/download lintas prodi.
- Perbaikan: policy per transition dan state machine transaksional dengan expected prior state.
- Acceptance: hanya role/prodi yang tepat dapat menjalankan transisi yang tepat; setiap transisi diaudit dan invalid transition menghasilkan 409/422.
- Task: [X] **C-07 Terapkan authorization dan state machine server-side untuk workflow SP.**
- Status 17 Juli 2026: selesai. Seluruh mutation API/web menggunakan `SpWorkflowService` dengan row lock dan expected-state transition canonical: `draft -> menunggu_kaprodi -> menunggu_kajur -> final`, serta cancellation hanya dari state nonterminal. Role/prodi divalidasi pada locked record; invalid/repeated transition menghasilkan 409; setiap transition menulis `audit_trails` di transaction yang sama.
- Side effect: regenerasi PDF dan notifikasi dipindahkan ke durable queued job `ProcessSpTransitionSideEffects` dengan retry dan notification idempotency. Cancellation menghapus signature/document reference dan menarik notification approval. Mutator status lama di `SpDocumentService` dihapus dan enum Vue/dashboard diselaraskan.
- Verifikasi: tests mencakup wrong role, admin jurusan read-only, cross-prodi, lompat/ulang state, signer field, audit creation, dan record tetap tidak berubah pada rejection. Full backend suite 76 test/143 assertion lulus; web production build lulus.

## Temuan High

### [H-01] Kontrak PHP tidak cocok dengan lockfile

- Bukti: `backend/composer.json:9`, `backend/composer.lock:3148-3167`, `backend/vendor/composer/platform_check.php:7-22`.
- Dampak: API, migration, scheduler, queue, dan test gagal bootstrap pada PHP 8.3.
- Perbaikan: pilih PHP 8.4 sebagai baseline atau resolve dependency yang mendukung 8.3; tambahkan `config.platform.php`, CI matrix, dan `composer check-platform-reqs`.
- Task: [X] **H-01 Selaraskan versi PHP, Composer dependency, dokumentasi deployment, dan CI.**
- Status 17 Juli 2026: selesai untuk baseline runtime/dependency. Project resmi memakai PHP `~8.3.0` dengan Composer platform `8.3.30`. OpenSpout dipin ke `^4.32`, Symfony ter-resolve ke 7.4, dan Laravel tetap 13.x. Constraint DomPDF yang sebelumnya wildcard dipin ke `^3.1`.
- Verifikasi: PHP default 8.3.30 berhasil menjalankan Composer scripts, `composer validate --strict`, `composer check-platform-reqs`, seluruh migration status, route import/export, dan full backend suite 76 test/143 assertion. `composer audit` tidak menemukan advisory; class OpenSpout reader/writer/options/row/style yang dipakai aplikasi tersedia pada v4.32.0.

### [H-02] Mobile mengirim credential dan biometrik melalui HTTP cleartext

- Bukti: `frontend/lib/core/constants/api_constants.dart:2-4`, `frontend/android/app/src/main/AndroidManifest.xml:21-25`.
- Dampak: bearer token, password, foto/embedding, lokasi, dan payload absensi dapat disadap/diubah di LAN/Wi-Fi.
- Perbaikan: HTTPS production, URL via flavor/`--dart-define`, cleartext hanya debug-host terpilih, release fail-closed bila URL bukan HTTPS.
- Task: [X] **H-02 Wajibkan HTTPS dan nonaktifkan cleartext pada build release.**
- Status 17 Juli 2026: selesai. URL API wajib diberikan melalui `--dart-define=API_BASE_URL`; release/profile fail-closed pada URL kosong/non-HTTPS. Debug HTTP hanya menerima loopback. Android main manifest dan network-security-config menolak cleartext, sementara debug overlay hanya mengizinkan localhost/127.0.0.1. Auth interceptor menolak request cross-origin agar bearer token tidak diteruskan ke host lain.
- Verifikasi: AppConfig tests mencakup HTTPS release, HTTP release rejection, debug loopback allow, dan LAN HTTP rejection; full Flutter suite 66 test lulus.

### [H-03] Token dan PII mobile disimpan plaintext

- Bukti: `frontend/lib/features/auth/data/datasources/auth_local_datasource.dart:20-55`; Android backup tidak dikontrol pada manifest.
- Dampak: token dan profil dapat diekstrak dari backup, perangkat rooted, malware lokal, atau debug tooling.
- Perbaikan: Keystore/Keychain-backed secure storage, minimalkan cache, backup exclusion, token lifetime/rotation yang jelas.
- Task: [X] **H-03 Migrasikan credential ke secure storage dan minimalkan cache PII.**
- Status 17 Juli 2026: selesai untuk storage/backup scope. Token dan profil dipindahkan ke `flutter_secure_storage`; cache profil hanya menyimpan `id`, nama, role, status akun, enrollment status, dan flag ganti password. Migrasi plaintext melakukan write/read-back sebelum menghapus key lama. Android cloud backup/device transfer dinonaktifkan dan seluruh shared preferences/file/database/external data dikecualikan.
- Verifikasi: tidak ada lagi pembacaan/penulisan token aktif melalui SharedPreferences; full Flutter suite dan analyzer perubahan baru lulus. Warning analyzer tersisa hanya L-05 lama.

### [H-04] Enrollment mempercayai liveness client dan auto-approve biometrik

- Bukti: `backend/app/Http/Controllers/Api/Mahasiswa/EnrollmentController.php:31-50`, `:79-99`.
- Masalah: `liveness_passed=true` berasal dari client; embedding langsung `approved`, sementara controller approval mencari `pending`.
- Dampak: embedding buatan dapat langsung aktif dan approval manusia menjadi dead path.
- Perbaikan: lifecycle authoritative `pending -> approved/rejected`; bukti challenge tervalidasi; review sesuai threat model.
- Task: [ ] **H-04 Perbaiki trust dan lifecycle enrollment biometrik.**
- Status 17 Juli 2026: **parsial**. Auto-approval telah dihapus: submit membuat satu candidate `pending`, user menjadi `pending`, dan API/web approval/rejection memakai service transaksional yang mengunci user serta tepat satu candidate dan menulis audit. Attendance membutuhkan user dan embedding approved. Namun server masih menerima `liveness_passed` sebagai klaim client tanpa challenge/capture proof yang diverifikasi server, sehingga task belum boleh `[X]` sampai blocker C-04 diselesaikan.

### [H-05] Face embedding plaintext dan terekspos melalui serializer admin

- Bukti: `backend/database/migrations/2024_01_01_000006_create_face_embeddings_table.php:13-22`, `backend/app/Models/FaceEmbedding.php:22-29`, `backend/app/Http/Controllers/Api/Admin/UserController.php:72-77`.
- Dampak: compromise DB/admin membocorkan template biometrik permanen.
- Perbaikan: encryption at rest dengan key terpisah/KMS, field hiding, endpoint khusus berpolicy, retention/deletion, audit akses.
- Task: [X] **H-05 Enkripsi embedding dan hapus biometrik dari response model umum.**
- Status 17 Juli 2026: selesai. Embedding dienkripsi AES-256-GCM menggunakan key biometrik 32-byte yang terpisah dari `APP_KEY`, memiliki key ID/keyring untuk rotasi, dan fail-closed pada ciphertext/key ID hilang atau tidak dikenal. Migration mengenkripsi legacy row, memverifikasi melalui service, lalu mengosongkan plaintext; rollback destructive dilarang eksplisit. Generic admin serializer hanya mengembalikan metadata enrollment, sementara vector/ciphertext/key ID disembunyikan. Submit, approval/rejection, dan owner embedding access menulis audit tanpa vector/ciphertext; response owner memakai `private, no-store`.
- Verifikasi: migration `2026_07_17_000003` dan plaintext purge `000004` berstatus Ran. Tests membuktikan raw DB tidak memuat vector baru, decrypt menghasilkan vector asli, lifecycle pending/approved berjalan, dan generic admin response tidak memuat vector/ciphertext. Full backend suite 79 test/159 assertion lulus.

### [H-06] Storage/URL foto enrollment dan surat izin tidak konsisten atau publik

- Bukti: `backend/config/filesystems.php:50-61`, `backend/app/Http/Controllers/Web/ApprovalController.php:47-56`, `:175`, `Api/Kaprodi/EnrollmentController.php:33-38`.
- Dampak: foto approval rusak atau data biometrik/dokumen kesehatan terekspos tanpa authorization.
- Perbaikan: private disk + authenticated policy route; short-lived signed URL bila diperlukan; `no-store`; audit akses.
- Task: [X] **H-06 Privatkan seluruh foto enrollment dan dokumen izin dengan authorization.**
- Status 17 Juli 2026: selesai. Foto enrollment/re-enrollment tetap pada disk biometrik privat dan surat izin dipindahkan ke disk dokumen privat. Semua download memerlukan sesi autentik, signed URL 10 menit, object-level owner/prodi authorization, response `private, no-store`, dan audit akses. Path storage disembunyikan dari serializer; migration memindahkan file legacy dari disk publik.
- Verifikasi: negative test membuktikan anonymous dan user lain ditolak, Kaprodi sah dapat mengakses resource satu prodi, response tidak membocorkan path fisik, dan akses menghasilkan audit trail.

### [H-07] Schema re-enrollment tidak memiliki field yang digunakan controller

- Bukti: migration `2024_01_01_000007_create_re_enrollment_requests_table.php:11-20`; controller menulis `foto_baru/new_embedding` di `Api/Mahasiswa/EnrollmentController.php:232-242`; model tidak fillable di `ReEnrollmentRequest.php:10-18`.
- Dampak: data biometrik baru hilang, file orphan, approval gagal atau menyetujui request tanpa embedding baru.
- Perbaikan: migration + fillable/cast; transaction/compensation file; version embedding `max+1`; E2E tests.
- Task: [X] **H-07 Selaraskan schema/model/controller re-enrollment dan cleanup file.**
- Status 17 Juli 2026: selesai. Schema/model kini memiliki foto dan embedding baru terenkripsi. API dan web memakai workflow approval transaksional yang mengunci request/user, menonaktifkan embedding lama, membuat embedding `max(version)+1`, mempertahankan status user `approved`, serta membersihkan foto lama atau foto request yang ditolak. Upload enrollment, re-enrollment, dan izin juga memiliki compensation cleanup saat write database gagal.
- Verifikasi: E2E test membuktikan ciphertext tersimpan tanpa vector plaintext, approval menghasilkan versi 4 setelah versi 3, foto lama terhapus, foto baru tetap tersedia, dan rejection membersihkan file request.

### [H-08] Enum enrollment tidak menerima `not_required`

- Bukti: `2024_01_01_000003_create_users_table.php:34-36`, `Api/Admin/UserController.php:114-122`, `:316-329`.
- Dampak: create/import dosen dan admin gagal pada MySQL strict atau menghasilkan state korup.
- Task: [X] **H-08 Selaraskan domain `enrollment_status` dan test semua jenis user.**
- Status 17 Juli 2026: selesai. Enum schema awal dan migration forward menerima `not_required`; mahasiswa dibuat dengan `belum`, sedangkan seluruh role non-mahasiswa menggunakan `not_required`.
- Verifikasi: integration test membuat setiap role yang tersedia melalui Admin API dan memverifikasi status enrollment pada response serta database.

### [H-09] CRUD semester memakai field yang tidak ada/kurang

- Bukti: schema/model memakai `nama` dan `kode` (`2024_01_01_000009_create_semesters_table.php:13-18`, `Semester.php:11-18`), API memakai `tipe` dan tidak mewajibkan `kode` (`Api/Admin/SemesterController.php:38-82`).
- Dampak: create/update semester gagal; akumulasi alpha/SP ikut terganggu.
- Task: [X] **H-09 Samakan kontrak API semester dengan schema dan tambahkan integration test.**
- Status 17 Juli 2026: selesai. API semester sekarang memakai field canonical `nama` dan `kode`, mewajibkan serta menjaga uniqueness `kode`, memakai allowlist input, dan tetap mengaktifkan/nonaktifkan semester secara transaksional. Dashboard dan template PDF juga tidak lagi membaca field `tipe` yang tidak ada.
- Verifikasi: integration test membuktikan `kode` wajib, create/update dengan `nama` dan `kode` berhasil, serta response sesuai schema.

### [H-10] Field alasan rejection tidak konsisten

- Bukti: schema memakai `rejected_reason`; controller memakai `rejection_reason` di `Api/Kaprodi/ReEnrollmentController.php:85-89` dan `LeaveRequestController.php:73-77`.
- Dampak: alasan keputusan hilang atau write gagal.
- Task: [X] **H-10 Gunakan satu field canonical untuk alasan rejection dan migrasikan data bila perlu.**
- Status 17 Juli 2026: selesai. Seluruh API/web memakai `rejected_reason`. Migration forward menyalin nilai dari kolom legacy `rejection_reason` bila kolom tersebut pernah terbentuk, lalu menghapus kolom legacy.
- Verifikasi: regression test rejection re-enrollment dan izin membuktikan alasan tersimpan pada field canonical. Full backend suite 87 test/219 assertion lulus setelah perubahan H-06 sampai H-10 dan review independen.

### [H-11] Check-in online tidak memiliki attendance time window

- Bukti: `Api/Mahasiswa/AttendanceController.php:50-76`, `:130-149`.
- Dampak: pukul 00:01 pada hari yang sama dapat check-in untuk kelas pukul 10:00 sebagai `hadir`; check-in setelah kelas juga diterima sebagai pending.
- Perbaikan: window awal/akhir eksplisit dari setting dan server time; reject di luar window.
- Task: [X] **H-11 Terapkan window check-in server-side dengan boundary tests.**
- Status 18 Juli 2026: selesai. Permit memakai server time dengan boundary inklusif `jam_mulai - toleransi_masuk` sampai `jam_selesai + toleransi_pulang`; online capture tidak memakai timestamp client.
- Verifikasi: feature test menerima tepat pada kedua boundary dan menolak satu detik sebelum/sesudah window.

### [H-12] Check-in tidak memeriksa status seluruh resource akademik

- Bukti: lookup jadwal di `Api/Mahasiswa/AttendanceController.php:50` tanpa memastikan jadwal, mata kuliah, geofence, semester, dan tanggal akademik aktif.
- Dampak: jadwal/MK/geofence nonaktif masih dapat digunakan.
- Task: [X] **H-12 Terapkan invariant jadwal, MK, geofence, enrollment, dan semester aktif.**
- Status 18 Juli 2026: selesai. Permit issuance dan consumption memeriksa user/enrollment, pivot MK, prodi, jadwal, MK, geofence, semester, tahun ajaran, status aktif, hari, serta inclusive academic date range.
- Verifikasi: negative tests mencakup setiap resource nonaktif, enrollment hilang, dan tanggal semester berakhir.

### [H-13] Check-out tidak dapat dicapai dari UI Flutter

- Bukti: router mendukung checkout di `frontend/lib/main.dart:148-161`, tetapi card hanya dapat ditekan saat belum check-in di `home_page.dart:274-288`.
- Dampak: pengguna tidak dapat checkout; durasi efektif dan alpha pulang awal tidak berjalan dari UI normal.
- Task: [ ] **H-13 Tambahkan action checkout yang dapat dicapai dan integration test navigasi.**
- Status 18 Juli 2026: implementasi UI selesai, acceptance test parsial. Card memakai `canOpenAttendance`, menyediakan semantic checkout label dan stable key, serta route menerima `attendanceId`. Unit/domain test membuktikan checked-in schedule membuka checkout; integration test navigasi penuh belum tersedia sehingga checkbox tetap terbuka.

### [H-14] Payload offline checkout Flutter melanggar kontrak backend

- Bukti: mobile hanya menyertakan `jadwal_id` untuk check-in (`attendance_page.dart:377-414`), backend mewajibkan `attendances.*.jadwal_id` untuk semua item (`OfflineSyncController.php:36-41`).
- Dampak: checkout offline selalu 422 dan satu item dapat menggagalkan seluruh batch.
- Task: [X] **H-14 Selaraskan kontrak offline checkout frontend/backend dan tambah contract test mixed batch.**
- Status 18 Juli 2026: selesai. Flutter selalu mengirim `jadwal_id`; checkout juga mengirim `attendance_id` dan `type=check_out`. Backend mewajibkan attendance ID hanya untuk checkout dan menggunakan permit expiry sebagai batas canonical.
- Verifikasi: mixed-batch feature test memproses satu check-in dan satu checkout dengan dua permit/UUID.

### [H-15] Item offline dapat terkunci permanen pada `syncing`

- Bukti: `connectivity_service.dart:63-71`, `offline_queue_service.dart:33-39`, `:64-69`.
- Dampak: crash/reboot setelah `markSyncing` membuat item tidak pernah diproses kembali.
- Perbaikan: lease dengan `syncStartedAt` dan stale recovery atau state machine outbox yang retry-safe.
- Task: [X] **H-15 Implementasikan crash recovery dan lease untuk offline queue.**
- Status 18 Juli 2026: selesai. Item syncing menyimpan `syncStartedAt`; lease default lima menit. Aktivasi queue memulihkan lease stale/legacy tanpa menaikkan retry, sedangkan fresh lease tidak disentuh.
- Verifikasi: restart tests mencakup stale recovery dan fresh lease retention.

### [H-16] Konversi NV21 dapat gagal pada Android

- Bukti: kamera dipaksa NV21 di `attendance_page.dart:213-220`; converter non-BGRA mengakses tiga plane di `face_recognition_service.dart:110-155`.
- Dampak: NV21 satu-plane dapat menghasilkan `RangeError` dan face verification gagal terus.
- Task: [ ] **H-16 Implementasikan converter berdasarkan format/jumlah plane dan uji perangkat fisik.**
- Status 18 Juli 2026: implementasi dan unit contract selesai. Converter dispatch berdasarkan format+plane count untuk BGRA one-plane, NV21 one-plane, dan YUV420 three-plane dengan row/pixel stride. Physical Android device matrix belum diuji sehingga checkbox tetap terbuka.

### [H-17] Dukungan iOS yang ada tidak berfungsi

- Bukti: `frontend/ios/Runner/Info.plist` tidak memiliki camera/location usage description; kode meminta `androidInfo` dan NV21 pada jalur umum.
- Dampak: permission ditolak/crash dan device info gagal pada iOS.
- Perbaikan: implementasikan branch iOS lengkap atau keluarkan iOS secara eksplisit dari release matrix.
- Task: [ ] **H-17 Putuskan dukungan iOS dan selesaikan konfigurasi/platform path atau hapus target release.**
- Status 18 Juli 2026: basic iOS path diperbaiki: camera/location usage descriptions, BGRA camera input, iOS device metadata, dan Podfile tersedia. macOS build, signing/capability, Firebase decision, dan physical iPhone smoke test belum ada; iOS belum masuk release matrix.

### [H-18] Release Android memakai debug signing key

- Bukti: `frontend/android/app/build.gradle.kts:31-36`.
- Dampak: release identity/provenance dan continuity update tidak aman.
- Task: [X] **H-18 Konfigurasikan release signing aman melalui CI/secret manager.**
- Status 18 Juli 2026: selesai. Gradle membaca untracked properties atau environment secrets dan release fail-closed tanpa keystore. GitHub workflow memulihkan temporary keystore, memvalidasi protected HTTPS `API_BASE_URL`, membangun AAB, mengunggah artifact, lalu membersihkan keystore.
- Verifikasi: local release tanpa secrets gagal eksplisit dan tidak fallback ke debug signing.

### [H-19] Akun nonaktif tetap dapat memakai token lama

- Bukti: status hanya dicek saat login (`Api/AuthController.php:38-40`); toggle tidak revoke token (`Api/Admin/UserController.php:379-391`).
- Dampak: suspend akun tidak efektif untuk sesi aktif.
- Task: [X] **H-19 Terapkan active-user middleware dan revoke token/session saat deactivation.**
- Status 18 Juli 2026: selesai. API dan web protected groups memakai `user.active`; status nonaktif menghasilkan 403, token dicabut, dan session database dihapus pada seluruh jalur deactivation yang diperbarui.
- Verifikasi: token yang terbit sebelum account deactivation ditolak dan terhapus.

### [H-20] Seeder/import/default password sangat mudah ditebak

- Bukti: `DatabaseSeeder.php:11-23`, `UserSeeder.php:13-25`, `Web/UserController.php:312-314`, `:377-382`, serta fallback password API/import.
- Dampak: akun administratif/mahasiswa dapat diambil alih bila seeder/import digunakan di lingkungan nyata.
- Perbaikan: blok demo seeder di production; one-time random activation; jangan gunakan NIM/NIDN atau password universal.
- Task: [X] **H-20 Hapus seluruh default password universal dan amankan activation/import.**
- Status 18 Juli 2026: selesai. API/web import dan provisioning menggunakan random 32-character placeholder, akun tanpa explicit password nonaktif dengan `activation_pending`, dan one-time reset mengaktifkan hanya pending activation. NIM/NIDN/password universal tidak digunakan. User demo seeder diblokir di production dan menghasilkan akun nonaktif/random di non-production.
- Verifikasi: full backend suite lulus setelah migration `activation_pending` dan provisioning flow diterapkan.

### [H-21] Monitoring, dashboard, laporan, dan setting bocor lintas scope

- Bukti: `Web/AttendanceController.php:27-52`, `Web/DashboardController.php:35-129`, `Web/ReportController.php:28-205`, `Web/SettingController.php:25-37`, `:98-128`.
- Masalah: dosen/admin prodi dapat menerima data global atau override scope menggunakan query/object ID.
- Dampak: kebocoran NIM, pola kehadiran, statistik, dan perubahan threshold prodi lain.
- Task: [X] **H-21 Buat actor-based scope canonical untuk attendance, dashboard, report, export, dan setting.**
- Status 18 Juli 2026: selesai. `AuthorizationService` menjadi sumber scope canonical untuk user, prodi, mata kuliah, dan attendance. Monitoring, dashboard, report/PDF/XLSX, object lookup, serta setting web/API kini selalu memakai scope actor; filter request hanya dapat mempersempit scope. Dosen dibatasi ke mata kuliah yang diampu, role prodi/jurusan fail-closed ke `prodi_id` actor, sedangkan akses global hanya untuk `super_admin`.
- Verifikasi: regression test lintas prodi dan full backend suite lulus; export menggunakan path unik agar request bersamaan tidak saling menimpa.

## Temuan Medium

### [M-01] Approval izin tidak membuat attendance lengkap dan alpha dapat tetap penuh

- Bukti API: `Api/Kaprodi/LeaveRequestController.php:48-56`; web: `Web/ApprovalController.php:166-201`; job melewati approved leave di `MarkAbsentAttendance.php:58-68`.
- Dampak: izin approved tetap menambah alpha atau tidak muncul dalam denominator laporan; rentang multi-hari hanya memproses tanggal mulai/record pertama.
- Task: [X] **M-01 Satukan service approval izin yang membuat/update semua sesi dan recalculation alpha.**
- Status 18 Juli 2026: selesai. `LeaveApprovalService` memproses seluruh tanggal dan jadwal dalam rentang izin secara transaksional, membuat atau memperbarui attendance menjadi `izin`/`sakit` dengan alpha nol, mencatat log/audit, dan mengevaluasi ulang SP pada semester mata kuliah. Job alpha juga mematerialisasi approved leave legacy, bukan melewatinya.

### [M-02] Override Kaprodi tidak menjaga alpha, SP, dan audit

- Bukti: `Api/Kaprodi/AttendanceController.php:32-42` hanya mengganti status/metadata.
- Dampak: status dan `alpha_menit` bertentangan; SP tidak ikut berubah; jejak audit tidak lengkap.
- Task: [X] **M-02 Pusatkan override attendance dalam service transaksional dengan audit dan recalculation.**
- Status 18 Juli 2026: selesai. Override Kaprodi dan dosen memakai `AttendanceWorkflowService` dengan transaction, row lock, derivasi alpha canonical, `AttendanceLog`, `AuditTrail`, dan evaluasi ulang akumulasi/SP pada semester attendance.

### [M-03] Approval attendance web selalu menghasilkan `hadir_terlambat`

- Bukti: `Web/DosenAttendanceController.php:157-169`.
- Dampak: kehadiran dengan alpha 0 tetap diklasifikasikan terlambat.
- Task: [X] **M-03 Perbaiki derivasi status approval dosen dan gunakan toleransi prodi.**
- Status 18 Juli 2026: selesai. Approval pending web/API memakai workflow yang sama: check-in sampai batas `toleransi_masuk_menit` menjadi `hadir` dengan alpha nol, sedangkan check-in setelah batas menjadi `hadir_terlambat` dengan alpha aktual.

### [M-04] Notifikasi SP tidak terkirim karena mismatch casing

- Bukti: database/service alpha memakai lowercase, flag map/recipient memakai uppercase di `SpDetectionService.php:33-35`, `:101-117`, `:160`, `:264-287`.
- Dampak: notifikasi SP1/SP2/SP3/DO dan flag notified tidak berjalan.
- Task: [X] **M-04 Gunakan enum level SP canonical dan test seluruh transisi/recipient.**
- Status 18 Juli 2026: selesai. Enum `SpLevel` memakai nilai persistence lowercase canonical dan label display terpisah. Flag, payload, urgent handling, transisi, dan matriks recipient SP1/SP2/SP3/DO telah diseragamkan. Evaluasi, pembuatan notifikasi, dan perubahan flag diserialkan dalam transaksi untuk mencegah duplikasi concurrent.

### [M-05] Idempotency offline checkout tidak bekerja

- Bukti: lookup hanya `attendances.client_uuid` (`OfflineSyncController.php:78-93`); UUID checkout tidak pernah disimpan (`:305-315`).
- Dampak: retry setelah response hilang menjadi gagal, bukan duplicate/success yang sama.
- Task: [X] **M-05 Tambahkan idempotency record terpisah atau `checkout_client_uuid` unik.**
- Status 18 Juli 2026: selesai. Attendance memiliki `checkout_client_uuid` nullable dengan unique key per user. Replay hanya mengembalikan outcome lama setelah token dan immutable permit binding tervalidasi; retry check-in/checkout menghasilkan `duplicate` yang dihitung sukses, sementara permit consumed tanpa outcome fail-closed. Checkout mengunci attendance dan menyimpan UUID, mutasi, konsumsi permit, serta log secara atomik.
- Verifikasi M-01--M-05: regression test mencakup izin multi-hari/multi-jadwal, semester nonaktif, override/audit/SP, batas toleransi, seluruh transisi dan recipient SP, retry setelah expiry, wrong binding, stale checkout permits, dan unique constraint. Full backend suite lulus 125 test dengan 409 assertion.

### [M-06] Online `client_uuid` dikirim tetapi tidak divalidasi/disimpan

- Bukti mobile `attendance_page.dart:377-394`; backend `AttendanceController.php:27-41`, `:175-191`.
- Dampak: online idempotency hanya bergantung pada unique attendance, bukan request identity.
- Task: [X] **M-06 Implementasikan idempotency online yang konsisten atau hapus kontrak palsu.**
- Status 18 Juli 2026: selesai. `client_uuid` online wajib, terikat ke permit, dan disimpan sebagai identity check-in/checkout dengan unique constraint. Penerbitan permit juga retry-idempotent: binding yang sama mengembalikan token/challenge terenkripsi yang sama, reuse dengan binding berbeda atau permit consumed menghasilkan `409`. Replay identity yang sama mengembalikan outcome canonical, sedangkan UUID berbeda pada occurrence yang sudah committed menghasilkan conflict eksplisit.

### [M-07] Race condition pada attendance, approval, scheduler, dan notifikasi

- Bukti: checkout dan approve/reject melakukan read-modify-write tanpa lock; `SpDetectionService` memeriksa flag lalu notify tanpa transaksi; `MarkAbsentAttendance` check-then-insert.
- Dampak: duplicate log/notifikasi, last-write-wins, batch job berhenti karena duplicate key.
- Task: [X] **M-07 Gunakan transaction, `lockForUpdate`, conditional update, dan outbox/idempotency.**
- Status 18 Juli 2026: selesai. Mutation attendance/leave memakai lock order canonical, strict single transition, transaction, audit/log atomik, dan evaluasi SP setelah commit. Reject izin dipusatkan dalam workflow terkunci; scheduler alpha dan auto-close idempotent, collision-safe, serta tidak menimpa checkout manual atau permit checkout offline yang masih valid. Notification outbox memiliki unique idempotency key, row claim, retry backoff, stale-claim recovery, dan dead-letter agar poison row tidak memblokir item berikutnya. Jaminan external push tetap at-least-once/best-effort, bukan exactly-once.

### [M-08] Queue batch memperlakukan permanent dan transient failure sama

- Bukti: `frontend/lib/core/network/connectivity_service.dart:56-105` dan `offline_queue_service.dart:51-60`.
- Dampak: satu poison item 422 menggagalkan item valid; semua item menghabiskan retry.
- Task: [X] **M-08 Validasi lokal, klasifikasikan error, split poison item, dan gunakan backoff.**
- Status 18 Juli 2026: selesai. Queue Flutter memvalidasi payload sebelum kirim, membedakan permanent/transient/auth-blocked failure, mengisolasi poison HTTP 422 dengan stable recursive split, dan memakai exponential backoff terjadwal serta `Retry-After`. Timeout/429/5xx tidak di-split; response malformed/missing diperlakukan transient. Claimed item selalu dikembalikan ke pending bila pause/shutdown terjadi sebelum outcome, dan permanent failure tidak ikut retry-all. Queue legacy tidak lagi dihapus diam-diam, tetapi dikarantina dengan status recovery dan adapter tetap membaca schema lama.

### [M-09] Startup dapat menganggap user authenticated setelah token ditolak

- Bukti: `auth_interceptor.dart:23-28`, `auth_repository_impl.dart:57-79`, `auth_bloc.dart:52-62`.
- Dampak: cached user dapat membuat UI authenticated tanpa token valid.
- Task: [X] **M-09 Jadikan 401 terminal dan definisikan state offline-auth terpisah bila diperlukan.**
- Status 18 Juli 2026: selesai. `/auth/me` 401 bersifat terminal dan repository tidak lagi memakai cached profile sebagai bukti autentikasi. Network/5xx/malformed response menghasilkan state verifikasi tidak tersedia yang tetap menutup protected UI. Produk tidak memiliki kontrak offline-auth, sehingga startup diterapkan fail-closed. Refresh profile membawa snapshot generation dan hasil stale tidak dapat menghidupkan sesi setelah logout, invalidation, atau login baru.

### [M-10] 401 saat sesi aktif tidak mengubah AuthBloc

- Bukti: interceptor hanya menghapus storage; `main_shell.dart:33-40` menunggu state bloc.
- Dampak: pengguna tetap berada di halaman internal dan menerima error berulang.
- Task: [X] **M-10 Tambahkan centralized session invalidation single-flight.**
- Status 18 Juli 2026: selesai. `SessionCoordinator` menyediakan token-generation snapshot, compare-and-invalidate, stream invalidation, dan single-flight cleanup. Hanya protected same-origin request yang benar-benar membawa bearer aktif yang dapat menginvalidasi sesi; 401 login/public endpoint dan stale response token lama diabaikan. `AuthBloc` melakukan teardown serial `pause sync -> purge owner queue -> unauthenticated`, top-level auth gate membuang seluruh protected route stack, dan sync 401 menghentikan batch berikutnya tanpa menghabiskan retry.
- Verifikasi M-06--M-10: full backend suite lulus 135 test dengan 480 assertion; full Flutter suite lulus 106 test; `flutter analyze` bersih. Review independen terakhir tidak menemukan blocking finding. Residual concurrency risk diuji melalui row lock, unique constraint, conditional transition, stale-writer simulation, dan recovery state; belum ada harness multi-process database production.

### [M-11] Posisi dan jam perangkat terlalu dipercaya

- Bukti: posisi hanya diambil sebelum liveness (`attendance_page.dart:154-195`, `:374-394`); eligibility memakai `DateTime.now()` (`home_entities.dart:38-55`).
- Dampak: stale GPS fix dan manipulasi jam memengaruhi UI/timestamp offline.
- Task: [X] **M-11 Refresh GPS sebelum submit, batasi age/accuracy, dan gunakan server time anchor.**
- Status 18 Juli 2026: selesai. Backend menyediakan window, waktu server, dan policy lokasi canonical pada jadwal/permit serta menegakkan batas accuracy dan age yang sama pada check-in, checkout, dan offline sync. Flutter memakai monotonic `ServerTimeAnchor`, fail-closed tanpa anchor, dan selalu mengambil GPS authoritative baru tepat sebelum payload. Source timestamp provider ikut divalidasi sehingga cached/null/future/stale fix ditolak; timestamp offline tidak lagi berasal dari jam perangkat.

### [M-12] Rotasi kamera dan frame-binding liveness belum benar-benar aman

- Bukti enrollment hardcoded 270 derajat (`enrollment_page.dart:102-109`); attendance hanya sensor orientation; `_lastCameraImage` dapat berubah saat face lama masih diproses.
- Dampak: crop wajah salah, false reject, dan liveness/embedding dapat berasal dari frame berbeda.
- Task: [X] **M-12 Buat immutable frame pipeline dengan rotasi platform/lens/device yang benar.**
- Status 18 Juli 2026: selesai. Plane kamera disalin ke immutable snapshot dengan attempt/frame identity dan metadata platform, lens, sensor, serta device orientation. Pipeline single-inflight hanya memberi sequence pada frame yang diterima, sehingga dropped frame tidak mencampur atau memutus evidence. Detector, liveness, canonical upright pixels, crop, dan embedding attendance memakai snapshot yang sama. Tracking ID null/change, orientation change, zero/multiple face, stale attempt, dan gap evidence mereset secara fail-closed. Enrollment mengikat final JPEG ke embedding frame liveness dengan threshold dan continuity window eksplisit.

### [M-13] Foto temporary enrollment tidak dibersihkan

- Bukti: `enrollment_page.dart:227-250`, `:337-400`.
- Dampak: foto wajah tertinggal di cache lebih lama dari kebutuhan.
- Task: [X] **M-13 Hapus temporary face capture dalam `finally` dan tetapkan retention policy.**
- Status 18 Juli 2026: selesai. Seluruh hasil `takePicture()` dimiliki `TemporaryCaptureProcessor` dan dihapus setelah bytes serta ML Kit selesai memakai path, pada jalur sukses maupun error. Delete failure dicatat ke registry bounded untuk retry startup/resume hanya pada exact app-owned paths dengan retention maksimum satu jam; primary processing error tetap dipertahankan dan path sensitif tidak dicatat ke log.

### [M-14] Duplicate-face endpoint menjadi biometric identity oracle

- Bukti: backend `EnrollmentController.php:116-158`; mobile menampilkan nama/NIM/kelas di `enrollment_page.dart:253-263`, `:380-385`.
- Dampak: probing embedding membocorkan identitas mahasiswa lain.
- Task: [X] **M-14 Kembalikan konflik anonim, rate-limit khusus, dan audit probing biometrik.**
- Status 18 Juli 2026: selesai. Probe dan final enrollment memakai `BiometricDuplicateService` canonical dan konflik anonim `409 BIOMETRIC_CONFLICT` tanpa nama, NIM, kelas, ID, distance, atau threshold serta response `no-store`. Limiter khusus menerapkan ceiling user per menit/jam dan IP. Audit hanya menyimpan actor, outcome, ukuran embedding, IP, dan user-agent. Collision set mencakup pending/approved; check-create dan approval diserialkan global serta approval mengulang authoritative duplicate check agar kandidat concurrent tidak sama-sama lolos.

### [M-15] Health endpoint membocorkan detail internal

- Bukti: `backend/routes/api.php:27-67` menyertakan environment dan pesan exception.
- Dampak: reconnaissance database/cache/storage; setiap probe juga melakukan write/delete.
- Task: [X] **M-15 Pisahkan public liveness minimal dari private readiness detail.**
- Status 18 Juli 2026: selesai. Public `/api/health` hanya mengembalikan `{"status":"ok"}` tanpa dependency check. `/api/healthz` membutuhkan user aktif `super_admin` dan hanya menjalankan probe read-only: `SELECT 1`, cache `get`, serta pembacaan sentinel storage. Response readiness hanya berisi status generic `ok/unavailable`, tanpa environment, version, path, exception, atau write/delete probe; `/up` dipertahankan sebagai compatibility liveness.
- Verifikasi M-11--M-15: full backend suite lulus 150 test dengan 587 assertion; full Flutter suite lulus 152 test; `flutter analyze` dan formatter bersih. Pipeline kamera tetap memerlukan smoke test perangkat fisik Android/iOS untuk kombinasi lens dan orientation, sedangkan scalar attendance dari client tetap merupakan client-attested evidence sampai tersedia trusted verifier/platform attestation terpisah.

### [M-16] Export menggunakan atribut salah dan report export tidak sesuai tampilan

- Bukti: `AttendanceExport.php:96-97`, `:131-132` membaca `sp_level`, `check_in_time`, `check_out_time`; model memakai `sp_status`, `checkin_time`, `checkout_time`. `Web/ReportController.php:65-81` mengabaikan beberapa filter/tab yang dikirim Vue.
- Dampak: kolom kosong, SP selalu aman, dan file yang diunduh berbeda dari laporan layar.
- Task: [X] **M-16 Samakan export dengan model/query layar dan tambah content-level workbook tests.**
- Status 26 Juli 2026: selesai. Bagian atribut sudah canonical: `AttendanceExport` memakai `checkin_time`/`checkout_time`/`sp_status`. Paritas filter ditutup: `Web/ReportController::exportExcel` kini membaca `tab`, `semester_id`, `kelas`, `mata_kuliah_id`, dan `user_id`, serta menetralkan filter yang tidak relevan dengan tab aktif sehingga hasil unduhan sama dengan tampilan layar. `AttendanceExport` menerima filter `userId` dan menerapkannya pada summary, detail, dan sheet alpha.
- Verifikasi: `AttendanceExportRegressionTest` (content-level workbook) dan `ReportExportParityTest` membuktikan filter mahasiswa benar-benar mempersempit isi workbook.

### [M-17] Query N+1 dan full-table analysis berisiko timeout

- Bukti: `Api/Admin/ReportController.php`, `Api/Dosen/AttendanceController.php`, `AttendanceExport.php`, `Web/DosenAttendanceController.php`, `Web/ReportController.php`, dan `Web/AnalysisController.php` melakukan query per mahasiswa/MK atau `get()` seluruh log.
- Dampak: puluhan ribu query dan memory exhaustion pada dataset realistis.
- Task: [X] **M-17 Ganti dengan aggregate query/grouping, pagination/chunk, dan query-count benchmark.**
- Status 26 Juli 2026: selesai untuk jalur export dan analisis. `AttendanceExport` tidak lagi melakukan query per mahasiswa: statistik status, akumulasi alpha, dan rincian alpha per mata kuliah memakai `GROUP BY` batch. `Web/AnalysisController` mengganti `->get()` full-table dengan agregat SQL untuk distribusi face distance, statistik geofence, dan latency (termasuk agregasi per device); percentile hanya mengambil satu kolom terurut melalui offset.
- Verifikasi: `ExportQueryCountTest` membuktikan jumlah query export konstan saat jumlah mahasiswa dinaikkan dari 2 ke 8, dan `AttendanceExportRegressionTest` membuktikan isi workbook tetap benar setelah optimasi.

### [M-18] Pagination dan sorting tidak dibatasi

- Bukti: banyak controller menerima `per_page` dan kolom sorting langsung; contoh `Api/Admin/UserController.php:59-64` dan `Web/UserController.php:33`.
- Dampak: authenticated memory/DB DoS dan error dari nama kolom arbitrer.
- Task: [X] **M-18 Buat pagination/sort helper dengan clamp dan allowlist global.**
- Status 26 Juli 2026: selesai. Helper `App\Traits\ResolvesListQuery` di-mount pada base `Controller` sehingga tersedia global: `resolvePerPage` (clamp maksimum 100, fallback default per endpoint) dan `resolveSort` (allowlist kolom + arah tervalidasi). Seluruh controller list API dan Web sekarang memakai `resolvePerPage`; tidak ada lagi `->paginate($request->get('per_page'...))` atau `integer('per_page'...)` mentah. Sort web sudah pakai allowlist per controller; `Api/Admin/UserController` memakai allowlist konstanta `USER_SORTABLE`. Regression `ListQueryHardeningTest` membuktikan sort kolom arbitrer diabaikan dan `per_page` besar di-clamp.

### [M-19] Cascade delete dapat menghapus rekam akademik secara transitif

- Bukti: FK cascade dari tahun/semester/prodi/MK/jadwal ke attendance, SP, leave, embedding, dan logs pada migration awal.
- Dampak: satu hard delete master dapat menghilangkan sejarah kehadiran dan dokumen disipliner.
- Task: [X] **M-19 Ubah lifecycle master historis menjadi restrict/archive dan uji restore/retention.**
- Status 9 Agustus 2026: selesai. Migration `2026_07_26_000002_restrict_historical_master_deletes` mengubah FK spine historis dari `ON DELETE CASCADE` menjadi `RESTRICT` sehingga database menolak hard delete master yang masih menyimpan riwayat: `attendances(user_id,jadwal_id,mata_kuliah_id)`, `sp_records(user_id,semester_id)`, `leave_requests(user_id,mata_kuliah_id)`, `alpha_accumulations(user_id,semester_id)`, `attendance_logs(user_id)`, dan `face_embeddings(user_id)`. Kolom aktor (`approved_by`/`overridden_by`/`generated_by`/`signed_*`) tetap `SET NULL` karena bukan tulang punggung riwayat, dan `attendance_logs.attendance_id` tetap `SET NULL` agar log bertahan meski attendance dihapus. Jalur arsip (soft delete) sudah tersedia pada master pemilik riwayat langsung — `MataKuliah`, `Jadwal`, dan `User` memakai `SoftDeletes` — sehingga menonaktifkan master tidak menghancurkan sejarah dan dapat di-restore. Guard aplikasi `Api/Admin/SemesterController::destroy` diperkuat untuk juga menolak hapus semester yang masih memiliki `sp_records`/`alpha_accumulations`, konsisten dengan RESTRICT database.
- Verifikasi: migration idempotent, rollback membalikkan ke CASCADE, dan `migrate:fresh` lulus. Regression `HistoricalMasterLifecycleTest` membuktikan hard delete user/semester/mata_kuliah/jadwal yang masih memiliki riwayat ditolak database (riwayat tetap utuh = retention), arsip `MataKuliah` dapat di-restore tanpa kehilangan kehadiran, dan hard delete permanen baru berhasil setelah sejarah dibersihkan terkontrol.

### [M-20] Constraint dan index database belum menjaga invariant domain

- Bukti: tidak ada check untuk koordinat/radius, urutan waktu/tanggal, threshold SP; unique MK dengan `kelas=NULL` masih dapat duplikat; tidak ada invariant satu embedding/request aktif; index tidak mengikuti query utama.
- Dampak: state invalid akibat import/race/script dan performa turun saat data membesar.
- Task: [X] **M-20 Tambahkan check/unique/composite index berdasarkan invariant dan hasil `EXPLAIN`.**
- Status 26 Juli 2026: selesai. Migration `2026_07_26_000001_add_domain_invariant_constraints` menambahkan CHECK constraint untuk koordinat/radius geofence, urutan `jam_selesai > jam_mulai` pada jadwal, urutan tanggal semester/tahun ajaran, `sks`/`total_pertemuan` positif, serta toleransi/geofence/persentase dan urutan threshold SP1-SP2-SP3-DO yang tidak boleh tumpang tindih pada `prodi_settings`. Duplikat mata kuliah dengan `kelas IS NULL` ditutup melalui generated column `kelas_key = COALESCE(kelas,'')` plus unique `(kode_mk, semester_id, kelas_key)`. Composite index ditambahkan mengikuti query utama: `attendances(user_id,tanggal)`, `attendances(jadwal_id,tanggal)`, `attendances(mata_kuliah_id,status)`, `jadwals(hari,status)`, dan `face_embeddings(user_id,status)`. Model `MataKuliah` membuang kolom generated pada insert/update/`replicate()`.
- Verifikasi: data existing diperiksa bersih sebelum constraint dipasang; migration idempotent dan rollback/re-migrate serta `migrate:fresh` lulus. Regression `DomainInvariantConstraintTest` membuktikan koordinat di luar rentang, radius nonpositif, jadwal terbalik, rentang tanggal terbalik, threshold SP tumpang tindih, dan duplikat MK `kelas` NULL semuanya ditolak database. Full backend suite 164 test/607 assertion lulus.

### [M-21] Session/login/2FA belum cukup hardened

- Bukti: route web login dan TOTP tidak memiliki throttling yang terlihat (`routes/web.php:50-53`, `:73-75`); env production session belum fail-closed; change password tidak revoke sesi lain (`Web/ProfileController.php:79-104`).
- Dampak: brute force dan sesi kompromi tetap aktif.
- Task: [X] **M-21 Tambahkan throttle login/TOTP, secure session profile, dan session/token revocation.**
- Status 26 Juli 2026: parsial. `POST /login` memakai named limiter `throttle:login` dengan keying gabungan IP+identitas (5/menit) plus batas per IP yang lebih longgar (30/menit) agar brute force satu akun tertutup tanpa mengunci pengguna sah di belakang NAT kampus. `POST /two-factor/verify` memakai `throttle:5,1`. `Web/ProfileController::updatePassword` mencabut seluruh Sanctum token, menghapus session database milik user selain sesi web saat ini, dan me-regenerate sesi aktif. Regression `WebAuthHardeningTest` membuktikan login web 429 setelah 5 percobaan dan change-password mencabut token + sesi lain.
- Status 9 Agustus 2026: selesai. Secure session profile kini fail-closed di production melalui `AppServiceProvider::register()`: saat `app()->environment('production')` dan operator tidak meng-set env cookie, aplikasi memaksa `session.secure=true` dan `session.http_only=true`, serta menormalkan `session.same_site` ke minimal `lax` (menolak nilai kosong/null yang membuat cookie dikirim lintas situs). `SameSite=none` otomatis dipasangkan dengan `Secure` agar diterima browser. Default berlaku sebelum `StartSession` boot karena diset di `register()`. `.env.example` diperbarui: `SESSION_SECURE_COOKIE` tidak lagi hardcoded `false` (dikomentari + panduan agar production membiarkannya kosong/true), plus `SESSION_HTTP_ONLY=true` dan `SESSION_SAME_SITE=lax`.
- Verifikasi: `WebAuthHardeningTest` menambah tiga skenario — production memaksa `secure`+`http_only`+`same_site=lax` walau env tidak diset, environment non-production tidak memaksa `secure` (dev via HTTP tetap jalan), dan `SameSite=none` di production otomatis memaksa `secure=true`.

### [M-22] Logging dan error handling berpotensi membocorkan data sensitif

- Bukti: Flutter `LogInterceptor` mencatat headers/body/response di debug (`api_client.dart:30-40`); berbagai `e.toString()` ditampilkan/disimpan; import web menyimpan `$e->getMessage()`.
- Dampak: token, password, embedding, lokasi, SQL detail, dan PII masuk log/UI/queue.
- Task: [X] **M-22 Terapkan typed error, redaction, correlation ID, dan logging sensitif default-off.**
- Status 26 Juli 2026: selesai untuk jalur yang terbukti membocorkan data. `App\Support\SafeErrorMessage` mengubah exception menjadi pesan generik bertagar correlation ID; detail lengkap hanya masuk log aplikasi dan `QueryException` tidak pernah meneruskan fragmen SQL. Import user API dan web memakai helper ini sehingga pesan mentah tidak lagi tampil di response/UI. `LogInterceptor` Flutter sudah default-off untuk header dan body request/response.
- Verifikasi: `SafeErrorMessageTest` membuktikan nilai baris, fragmen SQL, nama index, path file, dan token tidak muncul pada pesan yang ditampilkan, sementara correlation ID tetap tersedia untuk penelusuran.

## Temuan Low

### [L-01] Dependency constraints terlalu longgar

- Bukti: `barryvdh/laravel-dompdf: *`; mayoritas Flutter dependency memakai `any`.
- Dampak: update sulit direview dan resolusi ulang dapat menarik breaking release.
- Task: [X] **L-01 Pin direct dependency ke compatible range dan gunakan frozen install di CI.**
- Status 26 Juli 2026: selesai untuk manifest. `barryvdh/laravel-dompdf` sudah `^3.1`, dan seluruh dependency Flutter yang sebelumnya `any` dipin ke caret range sesuai versi resolved saat ini (`flutter_bloc ^9.1.1`, `dio ^5.9.2`, `camera ^0.12.0+1`, `geolocator ^14.0.1`, `firebase_messaging ^16.2.2`, dan lainnya). `flutter pub get` tetap resolve tanpa perubahan versi, `flutter analyze` bersih, dan 152 test Flutter lulus.

### [L-02] FCM lifecycle mobile belum diimplementasikan

- Bukti: dependency dan endpoint ada, tetapi tidak ditemukan Firebase init, permission, token refresh, foreground/background handler, atau revoke saat logout.
- Dampak: push notification mobile kemungkinan tidak berfungsi.
- Task: [ ] **L-02 Implementasikan lifecycle FCM end-to-end atau hapus klaim fitur sampai siap.**

### [L-03] Auto-close memakai toleransi hardcoded dan rule missing checkout tidak jelas

- Bukti: `AutoCloseAttendance.php:22-52` memakai 15 menit, sementara endpoint memakai setting prodi.
- Dampak: scheduler dan checkout manual menghasilkan kebijakan berbeda.
- Task: [X] **L-03 Tetapkan policy missing-checkout dan gunakan setting prodi secara konsisten.**
- Status 26 Juli 2026: selesai. `AutoCloseAttendance` (di `app/Console/Commands/`) membaca `toleransi_pulang_menit` dari `ProdiSetting` per jadwal (baris 34-35); angka 15 hanya fallback bila setting null, konsisten dengan endpoint checkout.

### [L-04] Jadwal back-to-back dianggap bentrok

- Bukti: `Api/Admin/JadwalController.php:68-80` memakai `whereBetween` inklusif.
- Dampak: 08:00-09:00 dan 09:00-10:00 dianggap overlap.
- Task: [X] **L-04 Gunakan interval setengah terbuka untuk validasi bentrok jadwal.**
- Status 26 Juli 2026: selesai. `Api/Admin/JadwalController::store` dan `::update` memakai overlap setengah terbuka `jam_mulai < new.jam_selesai AND jam_selesai > new.jam_mulai`. Review lanjutan juga menemukan bahwa `update()` tidak memvalidasi urutan waktu terhadap nilai efektif, sehingga update parsial (hanya `jam_selesai`) dapat menghasilkan rentang terbalik; validasi ditambahkan agar menghasilkan 422, bukan pelanggaran CHECK constraint. Regression `JadwalConflictRegressionTest` mencakup back-to-back, overlap, containment, dan update parsial terbalik.

### [L-05] Analyzer Flutter belum zero-warning

- Bukti: `_countdownValue` unused di `enrollment_page.dart:48`.
- Task: [X] **L-05 Bersihkan analyzer dan jadikan warning sebagai CI failure.**
- Status 26 Juli 2026: `flutter analyze` bersih (No issues found). `camera_platform_interface` ditambahkan sebagai dev_dependency terpin (`^2.13.0`) karena dipakai langsung oleh camera converter test. Sisa terbuka: menjadikan analyzer warning sebagai CI gate.
- Status 9 Agustus 2026: selesai. Workflow baru `.github/workflows/frontend-ci.yml` berjalan pada setiap `push`/`pull_request` dan menjalankan `flutter analyze --fatal-warnings --fatal-infos` lalu `flutter test`, sehingga warning maupun info menjadi CI failure — bukan lagi hanya saat release manual. Step analyze pada `android-release.yml` juga dinaikkan ke `--fatal-warnings --fatal-infos` agar konsisten. Diverifikasi lokal: `flutter analyze --fatal-warnings --fatal-infos` menghasilkan "No issues found".

### [L-06] Test Flutter memberi false confidence

- Bukti: `attendance_logic_test.dart` dan `face_recognition_test.dart` mengulang rumus/helper lokal, bukan menguji service production; tidak ada integration test auth/queue/API/device.
- Dampak: 59 test dapat tetap hijau saat flow production rusak.
- Task: [X] **L-06 Ganti test duplikat dengan unit/contract/integration test terhadap kode production.**
- Status 9 Agustus 2026: selesai untuk dua test duplikat yang disebut bukti. `face_recognition_test.dart` ditulis ulang untuk memanggil kode production: `FaceRecognitionService.calculateEuclideanDistance`, comparator match via `EnrollmentIdentityContinuity.matches` (termasuk boundary `distance == threshold` yang akan gagal bila comparator kembali ke `<`), dan `LivenessDetectionService.getRandomChallenge`/`progress`. `attendance_logic_test.dart` ditulis ulang untuk menguji `Formatters` production (`formatDuration`, `formatAlphaHours`, `formatDistance`, `formatPercentage`, `getStatusLabel`, `getSpLabel`) — perhitungan status/alpha/SP sendiri dilakukan server dan mobile hanya menampilkan, sehingga rumus lokal lama tidak menguji apa pun. Helper `_euclideanDistance`, `_getSpStatus`, `_checkSmile`, dan aritmetika `DateTime` lokal dihapus.
- Verifikasi: full Flutter suite lulus (144 test), `flutter analyze --fatal-warnings --fatal-infos` bersih. Sisa terbuka (di luar bukti): integration test end-to-end auth/queue/API/device yang membutuhkan emulator/perangkat fisik.

### [L-07] Aksesibilitas web belum memenuhi pola dasar WCAG

- Bukti: `Modal.vue` tanpa dialog semantics/focus trap; `DataTable.vue` sorting pada `<th>` mouse-only; banyak icon button tanpa accessible name; chart tanpa data alternatif.
- Task: [X] **L-07 Perbaiki modal, sorting, menu, icon button, dan chart sesuai WAI-ARIA/WCAG.**
- Status 26 Juli 2026: sebagian besar selesai. `Modal.vue` memakai `role="dialog"`, `aria-modal`, `aria-labelledby`, focus trap Tab/Shift+Tab, autofocus, pengembalian fokus, overlay `aria-hidden`, dan close button beraccessible name. `DataTable.vue` memindahkan sorting ke `<button>` (keyboard-operable) dengan `scope="col"` dan `aria-sort`, serta pagination memakai `<nav aria-label>`, `aria-label` per tombol, dan `aria-current="page"`. Seluruh icon button (audit otomatis: 0 tersisa dari 17) pada Prodi, MataKuliah, TahunAjaran, Semester, Users, Jadwal, Geofence, DataTable, AppLayout, dan FlashToast kini memiliki `aria-label` kontekstual dengan ikon `aria-hidden`. `FlashToast` memakai `role="status"`/`aria-live="polite"`. Chart tren dashboard memiliki `role="img"` beraccessible name plus tabel `.sr-only` sebagai alternatif data yang terverifikasi ter-generate di CSS build.
- Status 9 Agustus 2026: selesai untuk pola statis. Menu dropdown akun di `AppLayout.vue` kini memakai pola WAI-ARIA menu button: trigger memiliki `aria-label` (nama akun), `aria-haspopup="menu"`, `aria-expanded`, dan `aria-controls`; panel memakai `role="menu"` + `aria-labelledby`, item memakai `role="menuitem"`, seluruh ikon dekoratif `aria-hidden`, dan Escape menutup menu. Lonceng notifikasi memakai `aria-label` dinamis yang menyertakan jumlah belum dibaca dan ikon/badge `aria-hidden`. `npm run build` lulus. Sisa terbuka (bukan pola statis, butuh browser runtime): scan axe/Lighthouse otomatis pada halaman jadi.

### [L-08] Dokumentasi saling bertentangan dan sebagian klaim selesai belum terbukti

- Contoh: 7 vs 8 role; Vue SPA vs Inertia; Phase 12 selesai vs E2E belum; Android min API 21 vs 29; alpha pending penuh vs selisih; comparator `<` vs `<=`; GPS accuracy 20 vs 50; response/check-in/offline type berbeda.
- Dampak: implementasi dan pengujian memakai sumber kebenaran berbeda.
- Task: [X] **L-08 Tetapkan source-of-truth dan sinkronkan PRD, API contract, schema, serta status task.**
- Status 26 Juli 2026: parsial. Hierarki sumber kebenaran sudah ditetapkan di `README.md` dokumentasi. Ambiguitas jumlah role ditutup: `CURRENT-ARCHITECTURE.md` sekarang memuat tabel role canonical 8 role sesuai `RoleSeeder`, dengan enam role pertama sebagai pengguna dashboard. Klaim "Vue SPA" juga sudah dikoreksi menjadi Inertia pada dokumen current.
- Status 9 Agustus 2026: selesai untuk inkonsistensi kode yang terukur. Comparator match wajah disatukan ke `<=` di seluruh lapisan: mobile `FaceRecognitionService.verifyFace*` dan `EnrollmentIdentityContinuity.matches` diubah dari `<` menjadi `<=`, selaras dengan backend (`face_distance > threshold` => tolak) dan analisis FAR/FRR yang sudah `<=`. Angka GPS accuracy disatukan ke baseline 20 m: `AttendancePage.maxGpsAccuracy` sebelumnya 50 kini merujuk `AppConstants.gpsAccuracyMinimum` (20) sehingga cocok dengan default backend `gps_accuracy_minimum` dan seeder; server per-prodi tetap menjadi sumber kebenaran. Ambang SP mobile (`AppConstants` 16/32/38/46) diverifikasi cocok dengan default `prodi_settings`. Sisa terbuka: detail endpoint pada PRD-03/PRD-04 lama (dokumen historis).

### [L-09] Hygiene repository/deployment belum memadai

- Bukti: root bukan Git repository; workspace memuat `.env`, dependency/build/IDE artifacts; tidak ditemukan pipeline CI/deployment manifest; secret aktif ada di local `.env` walau di-ignore.
- Dampak: history/secret exposure tidak dapat diaudit dan deployment tidak reproducible.
- Task: [ ] **L-09 Inisialisasi/benahi repository, CI, secret management, artifact ignore, dan deployment runbook.**

## Rekomendasi Validitas Penelitian

Temuan berikut harus diselesaikan sebelum angka penelitian dipakai dalam laporan akhir:

1. ~~Analisis geofence harus menghitung `checkin_success` sebagai keberhasilan; implementasi saat ini cenderung hanya menganggap `geofence_valid` sebagai sukses.~~ (Selesai R-01, 9 Agustus 2026: `geofenceData()` memakai `checkin_success`/`checkin_failed`.)
2. Uji simultan R-07 harus mengukur HTTP response time eksternal. `inference_time_ms` bukan response latency.
3. Failure dan timeout harus ikut tercatat; payload `success=true` hardcoded tidak dapat menjadi sumber kebenaran.
4. FAR/FRR harus dipisahkan per prodi/threshold atau menggunakan dataset dan threshold yang konsisten.
5. Gunakan orang hidup berbeda untuk impostor FAR. Foto/video replay adalah dataset anti-spoofing/PAD yang terpisah.
6. ~~Tetapkan comparator tunggal (`<` atau `<=`) untuk mobile, backend, analisis, dan PRD.~~ (Selesai L-08/R-04, 9 Agustus 2026: comparator match disatukan ke `<=` di mobile, backend, dan analisis.)
7. Kumpulkan minimum 30 genuine dan 30 impostor, idealnya 50 masing-masing, serta simpan artefak mentah yang dapat diaudit.
8. Jalankan concurrent check-in 20/30/40 dengan target NFR P95 <= 2 detik dan catat success/failure/timeout.
9. Jangan klaim perlindungan terhadap video replay/deepfake sebelum diuji; liveness saat ini terutama challenge landmark, bukan PAD penuh.

Checklist:

- [x] **R-01 Perbaiki perhitungan analisis geofence.** (9 Agustus 2026) `Web/AnalysisController::geofenceData` menghitung `success`/`success_rate` dari `checkin_success` vs `checkin_failed`, bukan `geofence_valid`; distribusi jarak tetap dari log geofence (satu-satunya sumber `distance_to_geofence`). Regression `GeofenceAnalysisMetricTest` membuktikan 4 `geofence_valid` + 1 `checkin_success`/3 `checkin_failed` menghasilkan success_rate 25% (bukan 100%).
- [ ] **R-02 Bangun load-test runner yang mengukur response latency/failure/timeout secara eksternal.** (terbuka: butuh runner eksternal/infra)
- [ ] **R-03 Tetapkan metodologi FAR/FRR dan anti-spoofing yang terpisah.** (terbuka: metodologi + dataset penelitian)
- [ ] **R-04 Sinkronkan threshold/comparator dan filter dataset per prodi.** (parsial 9 Agustus 2026: comparator disatukan ke `<=` di mobile/backend/analisis — lihat L-08; filter dataset per prodi masih terbuka)
- [ ] **R-05 Jalankan pengambilan data lapangan serta arsipkan raw evidence.** (terbuka: pengambilan data lapangan)

## Urutan Remediasi yang Disarankan

### Milestone 0: Containment

- [x] Nonaktifkan response reset token dan revoke credential setelah reset.
- [ ] Selesaikan scope policy yang masih terbuka pada H-21.
- [x] Wajibkan permit untuk online/offline attendance.
- [x] Release Android menggunakan HTTPS dan release signing fail-closed.

### Milestone 1: Restore Build dan Test Baseline

- [x] Backend bootstrap dan test pada PHP 8.3.30.
- [x] Jalankan migration fresh dan upgrade terhadap database engine production (MySQL 8.0.30: `migrate`, `migrate:rollback`, dan `migrate:fresh` lulus per 26 Juli 2026).
- [x] Jalankan seluruh backend test: 92 test/239 assertion lulus.
- [x] Backend CI menjalankan Composer validation/platform/audit, PHP tests, dan Vite build; Android workflow menjalankan Flutter analyze/test.

### Milestone 2: Authorization dan Account Security

- [ ] C-01, C-02, C-03, C-07, H-19, H-20, dan M-21 selesai; lanjutkan H-21.
- [ ] Buat matriks role-permission-prodi sebagai sumber kebenaran.
- [ ] Tambahkan negative tests untuk setiap endpoint sensitif.

### Milestone 3: Attendance Integrity

- [ ] C-05/C-06/H-11/H-12/H-14/H-15 selesai; lanjutkan C-04, H-13, H-16, dan M-01 sampai M-12.
- [ ] Satukan logika web/API/scheduler dalam service domain transaksional.
- [ ] Tambahkan online/offline check-in/check-out contract dan concurrency tests.

### Milestone 4: Privacy dan Data Integrity

- [ ] H-03/H-05 sampai H-10 selesai; M-19 dan M-20 selesai; lanjutkan H-04, M-13, dan M-14.
- [ ] Tetapkan retention, encryption, consent, access audit, backup, dan deletion policy biometrik.
- [x] Uji larangan cascade delete historis (M-19: FK RESTRICT + `HistoricalMasterLifecycleTest`); backup/restore policy biometrik masih terbuka.

### Milestone 5: Performance, UX, dan Release

- [x] Selesaikan M-15 sampai M-22 (M-16, M-17, M-18, M-20, M-22 ditutup 26 Juli 2026; M-19 dan M-21 ditutup 9 Agustus 2026).
- [ ] Selesaikan seluruh Low findings (L-01/L-03/L-04/L-05/L-06/L-07/L-08 selesai; L-02 dan L-09 terbuka — keduanya butuh Firebase project / git+CI infra eksternal).
- [ ] Jalankan browser/device E2E untuk enam role dan mobile.
- [ ] Lakukan load test dan penelitian hanya setelah flow production stabil.

## Definition of Done Global

Project baru dapat dinyatakan release candidate bila seluruh kondisi berikut terpenuhi:

- [ ] Tidak ada Critical atau High yang terbuka.
- [ ] Backend berhasil bootstrap, migrate, queue, schedule, dan test pada platform production yang terdokumentasi.
- [ ] Authorization matrix memiliki negative tests lintas role dan lintas prodi.
- [ ] Reset password tidak mengekspos token dan seluruh auth endpoint memiliki rate limit.
- [ ] Online/offline check-in dan checkout memiliki bukti yang tidak dapat dipalsukan hanya dengan mengubah payload.
- [ ] Offline queue terisolasi per akun, encrypted, crash-safe, dan idempotent.
- [ ] Schema/model/controller konsisten dan migration diuji fresh serta upgrade.
- [ ] Data biometrik encrypted, private, tidak masuk serializer/log, dan memiliki retention policy.
- [ ] APK release memakai HTTPS, release signing, secure storage, serta lulus test perangkat fisik.
- [~] Web build, Flutter analyze/test, PHP test, dependency audit, dan security checks menjadi CI gate. (Backend CI + Frontend CI aktif pada push/PR dengan `flutter analyze --fatal-warnings --fatal-infos`; sisa: repo/secret management L-09.)
- [ ] Query report/export lulus benchmark dataset realistis dan P95 memenuhi NFR.
- [ ] Dokumentasi PRD/API/schema/status task sama dengan perilaku aktual.
- [ ] Data penelitian dikumpulkan ulang dari pipeline yang metriknya valid dan dapat diaudit.

## Batasan Audit

- Audit ini tidak mengeksploitasi sistem production dan tidak melakukan destructive test.
- Backend runtime, migration, dan tests telah diverifikasi pada PHP 8.3.30.
- Tidak dilakukan test kamera/GPS/fake-location pada perangkat fisik dalam audit ini.
- Tidak dilakukan browser runtime/accessibility scan; temuan web UI berasal dari source review dan build.
- Status secret di history tidak dapat diverifikasi karena root bukan repository Git.
- `npm audit` hanya mendeteksi advisory yang diketahui dan bukan bukti supply-chain sepenuhnya aman.

Dokumen ini menggantikan klaim umum “seluruh bug sudah selesai” sebagai backlog audit terkini. Setiap task sebaiknya diselesaikan dalam perubahan kecil, disertai regression test, lalu ditandai selesai hanya setelah acceptance criteria dan verification evidence tersedia.
