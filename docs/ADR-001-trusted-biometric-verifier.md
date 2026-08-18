# ADR-001 — Trusted Biometric Verifier (C-04 / H-04)

> **Status: DITOLAK / TIDAK DILANJUTKAN (12 Agustus 2026).** Tetap disimpan sebagai
> catatan keputusan; **bukan** rencana aktif dan **bukan** kontrak runtime.
>
> **Alasan penolakan.** Trusted verifier server-side (server mengulang face matching +
> liveness dari artefak capture) adalah kontrol tingkat produksi terhadap penyerang aktif
> yang memodifikasi APK. Untuk **konteks penelitian/skripsi** ini, kontrol tersebut
> **di luar scope**: tujuan penelitian — membuktikan absensi berbasis face recognition
> (MobileFaceNet on-device) + geofencing — sudah terpenuhi dengan matching di perangkat.
> Uji kelayakan runtime juga menunjukkan biaya integrasi tinggi tanpa nilai untuk
> penelitian (tidak ada runtime TFLite Node yang matang di Windows tanpa build tools;
> pendekatan alternatif menuntut Python/ONNX/konversi model yang eksplisit tidak diinginkan).
>
> **Konsekuensi.** C-04 dan H-04 **tidak diimplementasikan** dan diterima sebagai
> **residual risk penelitian yang didokumentasikan** (lihat [temuan.md](temuan.md) C-04/H-04
> dan [THREAT-MODEL-ATTENDANCE.md](THREAT-MODEL-ATTENDANCE.md) §"Batas Klaim yang Boleh Dibuat").
> Production tetap fail-closed via `RequireTrustedBiometricEvidence`; data flow legacy
> hanya untuk penelitian non-production dan diperlakukan **client-attested**, bukan bukti forensik.
> Jangan mengklaim ketahanan terhadap proxy attendance atau presentation attack.
>
> Dokumen di bawah ini adalah rancangan yang **tidak jadi dijalankan**, dipertahankan bila
> suatu saat proyek dinaikkan ke tingkat produksi.
>
> **Author:** (sesi implementasi) — **Tanggal:** 12 Agustus 2026.

## 1. Konteks

C-04 dan H-04 masih terbuka. Kondisi sekarang (terverifikasi di kode):

- Server hanya **menerima scalar dari client**: `face_distance`, `liveness_passed`,
  `mock_location_detected`, `latitude/longitude` (`AttendanceController.php:35-38`,
  `OfflineSyncController.php:51-53`).
- Server **tidak pernah mengulang matching wajah maupun liveness**. Matching berjalan
  di HP (`FaceRecognitionService`), server hanya membandingkan angka yang dikirim
  terhadap `face_threshold`.
- Fitur production **dikontain** oleh middleware `RequireTrustedBiometricEvidence`
  yang mengembalikan `503 TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED` untuk semua endpoint
  biometrik. Artinya absensi/enrollment production **tidak berfungsi** sampai verifier ada.

