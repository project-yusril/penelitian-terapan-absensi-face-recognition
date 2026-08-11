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
        abort_unless($jadwal->hari === Carbon::now()->locale('id')->isoFormat('dddd'), 422,
            "Jadwal ini hari {$jadwal->hari}, bukan hari ini.");

        $windows = $this->policy->windows($jadwal, $today, $setting);
        $notBefore = $windows['not_before'];
        $captureExpires = $windows['expires_at'];
        abort_unless(now()->between($notBefore, $captureExpires), 422,
            'Belum masuk waktu absensi. Absensi dibuka '.$notBefore->format('H:i').' sampai '.$captureExpires->format('H:i').'.');

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
        // Pesan sengaja tetap umum: ketidakcocokan pengikatan permit adalah
        // sinyal penyalahgunaan, jadi tidak perlu memberi tahu bagian mana
        // yang tidak cocok.
        abort_unless($permit->user_id === $user->id && $permit->action === $action
            && $permit->client_uuid === $clientUuid && $permit->jadwal_id === $jadwalId
            && $permit->attendance_id === $attendanceId, 403,
            'Permit absensi tidak cocok dengan permintaan ini.');

        if ($permit->consumed_at) {
            return $permit;
        }

        abort_unless($capturedAt->between($permit->not_before, $permit->capture_expires_at), 422,
            'Foto diambil di luar rentang waktu permit ('
            .$permit->not_before->format('H:i').' – '.$permit->capture_expires_at->format('H:i').').');
        abort_if($capturedAt->gt(now()->addMinutes(2)), 422,
            'Waktu pengambilan foto lebih maju dari waktu server. Periksa jam perangkat Anda.');
        abort_if($offline && now()->gt($permit->sync_expires_at), 422,
            'Batas waktu sinkronisasi absensi offline sudah lewat ('
            .$permit->sync_expires_at->format('d/m/Y H:i').').');

        $jadwal = Jadwal::with(['mataKuliah.semester.tahunAjaran', 'geofence'])->findOrFail($permit->jadwal_id);
        $this->assertEligible($user, $jadwal, $capturedAt, $offline);
        abort_unless($permit->occurrence_date->isSameDay($capturedAt), 422,
            'Permit absensi diterbitkan untuk tanggal '.$permit->occurrence_date->format('d/m/Y').'.');
        abort_unless($jadwal->hari === $capturedAt->locale('id')->isoFormat('dddd'), 422,
            "Jadwal ini hari {$jadwal->hari}, bukan hari pengambilan foto.");

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

    /**
     * Setiap penolakan di sini WAJIB membawa pesan.
     *
     * Sebelumnya semua cek memakai `abort_unless(..., 422)` tanpa argumen
     * ketiga, sehingga Laravel mengirim 422 dengan `message` kosong. Akibatnya
     * enam sebab yang sangat berbeda — semester kedaluwarsa, geofence
     * dimatikan, mata kuliah nonaktif, jadwal beda hari, di luar jam — tampil
     * identik di aplikasi sebagai "(HTTP 422)" tanpa keterangan apa pun.
     * Mahasiswa tidak tahu harus berbuat apa, dan admin tidak tahu apa yang
     * perlu dibetulkan.
     *
     * Pesan sengaja menyebut tanggal/jam konkret supaya bisa langsung
     * ditindaklanjuti tanpa membuka database.
     */
    private function assertEligible(User $user, Jadwal $jadwal, Carbon $occurrence, bool $offline): ?ProdiSetting
    {
        abort_unless($user->status === 'aktif' && $user->enrollment_status === 'approved', 403,
            'Akun belum aktif atau enrollment wajah belum disetujui.');
        abort_unless($jadwal->status === 'aktif' && $jadwal->mataKuliah?->status === 'aktif'
            && $jadwal->geofence?->status === 'aktif', 422,
            'Jadwal, mata kuliah, atau lokasi geofence sedang nonaktif.');
        abort_unless($jadwal->mataKuliah->prodi_id === $user->prodi_id, 403,
            'Mata kuliah ini bukan milik program studi Anda.');
        abort_unless($user->mataKuliahs()->where('mata_kuliah_id', $jadwal->mata_kuliah_id)->exists(), 403,
            'Mata kuliah ini tidak ada di KRS Anda.');

        $semester = $jadwal->mataKuliah->semester;
        $tahunAjaran = $semester?->tahunAjaran;

        abort_unless($semester !== null, 422, 'Mata kuliah ini belum terhubung ke semester mana pun.');
        abort_unless($semester->status === 'aktif', 422,
            "Semester {$semester->nama} berstatus {$semester->status}, bukan aktif.");
        abort_unless($occurrence->betweenIncluded($semester->tanggal_mulai->startOfDay(), $semester->tanggal_selesai->endOfDay()), 422,
            "Tanggal {$occurrence->format('d/m/Y')} di luar periode semester {$semester->nama} "
            ."({$semester->tanggal_mulai->format('d/m/Y')} – {$semester->tanggal_selesai->format('d/m/Y')}).");

        abort_unless($tahunAjaran !== null, 422, 'Semester ini belum terhubung ke tahun ajaran mana pun.');
        abort_unless($tahunAjaran->status === 'aktif', 422,
            "Tahun ajaran {$tahunAjaran->nama} berstatus {$tahunAjaran->status}, bukan aktif.");
        abort_unless($occurrence->betweenIncluded($tahunAjaran->tanggal_mulai->startOfDay(), $tahunAjaran->tanggal_selesai->endOfDay()), 422,
            "Tanggal {$occurrence->format('d/m/Y')} di luar periode tahun ajaran {$tahunAjaran->nama} "
            ."({$tahunAjaran->tanggal_mulai->format('d/m/Y')} – {$tahunAjaran->tanggal_selesai->format('d/m/Y')}).");

        $setting = ProdiSetting::where('prodi_id', $user->prodi_id)->first();
        if ($offline) {
            abort_unless((bool) ($setting?->allow_offline_attendance ?? false), 403,
                'Absensi offline tidak diizinkan untuk program studi Anda.');
        }

        return $setting;
    }
}
