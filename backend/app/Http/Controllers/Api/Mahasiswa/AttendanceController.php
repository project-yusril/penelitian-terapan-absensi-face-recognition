<?php

namespace App\Http\Controllers\Api\Mahasiswa;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\AuditTrail;
use App\Models\Geofence;
use App\Models\Jadwal;
use App\Models\ProdiSetting;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AttendancePermitService;
use App\Services\AttendancePolicyService;
use App\Services\NotificationOutboxService;
use App\Services\SpDetectionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    /**
     * Check-in absensi
     */
    public function checkIn(Request $request, AttendancePermitService $permits, AttendancePolicyService $policy): JsonResponse
    {
        $request->validate([
            'jadwal_id' => 'required|exists:jadwals,id',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'face_distance' => 'required|numeric|min:0',
            'face_threshold' => 'nullable|numeric|min:0',
            'mock_location_detected' => 'required|boolean',
            'liveness_passed' => 'required|boolean',
            'liveness_challenge' => 'nullable|string|max:50',
            'gps_accuracy' => 'required|numeric|min:0',
            'location_age_ms' => 'required|integer|min:0',
            'inference_time_ms' => 'nullable|integer',
            'device_model' => 'nullable|string|max:100',
            'device_os' => 'nullable|string|max:50',
            'app_version' => 'nullable|string|max:20',
            'permit_token' => 'required|string|size:64',
            'client_uuid' => 'required|uuid',
        ]);

        // Liveness wajib lolos (anti-spoofing wajah) — R-04
        if (! $request->boolean('liveness_passed')) {
            return $this->error('Liveness detection gagal. Pastikan wajah Anda terdeteksi dengan benar.', 422);
        }

        $user = $request->user();
        $permit = $permits->validate($user, $request->permit_token, 'check_in', $request->client_uuid,
            (int) $request->jadwal_id, null, now(), false);
        abort_unless($request->liveness_challenge === $permit->liveness_challenge, 403);
        if ($permit->consumed_at) {
            $attendance = $permits->committedOutcome($user, $permit);
            abort_unless($attendance, 409, 'Permit absensi sudah digunakan tanpa hasil');

            return $this->checkInResponse($attendance, true);
        }
        $jadwal = Jadwal::with(['mataKuliah', 'geofence'])->findOrFail($request->jadwal_id);

        // 1. Validasi enrollment
        if ($user->enrollment_status !== 'approved') {
            return $this->error('Enrollment wajah belum disetujui', 403);
        }

        // 2. Validasi mahasiswa terdaftar di MK
        $enrolled = $user->mataKuliahs()->where('mata_kuliah_id', $jadwal->mata_kuliah_id)->exists();
        if (! $enrolled) {
            return $this->error('Anda tidak terdaftar di mata kuliah ini', 403);
        }

        // 3. Validasi hari
        $hariIni = Carbon::now()->locale('id')->isoFormat('dddd');
        if ($jadwal->hari !== $hariIni) {
            return $this->error('Jadwal ini bukan untuk hari ini', 422);
        }

        // 4. Validasi belum check-in
        $existingAttendance = Attendance::where('user_id', $user->id)
            ->where('jadwal_id', $jadwal->id)
            ->whereDate('tanggal', today())
            ->first();

        if ($existingAttendance && $existingAttendance->checkin_time) {
            $outcome = $permits->committedOutcome($user, $permit->fresh());
            if ($outcome) {
                return $this->checkInResponse($outcome, true);
            }

            return $this->error('Occurrence attendance sudah memiliki check-in dengan UUID berbeda', 409);
        }

        // 5. Validasi mock location (anti-spoofing lokasi) — R-03
        $prodiSetting = ProdiSetting::where('prodi_id', $user->prodi_id)->first();
        $policy->assertLocationEvidence((float) $request->gps_accuracy, (int) $request->location_age_ms, $prodiSetting);
        if ($request->boolean('mock_location_detected') && ! ($prodiSetting?->allow_mock_location)) {
            $this->logAttempt($user->id, null, 'mock_location_detected', 'Fake/mock location terdeteksi', [
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
            ]);

            return $this->error('Fake/mock location terdeteksi', 422);
        }

        // 6. Validasi geofence
        $geofence = $jadwal->geofence;
        $distance = $this->calculateDistance(
            $request->latitude,
            $request->longitude,
            $geofence->latitude,
            $geofence->longitude
        );

        $radius = $geofence->radius ?? $prodiSetting?->default_radius_meter ?? 50;
        if ($distance > $radius) {
            // Log attempt
            $this->logAttempt($user->id, null, 'geofence_invalid', 'Di luar jangkauan geofence', [
                'distance' => round($distance, 2),
                'radius' => $radius,
            ]);

            return $this->error("Anda berada di luar jangkauan lokasi ({$radius}m). Jarak: ".round($distance).'m', 422);
        }

        // 7. Validasi face recognition threshold
        $faceThreshold = $prodiSetting?->face_threshold ?? 1.00;
        if ($request->face_distance > $faceThreshold) {
            // face_distance ditulis ke KOLOM (bukan hanya metadata) agar percobaan
            // impostor yang gagal match tetap masuk sweep FAR/FRR (R-05).
            $this->logAttempt($user->id, null, 'face_not_match', 'Face verification gagal',
                $this->buildResearchMetadata($request, ['threshold' => $faceThreshold]), [
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'face_distance' => $request->face_distance,
                    'face_threshold' => $faceThreshold,
                    'inference_time_ms' => $request->inference_time_ms,
                    'device_model' => $request->device_model,
                    'device_os' => $request->device_os,
                    'app_version' => $request->app_version,
                    'gps_accuracy' => $request->gps_accuracy,
                ]);

            return $this->error('Verifikasi wajah gagal. Silakan coba lagi.', 422);
        }

        // 8. Tentukan status berdasarkan waktu
        $now = Carbon::now();
        $jamMulai = Carbon::parse(today()->format('Y-m-d').' '.$jadwal->jam_mulai);
        $toleransi = $prodiSetting?->toleransi_masuk_menit ?? 15;
        $batasTerlambat = $prodiSetting?->batas_terlambat_persen ?? 50;

        // Hitung durasi MK dalam menit (M-01: pakai helper int agar aman di Carbon 2/3)
        $jamSelesai = Carbon::parse(today()->format('Y-m-d').' '.$jadwal->jam_selesai);
        $durasiMK = $this->minutesBetween($jamMulai, $jamSelesai);
        $batasTerlambatMenit = ($batasTerlambat / 100) * $durasiMK;

        $status = 'hadir';
        if ($now->gt($jamMulai->copy()->addMinutes($toleransi))) {
            $keterlambatan = $this->minutesBetween($jamMulai, $now);
            if ($keterlambatan <= $batasTerlambatMenit) {
                $status = 'hadir_terlambat';
            } else {
                $status = 'pending'; // Perlu approval dosen
            }
        }

        // After status determination, calculate alpha_menit
        $alphaMenit = 0;
        if ($status === 'hadir') {
            $alphaMenit = 0; // CASE 1: tepat waktu
        } elseif ($status === 'hadir_terlambat') {
            $alphaMenit = $this->minutesBetween($jamMulai, $now); // CASE 2: terlambat dari jam_mulai
        } elseif ($status === 'pending') {
            $alphaMenit = $durasiMK; // CASE 6: pending = sementara alpha penuh
        }

        // 9. Hitung pertemuan ke berapa
        $pertemuanKe = Attendance::where('jadwal_id', $jadwal->id)
            ->whereDate('tanggal', '<', today())
            ->distinct('tanggal')
            ->count() + 1;

        // 10. Simpan attendance
        // Race-condition guard: dua request check-in yang nyaris bersamaan bisa
        // sama-sama lolos pengecekan "belum check-in" (step 4). Unique constraint
        // `unique_attendance` (user_id, jadwal_id, tanggal) — sudah ada sejak
        // migrasi awal 2024_01_01_000014 — menjadi penjaga terakhir; jika kena,
        // tangkap QueryException dan kembalikan 422 idempotent (bukan 500).

        try {
            [$attendance, $duplicate] = DB::transaction(function () use ($permits, $permit, $user, $jadwal, $pertemuanKe, $now, $status, $request, $distance, $alphaMenit, $jamMulai, $faceThreshold) {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $lockedPermit = $permits->lockForConsumption($permit->id);
                if ($lockedPermit->consumed_at) {
                    $outcome = $permits->committedOutcome($user, $lockedPermit);
                    abort_unless($outcome, 409, 'Permit absensi sudah digunakan tanpa hasil');

                    return [$outcome, true];
                }
                $attendance = Attendance::create([
                    'client_uuid' => $request->client_uuid,
                    'user_id' => $user->id,
                    'jadwal_id' => $jadwal->id,
                    'mata_kuliah_id' => $jadwal->mata_kuliah_id,
                    'pertemuan_ke' => $pertemuanKe,
                    'tanggal' => today(),
                    'checkin_time' => $now,
                    'status' => $status,
                    'checkin_latitude' => $request->latitude,
                    'checkin_longitude' => $request->longitude,
                    'checkin_distance' => round($distance, 2),
                    'checkin_face_distance' => $request->face_distance,
                    'checkin_liveness_passed' => $request->liveness_passed,
                    'checkin_device' => $request->device_model,
                    'alpha_menit' => $alphaMenit,
                ]);
                $permits->consume($lockedPermit);

                $this->logAttempt($user->id, $attendance->id, 'checkin_success', "Status: {$status}",
                    $this->buildResearchMetadata($request, [
                        'keterlambatan_menit' => $now->gt($jamMulai) ? $this->minutesBetween($jamMulai, $now) : 0,
                    ]), [
                        'latitude' => $request->latitude,
                        'longitude' => $request->longitude,
                        'distance_to_geofence' => round($distance, 2),
                        'face_distance' => $request->face_distance,
                        'face_threshold' => $faceThreshold,
                        'liveness_challenge' => $request->liveness_challenge,
                        'inference_time_ms' => $request->inference_time_ms,
                        'device_model' => $request->device_model,
                        'device_os' => $request->device_os,
                        'app_version' => $request->app_version,
                        'gps_accuracy' => $request->gps_accuracy,
                    ]);
                $this->auditMutation($request, $attendance, 'checkin_attendance', [], [
                    'status' => $status, 'client_uuid' => $request->client_uuid,
                ]);

                if ($status === 'pending' && $jadwal->mataKuliah?->dosen_id) {
                    app(NotificationOutboxService::class)->enqueue(
                        "attendance:{$attendance->id}:pending:{$jadwal->mataKuliah->dosen_id}",
                        $jadwal->mataKuliah->dosen_id,
                        'approval_needed',
                        'Approval kehadiran baru',
                        "{$user->nama} ({$user->nim}) membutuhkan approval kehadiran untuk {$jadwal->mataKuliah->nama}.",
                        ['attendance_id' => $attendance->id, 'mahasiswa_id' => $user->id, 'mata_kuliah_id' => $jadwal->mata_kuliah_id],
                    );
                }

                return [$attendance, false];
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23000' && str_contains((string) ($e->errorInfo[2] ?? $e->getMessage()), 'unique_attendance')) {
                $existing = Attendance::where('user_id', $user->id)->where('jadwal_id', $jadwal->id)
                    ->whereDate('tanggal', today())->first();
                if ($existing?->client_uuid === $request->client_uuid) {
                    return $this->checkInResponse($existing, true);
                }

                return $this->error('Occurrence attendance sudah memiliki check-in dengan UUID berbeda', 409);
            }
            throw $e;
        }

        if ($duplicate) {
            return $this->checkInResponse($attendance, true);
        }

        // Trigger SP detection (H-05: evaluate() sudah memanggil recalculate() di dalamnya)
        app(SpDetectionService::class)->evaluate($user->id);

        $attendance->load(['jadwal.mataKuliah', 'jadwal.geofence']);

        return $this->checkInResponse($attendance);
    }

    /**
     * Check-out absensi
     */
    public function checkOut(Request $request, AttendancePermitService $permits, AttendancePolicyService $policy): JsonResponse
    {
        $request->validate([
            'attendance_id' => 'required|exists:attendances,id',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'face_distance' => 'required|numeric|min:0',
            'face_threshold' => 'nullable|numeric|min:0',
            'mock_location_detected' => 'required|boolean',
            'liveness_passed' => 'required|boolean',
            'liveness_challenge' => 'nullable|string|max:50',
            'gps_accuracy' => 'required|numeric|min:0',
            'location_age_ms' => 'required|integer|min:0',
            'inference_time_ms' => 'nullable|integer',
            'device_model' => 'nullable|string|max:100',
            'device_os' => 'nullable|string|max:50',
            'app_version' => 'nullable|string|max:20',
            'jadwal_id' => 'required|exists:jadwals,id',
            'permit_token' => 'required|string|size:64',
            'client_uuid' => 'required|uuid',
        ]);

        // Liveness wajib lolos (anti-spoofing wajah) — R-04
        if (! $request->boolean('liveness_passed')) {
            return $this->error('Liveness detection gagal saat check-out.', 422);
        }

        $user = $request->user();
        $permit = $permits->validate($user, $request->permit_token, 'check_out', $request->client_uuid,
            (int) $request->jadwal_id, (int) $request->attendance_id, now(), false);
        abort_unless($request->liveness_challenge === $permit->liveness_challenge, 403);
        if ($permit->consumed_at) {
            $attendance = $permits->committedOutcome($user, $permit);
            abort_unless($attendance, 409, 'Permit absensi sudah digunakan tanpa hasil');

            return $this->checkOutResponse($attendance, true);
        }
        $attendance = Attendance::with('jadwal.geofence')
            ->where('id', $request->attendance_id)
            ->where('user_id', $user->id)
            ->first();

        if (! $attendance) {
            $outcome = $permits->committedOutcome($user, $permit->fresh());
            abort_unless($outcome, 404);

            return $this->checkOutResponse($outcome, true);
        }

        $jadwal = $attendance->jadwal;
        $prodiSetting = ProdiSetting::where('prodi_id', $user->prodi_id)->first();
        $policy->assertLocationEvidence((float) $request->gps_accuracy, (int) $request->location_age_ms, $prodiSetting);

        // 1. Validasi mock location (anti-spoofing lokasi) — R-03
        if ($request->boolean('mock_location_detected') && ! ($prodiSetting?->allow_mock_location)) {
            return $this->error('Fake/mock location terdeteksi saat check-out', 422);
        }

        // 2. Validasi geofence
        $geofence = $jadwal->geofence;
        $distance = $this->calculateDistance(
            $request->latitude, $request->longitude,
            $geofence->latitude, $geofence->longitude
        );
        $radius = $geofence->radius ?? $prodiSetting?->default_radius_meter ?? 50;
        if ($distance > $radius) {
            return $this->error('Anda berada di luar jangkauan lokasi saat check-out. Jarak: '.round($distance).'m', 422);
        }

        // 3. Validasi face
        $faceThreshold = $prodiSetting?->face_threshold ?? 1.00;
        if ($request->face_distance > $faceThreshold) {
            return $this->error('Verifikasi wajah gagal saat check-out.', 422);
        }

        $result = DB::transaction(function () use ($permits, $permit, $attendance, $user, $request, $distance, $faceThreshold, $prodiSetting) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $lockedPermit = $permits->lockForConsumption($permit->id);
            if ($lockedPermit->consumed_at) {
                $outcome = $permits->committedOutcome($user, $lockedPermit);
                abort_unless($outcome, 409, 'Permit absensi sudah digunakan tanpa hasil');

                return ['attendance' => $outcome, 'duplicate' => true];
            }
            $lockedAttendance = Attendance::whereKey($attendance->id)
                ->where('user_id', $user->id)
                ->where('jadwal_id', $permit->jadwal_id)
                ->lockForUpdate()
                ->firstOrFail()
                ->load('jadwal');

            if ($lockedAttendance->checkout_time) {
                abort_unless($lockedAttendance->checkout_client_uuid === $lockedPermit->client_uuid, 409, 'Attendance sudah di-check-out dengan permit lain');
                $permits->consume($lockedPermit);

                return ['attendance' => $lockedAttendance, 'duplicate' => true];
            }

            $now = Carbon::now();
            $jamSelesai = Carbon::parse(today()->format('Y-m-d').' '.$lockedAttendance->jadwal->jam_selesai);
            $toleransiPulang = $prodiSetting?->toleransi_pulang_menit ?? 15;
            $checkoutAlphaTambahan = 0;
            $actualCheckoutTime = $now;

            if ($now->lt($jamSelesai->copy()->subMinutes($toleransiPulang))) {
                $checkoutAlphaTambahan = $this->minutesBetween($now, $jamSelesai);
            } elseif ($now->gt($jamSelesai->copy()->addMinutes($toleransiPulang))) {
                $actualCheckoutTime = $jamSelesai;
            }

            $totalAlphaMenit = ($lockedAttendance->alpha_menit ?? 0) + $checkoutAlphaTambahan;
            $durasiEfektifMenit = $this->minutesBetween(Carbon::parse($lockedAttendance->checkin_time), $actualCheckoutTime);
            $lockedAttendance->update([
                'checkout_client_uuid' => $request->client_uuid,
                'checkout_time' => $actualCheckoutTime,
                'checkout_latitude' => $request->latitude,
                'checkout_longitude' => $request->longitude,
                'checkout_distance' => round($distance, 2),
                'checkout_face_distance' => $request->face_distance,
                'checkout_liveness_passed' => $request->liveness_passed,
                'alpha_menit' => $totalAlphaMenit,
                'durasi_efektif_menit' => $durasiEfektifMenit,
            ]);
            $permits->consume($lockedPermit);

            $this->logAttempt($user->id, $lockedAttendance->id, 'checkout_success', 'Check-out berhasil',
                $this->buildResearchMetadata($request, [
                    'checkout_alpha_tambahan' => $checkoutAlphaTambahan,
                    'durasi_efektif_menit' => $durasiEfektifMenit,
                ]), [
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'distance_to_geofence' => round($distance, 2),
                    'face_distance' => $request->face_distance,
                    'face_threshold' => $faceThreshold,
                    'liveness_challenge' => $request->liveness_challenge,
                    'inference_time_ms' => $request->inference_time_ms,
                    'device_model' => $request->device_model,
                    'device_os' => $request->device_os,
                    'app_version' => $request->app_version,
                    'gps_accuracy' => $request->gps_accuracy,
                ]);
            $this->auditMutation($request, $lockedAttendance, 'checkout_attendance',
                ['checkout_time' => null], ['checkout_time' => $actualCheckoutTime, 'client_uuid' => $request->client_uuid]);

            return ['attendance' => $lockedAttendance, 'duplicate' => false];
        });

        if ($result['duplicate']) {
            return $this->checkOutResponse($result['attendance'], true);
        }

        // Trigger SP detection (H-05: evaluate() sudah memanggil recalculate())
        app(SpDetectionService::class)->evaluate($user->id);

        return $this->checkOutResponse($result['attendance']->fresh());
    }

    /**
     * Riwayat kehadiran mahasiswa
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Attendance::with(['jadwal.geofence', 'mataKuliah'])
            ->where('user_id', $user->id);

        if ($request->filled('mata_kuliah_id')) {
            $query->where('mata_kuliah_id', $request->mata_kuliah_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('tanggal', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('tanggal', '<=', $request->date_to);
        }

        $data = $query->orderByDesc('tanggal')->paginate($this->resolvePerPage($request, 20));

        return $this->paginated($data);
    }

    /**
     * Kehadiran hari ini
     */
    public function today(Request $request): JsonResponse
    {
        $user = $request->user();

        $attendances = Attendance::with(['jadwal.geofence', 'mataKuliah'])
            ->where('user_id', $user->id)
            ->whereDate('tanggal', today())
            ->get();

        return $this->success($attendances);
    }

    /**
     * Hitung jarak antara 2 koordinat (Haversine formula)
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // meter

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function checkInResponse(Attendance $attendance, bool $duplicate = false): JsonResponse
    {
        $status = $attendance->status;
        $attendance->loadMissing(['jadwal.mataKuliah', 'jadwal.geofence']);

        return $this->created([
            'attendance' => $attendance,
            'status' => $status,
            'message' => match ($status) {
                'hadir' => 'Check-in berhasil. Anda hadir tepat waktu.',
                'hadir_terlambat' => 'Check-in berhasil. Anda tercatat terlambat.',
                'pending' => 'Check-in tercatat. Menunggu persetujuan dosen karena keterlambatan melebihi batas.',
                default => 'Check-in berhasil.',
            },
            'duplicate' => $duplicate,
        ], 'Check-in berhasil');
    }

    private function checkOutResponse(Attendance $attendance, bool $duplicate = false): JsonResponse
    {
        return $this->success([
            'attendance' => $attendance,
            'checkout_time' => $attendance->checkout_time?->toTimeString(),
            'durasi_efektif_menit' => $attendance->durasi_efektif_menit,
            'alpha_menit' => $attendance->alpha_menit,
            'status' => $attendance->status,
            'duplicate' => $duplicate,
        ], 'Check-out berhasil');
    }

    /**
     * Selisih menit absolut antar 2 waktu sebagai integer (M-01).
     * Aman untuk Carbon 2 (int) maupun Carbon 3 (float bertanda).
     */
    private function minutesBetween(Carbon $a, Carbon $b): int
    {
        return (int) round(abs($a->diffInMinutes($b, false)));
    }

    /**
     * Log attendance attempt.
     * $columns (opsional) menyimpan nilai ke kolom khusus attendance_logs
     * (latitude, longitude, distance_to_geofence, face_distance, face_threshold,
     *  liveness_challenge, inference_time_ms, device_model, device_os, app_version,
     *  gps_accuracy) — dipakai untuk analisis penelitian (R-06).
     */
    private function logAttempt(int $userId, ?int $attendanceId, string $action, string $keterangan, array $metadata = [], array $columns = []): void
    {
        $allowed = [
            'latitude', 'longitude', 'distance_to_geofence', 'face_distance',
            'face_threshold', 'liveness_challenge', 'inference_time_ms',
            'device_model', 'device_os', 'app_version', 'gps_accuracy',
        ];

        // test_type adalah kolom enum('genuine','impostor') — hanya boleh diisi
        // nilai label yang valid, selain itu null (hindari insert gagal di MySQL).
        $label = $metadata['label'] ?? null;

        $payload = [
            'attendance_id' => $attendanceId,
            'user_id' => $userId,
            'action' => $action,
            'keterangan' => $keterangan,
            'is_test_mode' => (bool) ($metadata['is_test_mode'] ?? false),
            'test_type' => in_array($label, ['genuine', 'impostor'], true) ? $label : null,
            'metadata' => $metadata,
            'created_at' => now(),
        ];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $columns) && $columns[$col] !== null) {
                $payload[$col] = $columns[$col];
            }
        }

        AttendanceLog::create($payload);
    }

    /**
     * Bangun metadata penelitian (R-05/R-07):
     * - is_test_mode dari setting global (`test_mode_enabled`).
     * - label genuine|impostor dari header X-Test-Label atau payload
     *   metadata.label; nilai ini juga mengisi kolom enum test_type (lihat
     *   logAttempt) dan dibaca oleh AnalysisController untuk sweep FAR/FRR.
     * - concurrent_level, success, latency_ms dari payload metadata client
     *   (dipakai k6 / load-test simultan untuk R-07).
     */
    private function buildResearchMetadata(Request $request, array $extra = []): array
    {
        $meta = $extra;

        $testMode = SystemSetting::where('key', 'test_mode_enabled')->value('value');
        if ((string) $testMode === '1' || $testMode === 'true') {
            $meta['is_test_mode'] = true;
        }

        $label = $request->header('X-Test-Label') ?? $request->input('metadata.label');
        if (in_array($label, ['genuine', 'impostor'], true)) {
            $meta['label'] = $label;
            $meta['is_test_mode'] = true;
        }

        $client = $request->input('metadata', []);
        if (is_array($client)) {
            foreach (['concurrent_level', 'success', 'latency_ms'] as $k) {
                if (array_key_exists($k, $client)) {
                    $meta[$k] = $client[$k];
                }
            }
        }

        return $meta;
    }

    private function auditMutation(Request $request, Attendance $attendance, string $action, array $old, array $new): void
    {
        AuditTrail::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'model_type' => Attendance::class,
            'model_id' => $attendance->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
