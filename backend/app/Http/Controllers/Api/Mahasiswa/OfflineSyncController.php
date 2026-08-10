<?php

namespace App\Http\Controllers\Api\Mahasiswa;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\AuditTrail;
use App\Models\Jadwal;
use App\Models\ProdiSetting;
use App\Models\User;
use App\Services\AttendancePermitService;
use App\Services\AttendancePolicyService;
use App\Services\NotificationOutboxService;
use App\Services\SpDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Offline Sync Controller
 *
 * Fixes:
 *  - C-05: validasi anti-spoofing (mock GPS, geofence, liveness, threshold wajah)
 *          tetap dijalankan saat sinkron, sama persis dengan endpoint online.
 *          Sebelumnya, mahasiswa bisa mem-bypass keamanan dengan mengirim payload
 *          via endpoint ini.
 *  - M-02: idempotency via `client_uuid`. Jika user kirim ulang batch yang sama
 *          (mis. retry karena timeout), record yang sudah pernah dibuat akan
 *          dikembalikan tanpa duplikasi. UUID disimpan ke `attendances.client_uuid`.
 *  - Setelah batch selesai, alpha_accumulation + SP detection di-trigger satu
 *          kali per user (lebih hemat ketimbang per-item).
 */
class OfflineSyncController extends Controller
{
    /**
     * Sync offline attendance data
     */
    public function sync(Request $request, AttendancePermitService $permits, AttendancePolicyService $policy): JsonResponse
    {
        $request->validate([
            'attendances' => 'required|array|min:1|max:20',
            'attendances.*.client_uuid' => 'required|string|uuid', // M-02
            'attendances.*.jadwal_id' => 'required|exists:jadwals,id',
            'attendances.*.attendance_id' => 'exclude_unless:attendances.*.type,check_out|required_if:attendances.*.type,check_out|integer',
            'attendances.*.type' => 'required|in:check_in,check_out',
            'attendances.*.timestamp' => 'required|date',
            'attendances.*.latitude' => 'required|numeric|between:-90,90',
            'attendances.*.longitude' => 'required|numeric|between:-180,180',
            'attendances.*.face_distance' => 'required|numeric|min:0',
            'attendances.*.mock_location_detected' => 'required|boolean', // C-05
            'attendances.*.liveness_passed' => 'required|boolean', // C-05
            'attendances.*.gps_accuracy' => 'required|numeric|min:0',
            'attendances.*.location_age_ms' => 'required|integer|min:0',
            'attendances.*.inference_time_ms' => 'nullable|integer',
            'attendances.*.liveness_challenge' => 'nullable|string|max:50',
            'attendances.*.device_model' => 'nullable|string|max:100',
            'attendances.*.device_os' => 'nullable|string|max:50',
            'attendances.*.app_version' => 'nullable|string|max:20',
            'attendances.*.permit_token' => 'required|string|size:64',
        ]);

        $user = $request->user();
        $prodiSetting = ProdiSetting::where('prodi_id', $user->prodi_id)->first();
        $faceThreshold = (float) ($prodiSetting?->face_threshold ?? 1.00);
        $allowMock = (bool) ($prodiSetting?->allow_mock_location ?? false);
        $toleransiMasuk = $prodiSetting?->toleransi_masuk_menit ?? 15;
        $toleransiPulang = $prodiSetting?->toleransi_pulang_menit ?? 15;
        $batasTerlambatPersen = $prodiSetting?->batas_terlambat_persen ?? 50;

        $results = [];
        $successCount = 0;
        $failedCount = 0;

        foreach ($request->attendances as $item) {
            $timestamp = Carbon::parse($item['timestamp']);

            try {
                $policy->assertLocationEvidence((float) $item['gps_accuracy'], (int) $item['location_age_ms'], $prodiSetting);
                $permit = $permits->validate($user, $item['permit_token'], $item['type'], $item['client_uuid'],
                    (int) $item['jadwal_id'], isset($item['attendance_id']) ? (int) $item['attendance_id'] : null,
                    $timestamp, true);
                abort_unless(($item['liveness_challenge'] ?? null) === $permit->liveness_challenge, 403);
                if ($permit->consumed_at) {
                    $outcome = $permits->committedOutcome($user, $permit);
                    abort_unless($outcome, 409, 'Permit absensi sudah digunakan tanpa hasil');
                    $results[] = $this->duplicate($item, $outcome);
                    $successCount++;

                    continue;
                }
            } catch (\Throwable $e) {
                $code = in_array($e->getMessage(), ['gps_accuracy_invalid', 'gps_accuracy_exceeded', 'location_age_invalid', 'location_too_old'], true)
                    ? $e->getMessage() : 'permit_invalid';
                $results[] = $this->fail($item, $code, $code === 'permit_invalid' ? 'Permit atau binding absensi tidak valid' : $this->policyMessage($code));
                $failedCount++;

                continue;
            }

            $jadwal = Jadwal::with('geofence')->find($item['jadwal_id']);

            if (! $jadwal || ! $jadwal->geofence) {
                $results[] = $this->fail($item, 'schedule_not_found', 'Jadwal/geofence tidak ditemukan');
                $failedCount++;

                continue;
            }

            // C-05: validasi mock GPS (kecuali test-mode di prodi mengizinkan)
            if (! empty($item['mock_location_detected']) && ! $allowMock) {
                $this->log($user->id, null, 'mock_location_detected',
                    'Offline sync ditolak: fake GPS', $item);
                $results[] = $this->fail($item, 'mock_location_detected', 'Fake/mock location terdeteksi');
                $failedCount++;

                continue;
            }

            // C-05: liveness wajib lolos
            if (empty($item['liveness_passed'])) {
                $this->log($user->id, null, 'liveness_failed',
                    'Offline sync ditolak: liveness gagal', $item);
                $results[] = $this->fail($item, 'liveness_failed', 'Liveness detection gagal');
                $failedCount++;

                continue;
            }

            // C-05: validasi geofence
            $distance = $this->haversine(
                (float) $item['latitude'], (float) $item['longitude'],
                (float) $jadwal->geofence->latitude, (float) $jadwal->geofence->longitude
            );
            $radius = $jadwal->geofence->radius ?? $prodiSetting?->default_radius_meter ?? 50;
            if ($distance > $radius) {
                $this->log($user->id, null, 'geofence_invalid',
                    'Offline sync ditolak: di luar geofence', $item + ['distance' => $distance]);
                $results[] = $this->fail($item, 'outside_geofence',
                    'Di luar geofence saat offline (jarak: '.round($distance).'m)');
                $failedCount++;

                continue;
            }

            // C-05: validasi face threshold
            if ((float) $item['face_distance'] > $faceThreshold) {
                $this->log($user->id, null, 'face_not_match',
                    'Offline sync ditolak: face verification gagal', $item);
                $results[] = $this->fail($item, 'face_not_match', 'Verifikasi wajah gagal');
                $failedCount++;

                continue;
            }

            try {
                $result = DB::transaction(function () use (
                    $user, $jadwal, $item, $timestamp, $distance, $permit, $permits,
                    $toleransiMasuk, $toleransiPulang, $batasTerlambatPersen, $faceThreshold
                ) {
                    User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                    $lockedPermit = $permits->lockForConsumption($permit->id);
                    if ($lockedPermit->consumed_at) {
                        $outcome = $permits->committedOutcome($user, $lockedPermit);
                        abort_unless($outcome, 409, 'Permit absensi sudah digunakan tanpa hasil');

                        return $this->duplicate($item, $outcome);
                    }

                    $result = $item['type'] === 'check_in'
                        ? $this->processCheckIn($user, $jadwal, $item, $timestamp, $distance,
                            $toleransiMasuk, $batasTerlambatPersen, $faceThreshold)
                        : $this->processCheckOut($user, $jadwal, $item, $timestamp, $distance,
                            $toleransiPulang, $faceThreshold);

                    if ($result['status'] === 'success') {
                        $permits->consume($lockedPermit);
                    }

                    return $result;
                });
            } catch (\Throwable $e) {
                $result = $this->fail($item, 'processing_error', 'Gagal memproses absensi', true);
            }

            $results[] = $result;
            in_array($result['status'], ['success', 'duplicate'], true) ? $successCount++ : $failedCount++;
        }

        // Trigger SP detection (yang internal akan recalculate alpha) — 1x per user
        if ($successCount > 0) {
            app(SpDetectionService::class)->evaluate($user->id);
        }

        return $this->success([
            'total' => count($request->attendances),
            'success' => $successCount,
            'failed' => $failedCount,
            'results' => $results,
        ], "Sync selesai: {$successCount} berhasil, {$failedCount} gagal");
    }

