<?php

namespace App\Http\Controllers\Api\Mahasiswa;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Jadwal;
use App\Models\User;
use App\Services\AttendancePolicyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class JadwalController extends Controller
{
    /**
     * RENCANA 2: KRS mahasiswa diturunkan dari pivot `mahasiswa_kelas`
     * (kelas aktif) → jadwal kelas tersebut. Bukan lagi dari `users.kelas`
     * atau pivot `mahasiswa_mata_kuliah`.
     */
    private function scheduleQueryFor(User $user): Builder
    {
        $kelasIds = $user->mahasiswaKelas()
            ->whereHas('semester', fn ($q) => $q->where('status', 'aktif'))
            ->pluck('kelas_id');

        return Jadwal::with(['mataKuliah', 'kelas:id,tingkat,nama', 'dosen:id,nama', 'geofence'])
            ->whereIn('kelas_id', $kelasIds)
            ->where('status', 'aktif');
    }

    /**
     * Semua jadwal mahasiswa di semester aktif
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $jadwals = $this->scheduleQueryFor($user)
            ->orderByRaw("FIELD(hari, 'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu')")
            ->orderBy('jam_mulai')
            ->get();

        // Group by hari
        $grouped = $jadwals->groupBy('hari');

        return $this->success($grouped);
    }

    /**
     * Jadwal hari ini
     */
    public function today(Request $request, AttendancePolicyService $policy): JsonResponse
    {
        $user = $request->user();
        $hariIni = Carbon::now()->locale('id')->isoFormat('dddd');

        $jadwals = $this->scheduleQueryFor($user)
            ->where('hari', $hariIni)
            ->orderBy('jam_mulai')
            ->get();

        // H-03: sertakan status attendance hari ini agar mobile dapat menampilkan
        // tombol "Lihat Detail" untuk jadwal yang sudah di-absen.
        $jadwalIds = $jadwals->pluck('id');
        $attMap = Attendance::where('user_id', $user->id)
            ->whereDate('tanggal', today())
            ->whereIn('jadwal_id', $jadwalIds)
            ->get()
            ->keyBy('jadwal_id');

        $serverTime = now();
        $setting = $policy->setting($user->prodi_id);
        $jadwals = $jadwals->map(function ($j) use ($attMap, $policy, $serverTime, $setting) {
            $att = $attMap->get($j->id);
            $windows = $policy->windows($j, $serverTime, $setting);
            $j->attendance_id = $att?->id;
            $j->attendance_status = $att?->status; // null | hadir | hadir_terlambat | pending | alpha | izin | sakit
            $j->checkin_time = $att?->checkin_time;
            $j->checkout_time = $att?->checkout_time;
            $j->window = [
                'starts_at' => $policy->iso($windows['starts_at']),
                'ends_at' => $policy->iso($windows['ends_at']),
                'not_before' => $policy->iso($windows['not_before']),
                'expires_at' => $policy->iso($windows['expires_at']),
            ];
            $j->eligibility = [
                'can_check_in' => ! $att?->checkin_time && $serverTime->betweenIncluded($windows['not_before'], $windows['expires_at']),
                'can_check_out' => (bool) ($att?->checkin_time && ! $att?->checkout_time)
                    && $serverTime->betweenIncluded($windows['not_before'], $windows['expires_at']),
                'evaluated_at' => $policy->iso($serverTime),
            ];

            return $j;
        });

        $response = $this->success($jadwals);
        $response->setData(array_merge((array) $response->getData(true), [
            'meta' => [
                'server_time' => $policy->iso($serverTime),
                'timezone' => config('app.timezone'),
                'location_policy' => $policy->locationPolicy($setting),
            ],
        ]));

        return $response;
    }

    /**
     * Jadwal yang sedang berlangsung saat ini
     */
    public function active(Request $request): JsonResponse
    {
        $user = $request->user();
        $hariIni = Carbon::now()->locale('id')->isoFormat('dddd');
        $now = Carbon::now()->format('H:i:s');

        $jadwals = $this->scheduleQueryFor($user)
            ->where('hari', $hariIni)
            ->where('jam_mulai', '<=', $now)
            ->where('jam_selesai', '>=', $now)
            ->get();

        return $this->success($jadwals);
    }
}
