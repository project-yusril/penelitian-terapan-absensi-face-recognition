# PRD-08: NON-FUNCTIONAL REQUIREMENTS & SECURITY

> **Status:** target NFR, bukan klaim seluruh kontrol telah diverifikasi. Baseline
> runtime/deployment current ada di [DEPLOYMENT.md](DEPLOYMENT.md), kontrol dan
> residual risk ada di [SECURITY.md](SECURITY.md), acceptance ada di
> [temuan.md](temuan.md).

---

## 1. NON-FUNCTIONAL REQUIREMENTS

### 1.1 Performance

| Parameter | Target | Keterangan |
|-----------|--------|------------|
| API Response Time | < 500ms (95th percentile) | Untuk endpoint standar (CRUD) |
| API Response Time (concurrent) | < 2000ms | Saat 40 user bersamaan |
| Face Inference Time | < 300ms (mid-range device) | On-device MobileFaceNet |
| Face Inference Time | < 500ms (low-end device) | On-device MobileFaceNet |
| Total Attendance Process | < 15 detik | Dari buka app sampai selesai |
| Dashboard Load Time | < 3 detik | Initial page load |
| Database Query | < 100ms | Untuk query standar |
| File Upload | < 10 detik | Untuk surat izin (max 5MB) |

### 1.2 Scalability

| Parameter | Target |
|-----------|--------|
| Concurrent Users (Mobile) | 50+ mahasiswa bersamaan |
| Concurrent Users (Web) | 20+ admin/dosen bersamaan |
| Total Users | 500+ mahasiswa, 50+ dosen, 10+ admin |
| Data Retention | 5 tahun (arsip) |
| Database Size | Estimasi 10GB per tahun |

### 1.3 Availability

| Parameter | Target |
|-----------|--------|
| Uptime | 99% (selama jam operasional: 06:00-22:00) |
| Planned Downtime | Maks 2 jam/bulan (maintenance window: Minggu 00:00-02:00) |
| Recovery Time | < 1 jam (dari backup) |
| Backup Frequency | Daily (database), Weekly (full system) |

### 1.4 Reliability

| Parameter | Target |
|-----------|--------|
| Face Verification Accuracy | > 95% (genuine acceptance) |
| Geofence Validation Accuracy | > 98% |
| Data Integrity | 100% (no data loss) |
| Offline Sync Success | > 95% |

### 1.5 Usability

| Parameter | Target |
|-----------|--------|
| SUS Score | > 70 (acceptable) |
| Learning Curve (Mahasiswa) | < 5 menit untuk absensi pertama |
| Learning Curve (Admin) | < 30 menit untuk operasi dasar |
| Accessibility | WCAG 2.1 Level A (minimum) |
| Mobile OS Support | Android 10+ (API 29+) |
| Web Browser Support | Chrome 90+, Firefox 90+, Safari 15+, Edge 90+ |

### 1.6 Compatibility

| Parameter | Requirement |
|-----------|-------------|
| Android Version | Minimum Android 10 (API 29) |
| Device Category | Low-end (2GB RAM), Mid-range (4GB), High-end (8GB+) |
| Camera | Front camera minimum 5MP |
| GPS | Built-in GPS sensor |
| Screen Size | Minimum 5 inch |
| Internet | WiFi atau 4G (untuk sync) |

---

## 2. SECURITY REQUIREMENTS

### 2.1 Authentication & Authorization

| Requirement | Detail |
|-------------|--------|
| Token-based Auth | Laravel Sanctum (Bearer Token) |
| Token Expiry | 24 jam (mobile), 8 jam (web) |
| Password Policy | Min 8 karakter, huruf + angka |
| Password Hashing | bcrypt (cost factor 12) |
| Role-based Access | 8 role dengan permission granular |
| Session Management | Single active session per device |
| Brute Force Protection | Max 5 login attempts, lock 15 menit |

### 2.2 Data Security

| Requirement | Detail |
|-------------|--------|
| Data in Transit | HTTPS/TLS 1.3 |
| Data at Rest | Encrypted database (MySQL encryption) |
| Embedding Storage | JSON column (tidak bisa di-reverse ke foto) |
| File Upload | Validated (type, size), stored outside public |
| SQL Injection | Eloquent ORM (parameterized queries) |
| XSS Protection | Input sanitization, output encoding |
| CSRF Protection | Laravel CSRF token (web), Sanctum (API) |
| CORS | Whitelist specific origins |

