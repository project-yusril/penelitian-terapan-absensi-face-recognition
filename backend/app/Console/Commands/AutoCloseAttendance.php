<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendancePermit;
use App\Models\Jadwal;
use App\Models\ProdiSetting;
use App\Models\User;
use App\Services\SpDetectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AutoCloseAttendance extends Command
{
    protected $signature = 'attendance:auto-close';

    protected $description = 'Auto-close attendance records yang belum checkout setelah jadwal selesai';

    public function handle(): int
    {
        $now = Carbon::now();
        $hariIni = $now->locale('id')->isoFormat('dddd');

        $jadwals = Jadwal::with('mataKuliah')->where('hari', $hariIni)
            ->where('status', 'aktif')
            ->get();

        $closedCount = 0;

        foreach ($jadwals as $jadwal) {
            $jamSelesai = Carbon::parse(today()->format('Y-m-d').' '.$jadwal->jam_selesai);
            $tolerance = (int) (ProdiSetting::where('prodi_id', $jadwal->mataKuliah?->prodi_id)
                ->value('toleransi_pulang_menit') ?? 15);
            $batasAutoClose = $jamSelesai->copy()->addMinutes($tolerance);

            // Hanya proses jika sudah lewat batas auto-close
            if ($now->lt($batasAutoClose)) {
                continue;
            }

            // Cari attendance yang sudah check-in tapi belum check-out
            $attendanceIds = Attendance::where('jadwal_id', $jadwal->id)
                ->whereDate('tanggal', today())
                ->whereNotNull('checkin_time')
                ->whereNull('checkout_time')
                ->pluck('id');

            foreach ($attendanceIds as $attendanceId) {
                try {
                    $userId = DB::transaction(function () use ($attendanceId, $jamSelesai): ?int {
                        $candidate = Attendance::whereKey($attendanceId)->first();
                        if (! $candidate) {
                            return null;
                        }
                        User::whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
                        $attendance = Attendance::whereKey($attendanceId)->lockForUpdate()->first();
                        if (! $attendance || $attendance->checkout_time) {
                            return null;
                        }

                        $hasPendingCheckout = AttendancePermit::where('attendance_id', $attendance->id)
                            ->where('action', 'check_out')
                            ->whereNull('consumed_at')
                            ->where('sync_expires_at', '>=', now())
                            ->lockForUpdate()
                            ->exists();
                        if ($hasPendingCheckout) {
                            return null;
                        }

                        $attendance->update([
                            'checkout_time' => $jamSelesai,
                            'is_auto_closed' => true,
                            'durasi_efektif_menit' => (int) round(abs(Carbon::parse($attendance->checkin_time)->diffInMinutes($jamSelesai, false))),
                        ]);

                        return $attendance->user_id;
                    });
                    if ($userId) {
                        app(SpDetectionService::class)->evaluate($userId, $jadwal->mataKuliah?->semester_id);
                        $closedCount++;
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        $this->info("Auto-close selesai. {$closedCount} attendance records ditutup.");

        return Command::SUCCESS;
    }
}