Threat model ([THREAT-MODEL-ATTENDANCE.md](THREAT-MODEL-ATTENDANCE.md) §"Arah Remediasi
C-04") menetapkan **syarat minimum penutupan**: challenge-bound capture artifact yang
**diverifikasi server** (matching + liveness di server, bukan client), dengan bukti
bahwa payload arbitrer tanpa artefak capture sah ditolak.

## 2. Keputusan

Membangun **trusted verifier server-side penuh**:

1. **Challenge-bound capture.** Mobile tidak lagi mengirim `face_distance`/`liveness_passed`
   sebagai kebenaran. Mobile mengirim **artefak capture** (frame wajah) yang terikat ke
   `liveness_challenge` acak dari permit + `client_uuid` + `permit_token`.
2. **Server-side matching.** Backend Laravel meneruskan artefak ke **sidecar verifier
   Node.js** yang memuat **`mobile_face_net.tflite` yang sama persis dengan mobile**
   (tidak ada Python, tidak ada konversi ONNX). Verifier menghitung embedding,
   `face_distance` terhadap embedding enrolled, dan skor liveness. **Backend yang
   memutuskan match/liveness**, bukan client.

   > **Keputusan revisi (12 Agustus 2026):** arsitektur diubah dari microservice Python +
   > ONNX menjadi **sidecar Node.js + TFLite**. Alasan: (a) tidak menambah runtime Python;
   > (b) verifier memuat file model **identik** dengan mobile sehingga tidak ada risiko
   > paritas embedding maupun kalibrasi ulang threshold; (c) Node.js sudah dipakai project
   > (Vite/Inertia).
3. **Attestation & signature sebagai sinyal tambahan** (bukan pengganti): Play Integrity
   (Android) + hardware-backed signature dari Android Keystore atas payload permit.
4. **Privasi (MS-02).** Artefak capture disimpan terenkripsi (pola `BiometricEncryptionService`
   yang sudah ada), retention pendek + auto-delete, akses ber-audit, consent tercatat.

Keputusan ini menutup S-2 (proxy attendance) dan memperkecil S-1, sesuai butir 1 threat model.

## 3. Arsitektur

```
[Mobile] --(1) POST /attendance/permits--> [Laravel]
         <--   permit_token + liveness_challenge (acak)  --

[Mobile] menangkap frame terikat challenge, lampirkan attestation + signature

[Mobile] --(2) POST /attendance/verify (multipart: foto + permit_token + challenge + client_uuid + attestation)-->
                                              [Laravel]
                                                 | validasi permit (binding, window, single-use)  -- reuse AttendancePermitService
                                                 | simpan capture terenkripsi (retention MS-02)
                                                 | verifikasi attestation + signature
                                                 v
                                        [Verifier Sidecar (Node.js + TFLite)]
                                                 | mobile_face_net.tflite (identik mobile) -> embedding
                                                 | face_distance vs embedding enrolled (dari Laravel)
                                                 | skor liveness (PAD ringan / challenge motion)
                                                 v
                                        {match: bool, distance: float, liveness: float}
                                                 |
                                              [Laravel] memutuskan hadir/tolak dari hasil SERVER
                                                 | consume permit, tulis attendance + audit
                                                 v
                                        <-- hasil check-in/out -->
```

Poin penting:

- **Verifier tidak punya DB sendiri.** Stateless. Embedding enrolled dikirim Laravel
  (sudah didekripsi in-memory) bersama request, atau verifier menerima dua gambar dan
  mengembalikan distance. **Rekomendasi:** verifier menerima `probe_image` + `reference_embedding`
  (array 192) dan mengembalikan distance — reference tidak pernah keluar sebagai gambar.
- **mTLS / shared secret** antara Laravel dan verifier; verifier tidak terekspos publik.
- Bila verifier down → **fail-closed** (`503`), konsisten dengan containment sekarang.

## 4. Kontrak API baru

### 4.1 `POST /api/mahasiswa/attendance/verify` (menggantikan payload scalar check-in/out)

Request `multipart/form-data`:

| Field | Tipe | Catatan |
|---|---|---|
| `permit_token` | string(64) | dari permit |
| `client_uuid` | uuid | idempotency, terikat permit |
| `liveness_challenge` | string | harus == permit |
| `action` | enum `check_in`/`check_out` | |
| `jadwal_id` | int | |
| `attendance_id` | int? | wajib untuk `check_out` |
| `foto` | file (jpeg/png, <=1.5MB) | **artefak capture** yang diverifikasi server |
| `latitude`,`longitude`,`gps_accuracy`,`location_age_ms` | numeric | tetap dikirim; geofence server-side (tak berubah) |
| `mock_location_detected` | bool | tetap dilaporkan, tapi bukan satu-satunya kontrol |
| `attestation_token` | string? | Play Integrity |
| `device_signature` | string? | signature Keystore atas `permit_token\|client_uuid` |

**Yang DIHAPUS dari kontrak client-authoritative:** `face_distance`, `liveness_passed`.
Kedua nilai ini sekarang **dihasilkan server** dari verifier. Field lama ditolak/diabaikan
(bukan dipercaya).

Response sukses: sama seperti check-in/out sekarang (agar mobile UI minim berubah).
Response gagal match: `422 FACE_NOT_MATCH` (anonim, tanpa distance). Verifier down: `503`.

### 4.2 `POST /api/mahasiswa/enrollment` (H-04)

Sama: `liveness_passed` client dihapus; enrollment mengirim `foto` + challenge; verifier
menghitung embedding **dan** liveness di server; embedding disimpan hanya bila liveness lolos.
Approval Kaprodi tetap `pending -> approved` (lifecycle sudah ada).

### 4.3 Verifier internal `POST /verify` (Laravel -> Node sidecar, tidak publik)

```json
Request:  { "probe_jpeg_b64": "...", "reference_embedding": [192 floats], "threshold": 1.0, "mode": "attendance|enrollment" }
Response: { "distance": 0.31, "match": true, "liveness_score": 0.94, "embedding": [192 floats] }
```

`embedding` hanya dikembalikan untuk `mode=enrollment`.

## 5. Skema data baru

### 5.1 Tabel `attendance_captures` (retention pendek, MS-02)

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | pk | |
| `user_id` | fk restrict | ikut pola M-19 |
| `attendance_id` | fk nullable | terisi setelah sukses |
| `permit_id` | fk | binding |
| `ciphertext_path` | string | file capture terenkripsi di disk `biometric` privat |
| `key_id` | string | keyring `BiometricEncryptionService` |
| `verifier_distance` | decimal(10,6) | hasil SERVER |
| `verifier_liveness` | decimal(5,4) | hasil SERVER |
| `attestation_verdict` | string nullable | Play Integrity |
| `created_at` | ts | |
| `purge_after` | ts | auto-delete (job scheduler) |

Retention default: **24 jam** lalu file dihapus (embedding sudah cukup; capture mentah
hanya untuk sanggah/audit jangka pendek). Angka final ditetapkan di policy MS-02.

### 5.2 Tambah kolom `attendance_permits`

- `capture_verified_at` timestamp nullable — mencegah verifikasi ganda per permit.

## 6. Rencana bertahap (per fase, tiap fase punya test + acceptance)

> Aturan project: `[X]` hanya setelah acceptance terbukti. Fase device/attestation
> nyata tetap `[ ]` sampai ada evidence perangkat fisik.

### Fase 0 — ADR ini (review)
- [ ] Review & setujui arah, retention, dan kontrak API.

### Fase 1 — Verifier sidecar (Node.js + TFLite)
- [ ] `verifier/` Node.js (HTTP server), muat `mobile_face_net.tflite` yang sama, endpoint `/verify` + `/health`.
- [ ] Preprocessing identik mobile (resize 112x112, normalisasi) supaya **embedding paritas** dengan mobile.
- [ ] Shared-secret header untuk panggilan internal. Test: paritas embedding (self-distance ~0), distance simetris, input invalid ditolak, secret salah ditolak.

### Fase 2 — Backend integrasi (Laravel)
- [ ] `VerifierClient` (HTTP ke service, fail-closed bila down).
- [ ] Migration `attendance_captures` + kolom permit; `CaptureStorageService` (reuse enkripsi biometrik).
- [ ] Endpoint `/attendance/verify` + enrollment baru: validasi permit (reuse `AttendancePermitService`), simpan capture, panggil verifier, **putuskan dari hasil server**, consume permit, tulis attendance/audit.
- [ ] Ubah `RequireTrustedBiometricEvidence`: lolos hanya bila request punya capture + hasil verifier valid (bukan lagi hard-503). Legacy scalar path dihapus/ditolak.
- [ ] Test: payload tanpa foto ditolak; `face_distance` client diabaikan; verifier "not match" -> 422; verifier down -> 503; replay/permit salah -> 403/409; enrollment simpan embedding hanya jika liveness server lolos.

### Fase 3 — Mobile (Flutter)
- [ ] Kirim `foto` capture (bukan scalar) di check-in/out & enrollment; hapus pengiriman `face_distance`/`liveness_passed` sebagai kebenaran.
- [ ] Integrasi Play Integrity + signature Keystore atas `permit_token|client_uuid`.
- [ ] Test unit/contract: payload multipart benar; challenge dari permit dipakai; analyze bersih.

### Fase 4 — Privasi & retention (MS-02)
- [ ] `docs/BIOMETRIC-POLICY.md`: consent, dasar hukum, retention, backup, deletion.
- [ ] Job scheduler purge capture > `purge_after`; audit setiap akses capture.
- [ ] Test: capture terhapus setelah retention; akses capture ber-audit.

### Fase 5 — Deploy & evidence
- [ ] `deploy/`: manifest verifier (systemd/container), secret, healthcheck; `DEPLOYMENT.md` diperbarui.
- [ ] `.env.example`: `VERIFIER_URL`, `VERIFIER_SHARED_SECRET`, retention.
- [ ] Sinkron dokumen current + `temuan.md`.
- [ ] **[ ] Acceptance device fisik** (Android): matching nyata, attestation nyata — menahan `[X]` C-04/H-04 sampai ada bukti perangkat.

## 7. Acceptance penutupan C-04/H-04

C-04 boleh `[X]` bila **semua** terbukti (test + evidence):

1. Check-in/out tanpa `foto` capture sah **ditolak** (tak bisa dipalsukan dengan scalar).
2. `face_distance`/`liveness_passed` yang dikirim client **tidak memengaruhi** keputusan.
3. Keputusan match/liveness berasal dari verifier server; verifier "not match" menolak.
4. Replay/permit salah/challenge salah ditolak (sudah ada, tetap dijaga).
5. Verifier down -> fail-closed 503.
6. Evidence perangkat fisik: capture nyata match, attestation nyata terverifikasi.

H-04: enrollment production menyimpan embedding **hanya** setelah liveness server lolos;
tidak ada auto-approve; approval manual tetap ada.

## 8. Risiko & trade-off

| Risiko | Mitigasi |
|---|---|
| Komponen deploy baru (Node sidecar) | systemd/container + healthcheck + fail-closed; verifier stateless |
| Paritas embedding mobile vs sidecar | Model **identik** (TFLite yang sama); uji paritas Fase 1 memastikan preprocessing cocok |
| Capture biometrik mentah di server = risiko privasi baru | Enkripsi at-rest, retention pendek + purge, audit, consent (MS-02) |
| Liveness server ringan bukan PAD penuh | Jangan klaim tahan presentation attack; PAD terukur tetap R-03 |
| Latency naik (upload gambar + inferensi) | Ukur di R-02; kompres frame; batas ukuran; timeout + retry idempotent |
| Attestation butuh Firebase/Play project | Attestation opt-in; matching server tetap kontrol utama walau attestation off |

## 9. Yang TIDAK berubah

- Permit binding, anti-replay, window server, invariant akademik, geofence server-side,
  scope authorization — semua tetap (sudah kuat). Verifier hanya mengganti lapisan
  "kebenaran wajah/liveness" dari client ke server.