### 2.3 Anti-Spoofing

| Threat | Mitigation |
|--------|-----------|
| Fake GPS / Mock Location | safe_device package detection |
| Foto wajah (print attack) | Liveness detection (challenge-response) |
| Video replay | Random challenge (tidak bisa diprediksi) |
| Video call | Challenge timeout (10 detik), texture detection |
| Masker | Landmark detection (tolak jika mulut tidak terdeteksi) |
| Kacamata hitam | Eye detection (tolak jika mata tidak terdeteksi) |
| Deepfake | Liveness challenge + embedding distance threshold |
| Titip absen | Face verification 1:1 (wajah harus cocok akun) |

### 2.4 Privacy & Data Storage

| Requirement | Detail |
|-------------|--------|
| Foto wajah saat absensi | TIDAK disimpan (frame dibuang dari memori setelah proses) |
| Foto enrollment | Disimpan 1x saat enrollment pertama (JPG, untuk biodata/identitas visual) |
| Embedding saat absensi | TIDAK disimpan (hanya diproses di memori HP, lalu dibuang) |
| Embedding referensi | Disimpan 1x saat enrollment (di database, untuk perbandingan) |
| Data yang dikirim saat absensi | Hanya angka: distance, threshold, match result, inference_time (BUKAN foto/embedding) |
| Lokasi | Hanya dicatat saat proses absensi |
| Data retention | Sesuai kebijakan institusi (5 tahun) |
| Data deletion | Soft delete (bisa di-restore) |
| Consent | User agreement saat enrollment |

### 2.5 Audit & Logging

| Requirement | Detail |
|-------------|--------|
| Audit Trail | Semua CRUD operation tercatat |
| Attendance Log | Setiap percobaan absensi tercatat (berhasil/gagal) |
| Login Log | Semua login attempt tercatat |
| Anomaly Detection | Flag jika gagal verifikasi > 5x |
| Log Retention | 1 tahun (operational), 5 tahun (audit) |

---

## 3. API RATE LIMITING

| Endpoint Group | Limit | Window |
|---------------|-------|--------|
| Auth (login) | 5 requests | per menit per IP |
| Attendance (check-in/out) | 10 requests | per menit per user |
| General API | 60 requests | per menit per user |
| File Upload | 5 requests | per menit per user |
| Export | 3 requests | per menit per user |

---

## 4. CAMERA & IMAGE PROCESSING REQUIREMENTS

### 4.1 Konfigurasi Kamera

| Parameter | Nilai | Keterangan |
|-----------|-------|------------|
| Resolusi Preview | `ResolutionPreset.high` (1280x720) | Balance kualitas visual + performa ML |
| Format Stream | YUV420 | Efisien untuk ML processing |
| Kamera | Front camera | Untuk face detection & verification |
| FPS Target | 15-30 FPS | Cukup untuk real-time face detection |

### 4.2 Kenapa Preview Bisa Terlihat Jelek (dan Solusinya)

| Penyebab | Solusi |
|----------|--------|
| Default resolusi rendah (352x288 / 480p) | Set `ResolutionPreset.high` (720p) |
| Format YUV tanpa post-processing | Preview 720p sudah cukup bagus secara visual |
| Tidak ada HDR/sharpening/noise reduction | Opsional: tambah ColorFilter ringan di widget |
| Plugin kamera bypass image pipeline HP | Untuk foto enrollment: gunakan `takePicture()` (resolusi penuh) |

### 4.3 Strategi Dual-Mode Capture

| Mode | Resolusi | Digunakan Untuk | Disimpan? |
|------|----------|-----------------|-----------|
| **Stream mode** (real-time) | 720p dari preview stream | ML Kit face detection, liveness, MobileFaceNet embedding | ❌ Tidak (dibuang dari memori) |
| **Photo mode** (sekali) | Resolusi penuh HP (via `takePicture()`) | Foto enrollment untuk biodata/identitas visual | ✅ Ya (JPG, 1x saat enrollment) |

### 4.4 Catatan Penting untuk MobileFaceNet

- Input model: 112x112 piksel (fixed)
- Resolusi kamera asal **tidak berpengaruh** terhadap akurasi embedding
- Mau dari 720p atau 50MP, hasilnya sama karena akan di-resize ke 112x112
- Yang berpengaruh: pencahayaan cukup, wajah tidak blur, bounding box akurat

