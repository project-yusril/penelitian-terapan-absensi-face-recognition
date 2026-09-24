<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Services\AttemptFotoService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * FOTO ATTEMPT BERISIKO: purge retensi 30 hari.
 *
 * Foto check-in/checkout hanya disimpan untuk attempt berisiko dan hidup
 * 30 hari untuk audit. Setelah itu file dihapus dari disk privat; kolom path
 * di-nol-kan sementara angka/metadata (face_distance, GPS, dll) tetap disimpan
 * selamanya — itu sumber data penelitian R-03/R-04/FAR-FRR.
 *
 * Dijadwalkan harian (routes/console.php). Safety: menghapus maksimal 500
 * foto per run supaya shared hosting tidak kehabisan waktu/IO.
 */
class PurgeAttemptFotos extends Command
{
    protected $signature = 'attendance:purge-attempt-fotos {--days=30} {--limit=500}';

    protected $description = 'Hapus foto attempt berisiko yang melewati masa retensi (default 30 hari)';

    public function handle(AttemptFotoService $fotos): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $cutoff = Carbon::now()->subDays($days);

        $deleted = 0;

        // 1. Foto attempt di attendance_logs (offline + gagal verify).
        $logPaths = AttendanceLog::query()
            ->whereNotNull('foto_path')
            ->where('created_at', '<', $cutoff)
            ->limit($limit)
            ->pluck('id', 'foto_path');

        foreach ($logPaths as $path => $id) {
            $fotos->delete($path);
            AttendanceLog::whereKey($id)->update(['foto_path' => null, 'foto_reason' => null]);
            $deleted++;
        }

        // 2. Foto check-in/checkout final di attendances.
        $attendancePaths = Attendance::query()
            ->where(function ($query) use ($cutoff) {
                $query->where(function ($q) use ($cutoff) {
                    $q->whereNotNull('checkin_foto_path')->where('checkin_time', '<', $cutoff);
                })->orWhere(function ($q) use ($cutoff) {
                    $q->whereNotNull('checkout_foto_path')->where('checkout_time', '<', $cutoff);
                });
            })
            ->limit($limit)
            ->get(['id', 'checkin_foto_path', 'checkout_foto_path']);

        foreach ($attendancePaths as $attendance) {
            DB::transaction(function () use ($attendance, $fotos, &$deleted) {
                $attendance = Attendance::whereKey($attendance->id)->lockForUpdate()->first();

                if ($attendance->checkin_foto_path) {
                    $fotos->delete($attendance->checkin_foto_path);
                    $deleted++;
                }
                if ($attendance->checkout_foto_path) {
                    $fotos->delete($attendance->checkout_foto_path);
                    $deleted++;
                }

                $attendance->update(['checkin_foto_path' => null, 'checkout_foto_path' => null]);
            });
        }

        // 3. Bersihkan orphan di disk attempt yang sudah tak dirujuk DB apa pun
        //    (safety net bila DB insert gagal setelah store).
        $orphanCleaned = 0;
        foreach (Storage::disk(AttemptFotoService::DISK)->files(AttemptFotoService::DIRECTORY) as $path) {
            if ($orphanCleaned >= $limit) {
                break;
            }
            $referenced = AttendanceLog::where('foto_path', $path)->exists()
                || Attendance::where('checkin_foto_path', $path)->orWhere('checkout_foto_path', $path)->exists();
            if (! $referenced) {
                $fotos->delete($path);
                $orphanCleaned++;
            }
        }

        $this->info("Purge attempt foto selesai. {$deleted} foto dihapus (retensi {$days} hari), {$orphanCleaned} orphan dibersihkan.");

        return Command::SUCCESS;
    }
}
