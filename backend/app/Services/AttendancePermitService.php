<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendancePermit;
use App\Models\Jadwal;
use App\Models\ProdiSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttendancePermitService
{
    private const CHALLENGES = ['smile', 'turn_left', 'turn_right', 'blink', 'nod'];

    public function __construct(private AttendancePolicyService $policy) {}

    public function issue(User $user, int $jadwalId, string $action, string $clientUuid, ?int $attendanceId): array
    {
        $jadwal = Jadwal::with(['mataKuliah.semester.tahunAjaran', 'geofence'])->findOrFail($jadwalId);
        $today = Carbon::today();
        $setting = $this->assertEligible($user, $jadwal, $today, false);
        abort_unless($jadwal->hari === Carbon::now()->locale('id')->isoFormat('dddd'), 422);

        $windows = $this->policy->windows($jadwal, $today, $setting);
        $notBefore = $windows['not_before'];
        $captureExpires = $windows['expires_at'];
        abort_unless(now()->between($notBefore, $captureExpires), 422);

        [$permit, $token] = DB::transaction(function () use ($user, $jadwal, $today, $action, $clientUuid, $attendanceId, $notBefore, $captureExpires, $setting): array {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = AttendancePermit::where('user_id', $user->id)
                ->where('client_uuid', $clientUuid)
                ->where('action', $action)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $sameBinding = $existing->jadwal_id === $jadwal->id
                    && $existing->attendance_id === $attendanceId
                    && $existing->occurrence_date->isSameDay($today);
                abort_unless($sameBinding && ! $existing->consumed_at && $existing->permit_token, 409,
                    $existing->consumed_at ? 'Idempotency key sudah digunakan' : 'Idempotency key terikat ke request berbeda');

                return [$existing, $existing->permit_token];
            }

            $attendance = null;
            if ($action === 'check_out') {
                $attendance = Attendance::whereKey($attendanceId)
                    ->where('user_id', $user->id)
                    ->where('jadwal_id', $jadwal->id)
                    ->whereDate('tanggal', $today)
                    ->whereNull('checkout_time')
                    ->lockForUpdate()
                    ->firstOrFail();
            } else {
                abort_if(Attendance::where('user_id', $user->id)->where('jadwal_id', $jadwal->id)
                    ->whereDate('tanggal', $today)->exists(), 409, 'Occurrence attendance sudah memiliki check-in');
            }

            $token = Str::random(64);
            $permit = AttendancePermit::create([
                'token_hash' => hash('sha256', $token),
                'permit_token' => $token,
                'user_id' => $user->id,
                'jadwal_id' => $jadwal->id,
                'mata_kuliah_id' => $jadwal->mata_kuliah_id,
                'attendance_id' => $attendance?->id,
                'occurrence_date' => $today,
                'action' => $action,
                'client_uuid' => $clientUuid,
                'encrypted_challenge' => self::CHALLENGES[array_rand(self::CHALLENGES)],
                'not_before' => $notBefore,
                'capture_expires_at' => $captureExpires,
                'sync_expires_at' => $captureExpires->copy()->addMinutes((int) ($setting?->offline_sync_timeout_menit ?? 30)),
            ]);

            return [$permit, $token];
        });

        $serverTime = now();

        return ['permit_token' => $token, 'liveness_challenge' => $permit->liveness_challenge,
            'issued_at' => $this->policy->iso($permit->created_at),
            'not_before' => $this->policy->iso($permit->not_before),
            'expires_at' => $this->policy->iso($permit->capture_expires_at),
            'sync_expires_at' => $this->policy->iso($permit->sync_expires_at),
            'server_time' => $this->policy->iso($serverTime),
            'windows' => [
                'not_before' => $this->policy->iso($permit->not_before),
                'expires_at' => $this->policy->iso($permit->capture_expires_at),
                'sync_expires_at' => $this->policy->iso($permit->sync_expires_at),
            ],
            'location_policy' => $this->policy->locationPolicy($setting),
        ];
    }

    public function validate(User $user, string $token, string $action, string $clientUuid, int $jadwalId, ?int $attendanceId, Carbon $capturedAt, bool $offline): AttendancePermit
    {
        $permit = AttendancePermit::where('token_hash', hash('sha256', $token))->first();
        abort_unless($permit, 422, 'Permit absensi tidak valid');
        abort_unless($permit->user_id === $user->id && $permit->action === $action
            && $permit->client_uuid === $clientUuid && $permit->jadwal_id === $jadwalId
            && $permit->attendance_id === $attendanceId, 403);

        if ($permit->consumed_at) {
            return $permit;
        }

        abort_unless($capturedAt->between($permit->not_before, $permit->capture_expires_at), 422);
        abort_if($capturedAt->gt(now()->addMinutes(2)), 422);
        abort_if($offline && now()->gt($permit->sync_expires_at), 422);

        $jadwal = Jadwal::with(['mataKuliah.semester.tahunAjaran', 'geofence'])->findOrFail($permit->jadwal_id);
        $this->assertEligible($user, $jadwal, $capturedAt, $offline);
        abort_unless($permit->occurrence_date->isSameDay($capturedAt), 422);
        abort_unless($jadwal->hari === $capturedAt->locale('id')->isoFormat('dddd'), 422);

        return $permit;
    }

    public function lockForConsumption(int $permitId): AttendancePermit
    {
        return AttendancePermit::whereKey($permitId)->lockForUpdate()->firstOrFail();
    }

    public function consume(AttendancePermit $permit): void
    {
        abort_if($permit->consumed_at, 409, 'Permit absensi sudah digunakan');
        $permit->update(['consumed_at' => now()]);
    }

    public function committedOutcome(User $user, AttendancePermit $permit): ?Attendance
    {
        if (! $permit->consumed_at || $permit->user_id !== $user->id) {
            return null;
        }

        if ($permit->action === 'check_in') {
            return Attendance::where('user_id', $user->id)
                ->where('client_uuid', $permit->client_uuid)
                ->first();
        }

        return Attendance::whereKey($permit->attendance_id)
            ->where('user_id', $user->id)
            ->where('checkout_client_uuid', $permit->client_uuid)
            ->first();
    }

    private function assertEligible(User $user, Jadwal $jadwal, Carbon $occurrence, bool $offline): ?ProdiSetting
    {
        abort_unless($user->status === 'aktif' && $user->enrollment_status === 'approved', 403);
        abort_unless($jadwal->status === 'aktif' && $jadwal->mataKuliah?->status === 'aktif'
            && $jadwal->geofence?->status === 'aktif', 422);
        abort_unless($jadwal->mataKuliah->prodi_id === $user->prodi_id, 403);
        abort_unless($user->mataKuliahs()->where('mata_kuliah_id', $jadwal->mata_kuliah_id)->exists(), 403);
        $semester = $jadwal->mataKuliah->semester;
        $tahunAjaran = $semester?->tahunAjaran;
        abort_unless($semester?->status === 'aktif'
            && $occurrence->betweenIncluded($semester->tanggal_mulai->startOfDay(), $semester->tanggal_selesai->endOfDay()), 422);
        abort_unless($tahunAjaran?->status === 'aktif'
            && $occurrence->betweenIncluded($tahunAjaran->tanggal_mulai->startOfDay(), $tahunAjaran->tanggal_selesai->endOfDay()), 422);
        $setting = ProdiSetting::where('prodi_id', $user->prodi_id)->first();
        if ($offline) {
            abort_unless((bool) ($setting?->allow_offline_attendance ?? false), 403);
        }

        return $setting;
    }
}