### 4.5 Data yang Disimpan vs Dibuang

| Proses | Foto JPG | Embedding | Data Numerik |
|--------|----------|-----------|--------------|
| Enrollment (1x) | ✅ Simpan (biodata) | ✅ Simpan (referensi) | ✅ Simpan (metadata) |
| Re-enrollment | ✅ Update foto | ✅ Update embedding | ✅ Simpan |
| Absensi check-in | ❌ Dibuang | ❌ Dibuang (proses di memori) | ✅ Simpan (distance, threshold, result, inference_time) |
| Absensi check-out | ❌ Dibuang | ❌ Dibuang (proses di memori) | ✅ Simpan (distance, threshold, result, inference_time) |

---

## 5. ERROR HANDLING

### 5.1 Error Codes (Mobile)

| Code | Message | Action |
|------|---------|--------|
| GEOFENCE_INVALID | "Anda di luar area perkuliahan" | Tampilkan jarak + radius |
| MOCK_LOCATION_DETECTED | "Terdeteksi manipulasi lokasi" | Block + log anomaly |
| GPS_ACCURACY_LOW | "Akurasi GPS terlalu rendah" | Minta pindah ke area terbuka |
| FACE_NOT_DETECTED | "Wajah tidak terdeteksi" | Instruksi posisi wajah |
| MULTIPLE_FACES | "Hanya 1 wajah yang diizinkan" | Minta yang lain menjauh |
| MASK_DETECTED | "Silakan lepas masker" | - |
| SUNGLASSES_DETECTED | "Silakan lepas kacamata hitam" | - |
| LIVENESS_FAILED | "Verifikasi liveness gagal" | Boleh coba lagi |
| FACE_NOT_MATCH | "Verifikasi wajah gagal" | Boleh coba lagi |
| ENROLLMENT_NOT_APPROVED | "Enrollment belum disetujui" | Hubungi admin |
| SCHEDULE_NOT_ACTIVE | "Tidak ada jadwal aktif saat ini" | Tampilkan jadwal hari ini |
| ALREADY_CHECKED_IN | "Anda sudah check-in" | Tampilkan status |
| NOT_CHECKED_IN | "Anda belum check-in" | - |
| OFFLINE_SYNC_EXPIRED | "Data offline sudah expired" | Absen ulang |
| NETWORK_ERROR | "Koneksi internet bermasalah" | Retry / offline mode |

### 5.2 HTTP Status Codes (API)

| Status | Usage |
|--------|-------|
| 200 | Success |
| 201 | Created |
| 400 | Bad Request (validation error) |
| 401 | Unauthorized (token invalid/expired) |
| 403 | Forbidden (no permission) |
| 404 | Not Found |
| 422 | Unprocessable Entity (business logic error) |
| 429 | Too Many Requests (rate limit) |
| 500 | Internal Server Error |

---

## 6. DEPLOYMENT & INFRASTRUCTURE

### 6.1 Server Requirements

| Component | Minimum Spec |
|-----------|-------------|
| CPU | 2 vCPU |
| RAM | 4 GB |
| Storage | 50 GB SSD |
| OS | Ubuntu 22.04 LTS |
| Bandwidth | 100 Mbps |

### 6.2 Software Stack (Server)

```
- Nginx 1.24+
- PHP 8.2+ (with extensions: pdo_mysql, mbstring, xml, curl, gd, zip)
- MySQL 8.0+
- Redis 7.0+ (caching & queue)
- Node.js 18+ (untuk build Vue frontend)
- Composer 2.x
- Supervisor (queue worker)
- Certbot (SSL)
```

### 6.3 Deployment Flow

```
Development → Staging → Production

1. Developer push ke branch feature
2. Merge ke branch develop (staging)
3. Test di staging server
4. Merge ke branch main (production)
5. Auto-deploy via CI/CD (optional) atau manual deploy

Backend Deploy:
  git pull → composer install → php artisan migrate → php artisan config:cache
  → php artisan route:cache → supervisorctl restart all

Frontend Deploy:
  git pull → npm install → npm run build → copy dist/ ke nginx root
```

### 6.4 Backup Strategy

| Data | Frequency | Retention | Method |
|------|-----------|-----------|--------|
| MySQL Database | Daily (02:00) | 30 hari | mysqldump + gzip |
| Uploaded Files | Daily | 30 hari | rsync to backup storage |
| Full System | Weekly (Minggu 03:00) | 4 minggu | Full VPS snapshot |
| Logs | Daily rotation | 7 hari (active), 30 hari (archive) | logrotate |