    /**
     * Proses check-in offline (PRD-05 logic).
     */
    private function processCheckIn(
        $user, $jadwal, array $item, Carbon $timestamp, float $distance,
        int $toleransi, int $batasTerlambatPersen, float $faceThreshold
    ): array {
        // Cek sudah ada check-in untuk jadwal pada tanggal itu (selain via uuid)
        $existing = Attendance::where('user_id', $user->id)
            ->where('jadwal_id', $jadwal->id)
            ->whereDate('tanggal', $timestamp->toDateString())
            ->first();

        if ($existing && $existing->checkin_time) {
            return [
                'client_uuid' => $item['client_uuid'],
                'jadwal_id' => $item['jadwal_id'],
                'status' => 'failed',
                'code' => 'attendance_exists',
                'retryable' => false,
                'reason' => 'Sudah ada check-in untuk jadwal ini',
            ];
        }

        // Tentukan status & alpha_menit
        $jamMulai = Carbon::parse($timestamp->format('Y-m-d').' '.$jadwal->jam_mulai);
        $jamSelesai = Carbon::parse($timestamp->format('Y-m-d').' '.$jadwal->jam_selesai);
        $durasiMK = (int) round(abs($jamMulai->diffInMinutes($jamSelesai, false)));
        $batasTerlambatMenit = ($batasTerlambatPersen / 100) * $durasiMK;

        $status = 'hadir';
        $alphaMenit = 0;
        if ($timestamp->gt($jamMulai->copy()->addMinutes($toleransi))) {
            $keterlambatan = (int) round(abs($jamMulai->diffInMinutes($timestamp, false)));
            if ($keterlambatan <= $batasTerlambatMenit) {
                $status = 'hadir_terlambat';
                $alphaMenit = $keterlambatan;
            } else {
                $status = 'pending';
                $alphaMenit = $durasiMK;
            }
        }

        $pertemuanKe = Attendance::where('jadwal_id', $jadwal->id)
            ->whereDate('tanggal', '<', $timestamp->toDateString())
            ->distinct('tanggal')
            ->count() + 1;

        $attendance = Attendance::create([
            'client_uuid' => $item['client_uuid'], // M-02
            'user_id' => $user->id,
            'jadwal_id' => $jadwal->id,
            'mata_kuliah_id' => $jadwal->mata_kuliah_id,
            'pertemuan_ke' => $pertemuanKe,
            'tanggal' => $timestamp->toDateString(),
            'checkin_time' => $timestamp,
            'status' => $status,
            'checkin_latitude' => $item['latitude'],
            'checkin_longitude' => $item['longitude'],
            'checkin_distance' => round($distance, 2),
            'checkin_face_distance' => $item['face_distance'],
            'checkin_liveness_passed' => (bool) $item['liveness_passed'],
            'checkin_device' => $item['device_model'] ?? null,
            'alpha_menit' => $alphaMenit,
            'is_offline_synced' => true,
            'catatan' => 'Offline sync',
        ]);

        $this->log($user->id, $attendance->id, 'offline_checkin',
            "Offline check-in synced. Status: {$status}", $item + [
                'distance_to_geofence' => round($distance, 2),
                'face_threshold' => $faceThreshold,
            ]);
        AuditTrail::create([
            'user_id' => $user->id,
            'action' => 'offline_checkin_attendance',
            'model_type' => Attendance::class,
            'model_id' => $attendance->id,
            'old_values' => [],
            'new_values' => ['status' => $status, 'client_uuid' => $item['client_uuid']],
        ]);

        if ($status === 'pending') {
            $dosenId = $jadwal->mataKuliah()->value('dosen_id');
            if ($dosenId) {
                app(NotificationOutboxService::class)->enqueue(
                    "attendance:{$attendance->id}:pending:{$dosenId}",
                    $dosenId,
                    'approval_needed',
                    'Approval kehadiran baru',
                    "{$user->nama} ({$user->nim}) membutuhkan approval kehadiran untuk {$jadwal->mataKuliah()->value('nama')}.",
                    ['attendance_id' => $attendance->id, 'mahasiswa_id' => $user->id, 'mata_kuliah_id' => $jadwal->mata_kuliah_id],
                );
            }
        }

        return [
            'client_uuid' => $item['client_uuid'],
            'jadwal_id' => $item['jadwal_id'],
            'status' => 'success',
            'attendance_id' => $attendance->id,
            'attendance_status' => $status,
        ];
    }

