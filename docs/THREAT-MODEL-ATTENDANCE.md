# Threat Model Attendance

**Status:** maintained
**Pembaruan:** 11 Agustus 2026
**Konteks:** dokumen ini memenuhi bagian "buat threat model" pada acceptance C-04 di [temuan.md](temuan.md). Dokumen ini **tidak menutup C-04/H-04**. Tujuannya adalah menyatakan apa yang diverifikasi server, apa yang masih merupakan klaim client, dan mengapa production fail-closed.

> **Keputusan scope 12 Agustus 2026.** C-04/H-04 (trusted verifier server-side) **di luar scope penelitian**. Rancangan verifier ditinjau di [ADR-001](ADR-001-trusted-biometric-verifier.md) dan **ditolak**; verifier tidak dibangun. Threat model ini tetap valid sebagai pernyataan batas klaim: production fail-closed permanen (untuk penelitian) dan data legacy adalah **client-attested**. Bagian "Arah Remediasi C-04" di bawah kini menjadi **panduan bila proyek dinaikkan ke produksi**, bukan backlog aktif.

## Aset yang Dilindungi

| Aset | Dampak bila dipalsukan |
|---|---|
| Record kehadiran | Nilai dan status akademik salah |
| Akumulasi alpha | SP/DO diterbitkan atau dihindari secara keliru |
| Template biometrik | Kebocoran data biometrik permanen |
| Dokumen SP | Pemalsuan surat akademik resmi |

## Aktor Ancaman

| Aktor | Kemampuan diasumsikan |
|---|---|
| A1. Mahasiswa dengan sesi sah | Login valid, dapat memodifikasi APK, hook runtime, atau memanggil API langsung |
| A2. Mahasiswa tanpa sesi target | Tidak punya kredensial korban |
| A3. Penyadap jaringan | Berada di LAN/Wi-Fi yang sama |
| A4. Perangkat hilang/rooted | Akses fisik ke penyimpanan aplikasi |
| A5. Operator/DB compromise | Akses baca database |

## Kontrol yang Benar-benar Ditegakkan Server

Diverifikasi pada `AttendancePermitService`, `AttendancePolicyService`, `Api/Mahasiswa/AttendanceController`, `OfflineSyncController`, dan `RequireTrustedBiometricEvidence`.

> **Containment production:** selama challenge-bound capture verifier belum tersedia,
> endpoint permit, check-in/out, offline sync, enrollment/re-enrollment, reference
> embedding, dan approval biometrik ditolak `503 TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED`.
> Switch `BIOMETRIC_ALLOW_CLIENT_CLAIMS=true` hanya bekerja di environment
> non-production untuk compatibility test; production selalu fail-closed.

| Kontrol | Mekanisme |
|---|---|
| Preauthorization | Absensi wajib memakai permit yang diterbitkan server lebih dulu |
| Anti-replay | Permit sekali pakai, dikonsumsi atomik, hash tersimpan |
| Binding | Permit terikat user, jadwal, mata kuliah, action, attendance, tanggal occurrence, dan `client_uuid` |
| Waktu | Window berasal dari waktu server; capture online memakai waktu server, bukan timestamp client |
| Invariant akademik | Enrollment, pivot mata kuliah, prodi, jadwal, mata kuliah, geofence, semester, tahun ajaran, hari, dan rentang tanggal aktif |
| Identitas | `users.status=aktif`, embedding `approved`, token dicabut saat deaktivasi |
| Jarak geofence | Dihitung server dari koordinat yang dikirim, dibandingkan radius server |
| Status dan alpha | Diturunkan server dari waktu server dan setting prodi |
| Batas kualitas lokasi | Akurasi dan umur fix ditolak bila melewati policy prodi (baseline `gps_accuracy_minimum` 20 m) |
| Comparator match | Ambang `face_distance <= face_threshold` konsisten mobile/backend/analisis (L-08/R-04); server menegakkan penolakan |
| Transport | HTTPS wajib pada release; cleartext debug hanya loopback/emulator atau LAN privat terpercaya dengan akun/data uji |
| Abuse rate | Permit dan capture dibatasi `throttle:attendance` 10/menit per user; group API terautentikasi dibatasi `throttle:api` 60/menit per user (M-23). Keying per user, bukan per IP |
| Scope pembaca | Endpoint analisis memakai scope aktor: role tingkat prodi tidak dapat membaca data prodi lain (M-24). Matriks lengkap di [ROLE-PERMISSION-MATRIX.md](ROLE-PERMISSION-MATRIX.md) |

> Catatan metrik penelitian: analisis success rate geofence dihitung dari `checkin_success` vs `checkin_failed`, bukan `geofence_valid` (R-01), dan filter `prodi_id` mempersempit dataset dengan atribusi prodi subjek (R-04). Keduanya memperbaiki *validitas laporan*, bukan menutup residual C-04 di bawah.

Konsekuensi: A2, A3, dan sebagian besar A4 sudah tertutup. Permit menutup absensi tanpa preauthorization, salah binding, dan replay. A1 dikontain di production dengan menonaktifkan mutation biometrik secara permanen untuk konteks penelitian (trusted verifier di luar scope — [ADR-001](ADR-001-trusted-biometric-verifier.md) ditolak); ini mencegah pemalsuan masuk ke data resmi tetapi membuat fitur attendance/enrollment production tidak tersedia.