---

## 7. TESTING STRATEGY

### 7.1 Backend Testing

| Type | Tool | Coverage Target |
|------|------|----------------|
| Unit Test | PHPUnit | > 80% |
| Feature Test | PHPUnit + Laravel | All API endpoints |
| Integration Test | PHPUnit | Database + Service layer |

### 7.2 Frontend Testing

| Type | Tool | Coverage Target |
|------|------|----------------|
| Unit Test | Vitest | > 70% (components) |
| E2E Test | Cypress / Playwright | Critical flows |

### 7.3 Mobile Testing

| Type | Tool | Coverage Target |
|------|------|----------------|
| Unit Test | Flutter test | > 70% |
| Widget Test | Flutter test | Key widgets |
| Integration Test | Flutter integration_test | Critical flows |
| Device Testing | Physical devices | 3 kategori (low/mid/high) |

### 7.4 Performance Testing

| Type | Tool | Scenario |
|------|------|----------|
| Load Test | Apache JMeter / k6 | 40 concurrent users |
| Stress Test | k6 | Gradually increase to 100 users |
| Endurance Test | k6 | 40 users for 1 hour |

---

## 8. MONITORING & MAINTENANCE

### 8.1 Monitoring

| What | Tool | Alert |
|------|------|-------|
| Server uptime | UptimeRobot / Healthcheck | Email jika down > 5 menit |
| API response time | Laravel Telescope / custom | Jika > 2000ms |
| Error rate | Log monitoring | Jika > 5% error rate |
| Disk usage | Server monitoring | Jika > 80% |
| Database size | Custom script | Jika > 80% capacity |

### 8.2 Maintenance Schedule

| Task | Frequency |
|------|-----------|
| Security patches | Monthly |
| Dependency updates | Monthly |
| Database optimization | Monthly |
| Log cleanup | Weekly |
| Backup verification | Monthly |
| SSL renewal | Auto (Certbot) |

---

## 9. TIMELINE DEVELOPMENT (Estimasi)

| Phase | Durasi | Deliverable |
|-------|--------|-------------|
| **Phase 1: Setup & Foundation** | 2 minggu | Project setup, database, auth, basic CRUD |
| **Phase 2: Academic Module** | 2 minggu | Tahun ajaran, semester, matkul, jadwal, geofence |
| **Phase 3: Mobile - Geofence** | 2 minggu | GPS, mock detection, geofence validation |
| **Phase 4: Mobile - Face Recognition** | 3 minggu | ML Kit, MobileFaceNet, liveness, enrollment |
| **Phase 5: Attendance System** | 2 minggu | Check-in/out, status logic, offline queue |
| **Phase 6: SP & Early Warning** | 2 minggu | Alpha calculation, SP detection, document generation |
| **Phase 7: Web Dashboard** | 3 minggu | All role dashboards, charts, tables |
| **Phase 8: Notifications & Export** | 1 minggu | FCM, in-app notif, Excel/PDF export |
| **Phase 9: Analisis & Evaluasi** | 2 minggu | Menu evaluasi, mode pengujian, dokumentasi teknis |
| **Phase 10: Testing & Optimization** | 3 minggu | Unit test, load test, device testing, bug fixing |
| **Total Estimasi** | **~22 minggu (5.5 bulan)** | Sesuai timeline penelitian (Maret-November 2026) |

---

## 10. RISIKO & MITIGASI

| Risiko | Dampak | Probabilitas | Mitigasi |
|--------|--------|-------------|----------|
| GPS tidak akurat di dalam gedung | Geofence gagal | Medium | Radius geofence cukup besar (50m), fallback ke WiFi |
| Pencahayaan buruk saat face scan | Verifikasi gagal | Medium | Instruksi user, brightness check, retry |
| Device low-end lambat inferensi | UX buruk | Low | Optimasi model TFLite, loading indicator |
| Server down saat jam sibuk | Absensi gagal | Low | Offline mode, auto-sync |
| Mahasiswa tidak punya smartphone | Tidak bisa absen | Low | Dosen override manual |
| Perubahan wajah drastis | Verifikasi gagal | Low | Re-enrollment flow |
| Fake GPS canggih (root) | Bypass geofence | Low | Multiple detection methods, anomaly flag |