    /**
     * Proses check-out offline (PRD-05 logic).
     */
    private function processCheckOut(
        $user, $jadwal, array $item, Carbon $timestamp, float $distance,
        int $toleransiPulang, float $faceThreshold
    ): array {
        $attendanceId = $item['attendance_id'] ?? null;

        $attendance = $attendanceId
            ? Attendance::where('id', $attendanceId)
                ->where('user_id', $user->id)
                ->where('jadwal_id', $jadwal->id)
                ->where('mata_kuliah_id', $jadwal->mata_kuliah_id)
                ->whereDate('tanggal', $timestamp->toDateString())
                ->lockForUpdate()
                ->first()
            : Attendance::where('user_id', $user->id)
                ->where('jadwal_id', $item['jadwal_id'])
                ->whereDate('tanggal', $timestamp->toDateString())
                ->lockForUpdate()
                ->first();

        if (! $attendance) {
            return $this->fail($item, 'attendance_not_found', 'Tidak ada attendance record yang bisa di-checkout');
        }
        if ($attendance->checkout_time) {
            return $attendance->checkout_client_uuid === $item['client_uuid']
                ? $this->duplicate($item, $attendance)
                : $this->fail($item, 'attendance_already_checked_out', 'Attendance sudah di-check-out dengan permit lain');
        }
        if ($timestamp->lt(Carbon::parse($attendance->checkin_time))) {
            return $this->fail($item, 'checkout_before_checkin', 'Timestamp checkout mendahului check-in');
        }

        // Hitung tambahan alpha (CASE B & C dari PRD-05)
        $jamSelesai = Carbon::parse($timestamp->format('Y-m-d').' '.$jadwal->jam_selesai);
        $alphaTambahan = 0;
        $actualCheckout = $timestamp;
        if ($timestamp->lt($jamSelesai->copy()->subMinutes($toleransiPulang))) {
            $alphaTambahan = (int) round(abs($timestamp->diffInMinutes($jamSelesai, false)));
        } elseif ($timestamp->gt($jamSelesai->copy()->addMinutes($toleransiPulang))) {
            $actualCheckout = $jamSelesai;
        }

        $totalAlpha = ($attendance->alpha_menit ?? 0) + $alphaTambahan;
        $checkinTime = Carbon::parse($attendance->checkin_time);
        $durasiEfektif = (int) round($checkinTime->diffInMinutes($actualCheckout, false));

        $attendance->update([
            'checkout_client_uuid' => $item['client_uuid'],
            'checkout_time' => $actualCheckout,
            'checkout_latitude' => $item['latitude'],
            'checkout_longitude' => $item['longitude'],
            'checkout_distance' => round($distance, 2),
            'checkout_face_distance' => $item['face_distance'],
            'checkout_liveness_passed' => (bool) $item['liveness_passed'],
            'alpha_menit' => $totalAlpha,
            'durasi_efektif_menit' => $durasiEfektif,
            'is_offline_synced' => true,
        ]);

        $this->log($user->id, $attendance->id, 'offline_checkout',
            'Offline check-out synced', $item + [
                'distance_to_geofence' => round($distance, 2),
                'face_threshold' => $faceThreshold,
                'durasi_efektif_menit' => $durasiEfektif,
            ]);
        AuditTrail::create([
            'user_id' => $user->id,
            'action' => 'offline_checkout_attendance',
            'model_type' => Attendance::class,
            'model_id' => $attendance->id,
            'old_values' => ['checkout_time' => null],
            'new_values' => ['checkout_time' => $actualCheckout, 'client_uuid' => $item['client_uuid']],
        ]);

        return [
            'client_uuid' => $item['client_uuid'],
            'jadwal_id' => $item['jadwal_id'],
            'status' => 'success',
            'attendance_id' => $attendance->id,
        ];
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function fail(array $item, string $code, string $reason, bool $retryable = false): array
    {
        return [
            'client_uuid' => $item['client_uuid'] ?? null,
            'jadwal_id' => $item['jadwal_id'] ?? null,
            'status' => 'failed',
            'code' => $code,
            'retryable' => $retryable,
            'reason' => $reason,
        ];
    }

    private function policyMessage(string $code): string
    {
        return match ($code) {
            'gps_accuracy_exceeded' => 'Akurasi GPS melebihi batas kebijakan',
            'location_too_old' => 'Lokasi sudah terlalu lama',
            'location_age_invalid' => 'Usia lokasi tidak valid',
            default => 'Akurasi GPS tidak valid',
        };
    }

    private function duplicate(array $item, Attendance $attendance): array
    {
        return [
            'client_uuid' => $item['client_uuid'],
            'jadwal_id' => $item['jadwal_id'],
            'status' => 'duplicate',
            'attendance_id' => $attendance->id,
            'attendance_status' => $attendance->status,
        ];
    }

    private function log(int $userId, ?int $attendanceId, string $action, string $keterangan, array $item): void
    {
        AttendanceLog::create([
            'attendance_id' => $attendanceId,
            'user_id' => $userId,
            'action' => $action,
            'keterangan' => $keterangan,
            'latitude' => $item['latitude'] ?? null,
            'longitude' => $item['longitude'] ?? null,
            'distance_to_geofence' => $item['distance_to_geofence'] ?? null,
            'face_distance' => $item['face_distance'] ?? null,
            'face_threshold' => $item['face_threshold'] ?? null,
            'liveness_challenge' => $item['liveness_challenge'] ?? null,
            'inference_time_ms' => $item['inference_time_ms'] ?? null,
            'device_model' => $item['device_model'] ?? null,
            'device_os' => $item['device_os'] ?? null,
            'app_version' => $item['app_version'] ?? null,
            'gps_accuracy' => $item['gps_accuracy'] ?? null,
            'metadata' => [
                'original_timestamp' => $item['timestamp'] ?? null,
                'client_uuid' => $item['client_uuid'] ?? null,
            ],
        ]);
    }
}