## Residual: Klaim Client yang Belum Dapat Diverifikasi

Ini inti C-04 yang masih terbuka pada implementasi legacy dan alasan fitur production dikontain.

| Nilai | Sifat sekarang | Mengapa belum terverifikasi |
|---|---|---|
| `latitude`, `longitude` | Angka dari client | Server menghitung jarak, tetapi input koordinatnya sendiri tidak dapat dibuktikan berasal dari GPS asli |
| `mock_location_detected` | Boolean dari client | Deteksi berjalan di perangkat yang dikendalikan penyerang |
| `liveness_passed` | Boolean dari client | Tidak ada capture artifact yang diverifikasi server |
| `face_distance` | Angka dari client | Matching berjalan di perangkat; server tidak mengulang matching |
| `gps_accuracy`, `location_age_ms` | Angka dari client | Batas ditegakkan server, tetapi nilainya self-reported |

### Skenario Serangan yang Masih Mungkin

**S-1. Absensi tanpa hadir secara fisik (A1).**
Mahasiswa dengan sesi sah meminta permit yang benar, lalu mengirim koordinat pusat geofence, `liveness_passed=true`, `mock_location_detected=false`, dan `face_distance=0`. Seluruh kontrol server lolos karena semuanya konsisten dengan permit.

Prasyarat: sesi sah dan kemampuan memodifikasi client atau memanggil API langsung.
Status legacy/test: **tidak termitigasi**. Permit hanya memastikan permintaan terotorisasi, bukan bahwa evidence-nya asli. Status production: **dikontain** karena endpoint terkait ditolak sebelum mutation.

**S-2. Proxy attendance.**
Mahasiswa A meminjamkan kredensial ke B yang berada di lokasi. Kontrol biometrik seharusnya menutup ini, tetapi karena hasil matching berasal dari client, B dapat mengirim `face_distance` rendah tanpa matching sungguhan.
Status legacy/test: **tidak termitigasi**. Status production: **dikontain** melalui gate yang sama.

**S-3. Presentation attack.**
Foto atau video korban dipakai untuk melewati liveness. Liveness saat ini berbasis challenge landmark, bukan PAD penuh.
Status legacy/test: **tidak termitigasi dan belum diuji**. Production tidak boleh mengaktifkan flow ini; jangan mengklaim perlindungan terhadap video replay atau deepfake.

## Batas Klaim yang Boleh Dibuat

Boleh diklaim:

- Absensi tidak dapat dibuat tanpa preauthorization server.
- Replay, salah user, salah jadwal, salah action, dan salah UUID ditolak.
- Waktu, status, alpha, dan jarak geofence dihitung server.

Tidak boleh diklaim:

- Bahwa mahasiswa benar-benar berada di lokasi.
- Bahwa wajah yang diverifikasi adalah wajah asli pemilik akun.
- Bahwa sistem tahan terhadap presentation attack.

Untuk data compatibility/non-production, perlakukan hasil sebagai **client-attested**, bukan bukti forensik. Production tidak boleh menghasilkan attendance/enrollment dari flow legacy. Setelah verifier tersedia pun, sediakan jalur sanggah manual untuk keputusan akademik berkonsekuensi seperti SP/DO.

## Arah Remediasi C-04 (opsional — hanya untuk kenaikan ke produksi)

> **Bukan backlog aktif.** Verifier di luar scope penelitian ([ADR-001](ADR-001-trusted-biometric-verifier.md) ditolak).
> Daftar berikut adalah rujukan bila suatu saat proyek dinaikkan ke tingkat produksi.

Berurutan dari dampak tertinggi:

1. **Challenge-bound capture artifact.** Client mengirim artefak capture yang terikat challenge acak dari permit; server atau trusted verifier melakukan matching dan liveness, bukan client. Ini menghapus S-2 dan memperkecil S-1.
2. **Platform attestation.** Play Integrity dan App Attest sebagai sinyal tambahan untuk mendeteksi client termodifikasi. Bukan pengganti butir 1.
3. **Hardware-backed device signature.** Key di Keystore/Secure Enclave menandatangani payload sehingga payload dari HTTP client biasa dapat ditolak.
4. **Korelasi lintas sinyal.** Deteksi kejanggalan seperti perpindahan lokasi mustahil, akurasi GPS yang terlalu sempurna, dan `face_distance` konstan.
5. **PAD terukur.** Uji anti-spoofing terpisah dengan dataset yang jelas sebelum mengklaim ketahanan replay.

Jangan menandai C-04 selesai hanya karena permit, nonce, atau attestation ditambahkan. Syarat minimum penutupan adalah butir 1 diimplementasikan dan diuji, dengan bukti bahwa payload arbitrer tanpa artefak capture sah ditolak.

## Kontrol Privasi yang Wajib Menyertai Remediasi

Bila capture dikirim ke server:

- Tetapkan retention minimum dan penghapusan otomatis.
- Enkripsi at rest dengan key terpisah, seperti embedding saat ini.
- Batasi akses melalui policy dan audit setiap pembacaan.
- Nyatakan consent dan dasar hukum pemrosesan biometrik.
