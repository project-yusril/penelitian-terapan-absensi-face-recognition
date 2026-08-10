<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Jadwal;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveApprovalService;
use App\Services\SpDetectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MarkAbsentAttendance extends Command
{
    protected $signature = 'attendance:mark-absent';

    protected $description = 'Tandai mahasiswa yang tidak hadir sebagai ALPHA di akhir hari';

    public function handle(): int
    {
        $hariIni = Carbon::now()->locale('id')->isoFormat('dddd');
        $tanggalHariIni = today();

        // Cari semua jadwal hari ini yang aktif
        $jadwals = Jadwal::with('mataKuliah.mahasiswas')
            ->where('hari', $hariIni)
            ->where('status', 'aktif')
            ->get();

        $absentCount = 0;

        foreach ($jadwals as $jadwal) {
            $mataKuliah = $jadwal->mataKuliah;
            if (! $mataKuliah) {
                continue;
            }

            // Ambil semua mahasiswa yang terdaftar di MK ini
            $mahasiswaIds = $mataKuliah->mahasiswas()->pluck('users.id');

            foreach ($mahasiswaIds as $mahasiswaId) {
                try {
                    $leave = LeaveRequest::where('user_id', $mahasiswaId)
                        ->where('mata_kuliah_id', $mataKuliah->id)
                        ->where('tanggal_mulai', '<=', $tanggalHariIni)
                        ->where('tanggal_selesai', '>=', $tanggalHariIni)
                        ->where('status', 'approved')
                        ->first();
                    if ($leave) {
                        app(LeaveApprovalService::class)->materializeLegacy($leave, $jadwal, $tanggalHariIni);

                        continue;
                    }

                    $created = DB::transaction(function () use ($mahasiswaId, $jadwal, $mataKuliah, $tanggalHariIni): bool {
                        User::whereKey($mahasiswaId)->lockForUpdate()->firstOrFail();
                        if (Attendance::where('user_id', $mahasiswaId)->where('jadwal_id', $jadwal->id)
                            ->whereDate('tanggal', $tanggalHariIni)->lockForUpdate()->exists()) {
                            return false;
                        }
                        Attendance::create([
                            'user_id' => $mahasiswaId,
                            'jadwal_id' => $jadwal->id,
                            'mata_kuliah_id' => $mataKuliah->id,
                            'pertemuan_ke' => Attendance::where('jadwal_id', $jadwal->id)
                                ->whereDate('tanggal', '<', $tanggalHariIni)->distinct('tanggal')->count() + 1,
                            'tanggal' => $tanggalHariIni,
                            'status' => 'alpha',
                            'alpha_menit' => $jadwal->durasi_menit,
                        ]);

                        return true;
                    });
                    if ($created) {
                        app(SpDetectionService::class)->evaluate($mahasiswaId, $mataKuliah->semester_id);
                        $absentCount++;
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        $this->info("Mark absent selesai. {$absentCount} mahasiswa ditandai ALPHA.");

        return Command::SUCCESS;
    }
}
