<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\AuditTrail;
use App\Models\ProdiSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceWorkflowService
{
    public function __construct(private SpDetectionService $spDetection) {}

    public function approvePending(User $actor, int $attendanceId, Request $request, string $note): Attendance
    {
        $attendance = DB::transaction(function () use ($actor, $attendanceId, $request, $note): Attendance {
            $userId = Attendance::whereKey($attendanceId)->value('user_id');
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $attendance = Attendance::with(['jadwal', 'mataKuliah', 'user'])->lockForUpdate()->findOrFail($attendanceId);
            abort_unless($attendance->status === 'pending', 409, 'Hanya status pending yang bisa disetujui');

            $alpha = $this->lateMinutes($attendance);
            $tolerance = (int) (ProdiSetting::where('prodi_id', $attendance->user?->prodi_id)
                ->value('toleransi_masuk_menit') ?? 15);
            $status = $alpha <= $tolerance ? 'hadir' : 'hadir_terlambat';
            $alpha = $status === 'hadir' ? 0 : $alpha;

            $attendance->update([
                'status' => $status,
                'alpha_menit' => $alpha,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'approval_status' => 'approved',
            ]);
            $this->record($attendance, $actor, 'approve', 'pending', $status, $note, $request, [
                'alpha_menit' => $alpha,
                'toleransi_masuk_menit' => $tolerance,
            ]);
            $this->attendanceResultNotification($attendance, 'approved');

            return $attendance->fresh();
        });
        $this->spDetection->evaluate($attendance->user_id, $attendance->mataKuliah->semester_id);

        return $attendance;
    }

    public function rejectPending(User $actor, int $attendanceId, ?string $reason, Request $request, string $defaultNote): Attendance
    {
        $attendance = DB::transaction(function () use ($actor, $attendanceId, $reason, $request, $defaultNote): Attendance {
            $userId = Attendance::whereKey($attendanceId)->value('user_id');
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $attendance = Attendance::with(['jadwal', 'mataKuliah'])->lockForUpdate()->findOrFail($attendanceId);
            abort_unless($attendance->status === 'pending', 409, 'Hanya status pending yang bisa ditolak');
            $note = $reason ?? $defaultNote;
            $alpha = $this->alphaForStatus($attendance, 'alpha');

            $attendance->update([
                'status' => 'alpha',
                'approval_status' => 'rejected',
                'alpha_menit' => $alpha,
                'catatan' => $reason ? "Ditolak: {$reason}" : $defaultNote,
            ]);
            $this->record($attendance, $actor, 'reject', 'pending', 'alpha', $note, $request, ['alpha_menit' => $alpha]);
            $this->attendanceResultNotification($attendance, 'rejected', $reason);

            return $attendance->fresh();
        });
        $this->spDetection->evaluate($attendance->user_id, $attendance->mataKuliah->semester_id);

        return $attendance;
    }

    public function override(User $actor, int $attendanceId, string $status, string $reason, Request $request): Attendance
    {
        $attendance = DB::transaction(function () use ($actor, $attendanceId, $status, $reason, $request): Attendance {
            $userId = Attendance::whereKey($attendanceId)->value('user_id');
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $attendance = Attendance::with(['jadwal', 'mataKuliah'])->lockForUpdate()->findOrFail($attendanceId);
            $oldStatus = $attendance->status;
            abort_if($oldStatus === $status, 409, 'Status attendance sudah sesuai');
            $oldAlpha = (int) $attendance->alpha_menit;
            $alpha = $this->alphaForStatus($attendance, $status);

            $attendance->update([
                'status' => $status,
                'alpha_menit' => $alpha,
                'is_overridden' => true,
                'overridden_by' => $actor->id,
                'override_reason' => $reason,
                'override_at' => now(),
            ]);
            $this->record($attendance, $actor, 'override', $oldStatus, $status, "Override: {$reason}", $request, [
                'old_alpha_menit' => $oldAlpha,
                'alpha_menit' => $alpha,
                'alasan' => $reason,
            ]);

            return $attendance->fresh();
        });
        $this->spDetection->evaluate($attendance->user_id, $attendance->mataKuliah->semester_id);

        return $attendance;
    }

    private function alphaForStatus(Attendance $attendance, string $status): int
    {
        return match ($status) {
            'alpha' => (int) ($attendance->jadwal?->durasi_menit ?? 0),
            'hadir_terlambat' => $this->lateMinutes($attendance),
            default => 0,
        };
    }

    private function lateMinutes(Attendance $attendance): int
    {
        if (! $attendance->checkin_time || ! $attendance->jadwal?->jam_mulai) {
            return 0;
        }

        $checkin = Carbon::parse($attendance->checkin_time);
        $start = Carbon::parse($attendance->tanggal->format('Y-m-d').' '.$attendance->jadwal->jam_mulai);

        return $checkin->gt($start) ? (int) $start->diffInMinutes($checkin) : 0;
    }

    private function record(
        Attendance $attendance,
        User $actor,
        string $action,
        string $before,
        string $after,
        string $note,
        Request $request,
        array $values,
    ): void {
        AttendanceLog::create([
            'attendance_id' => $attendance->id,
            'user_id' => $actor->id,
            'action' => $action,
            'status_before' => $before,
            'status_after' => $after,
            'keterangan' => $note,
            'metadata' => $values,
        ]);
        AuditTrail::create([
            'user_id' => $actor->id,
            'action' => "{$action}_attendance",
            'model_type' => Attendance::class,
            'model_id' => $attendance->id,
            'old_values' => ['status' => $before],
            'new_values' => ['status' => $after] + $values,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    private function attendanceResultNotification(Attendance $attendance, string $action, ?string $reason = null): void
    {
        $approved = $action === 'approved';
        app(NotificationOutboxService::class)->enqueue(
            "attendance:{$attendance->id}:{$action}:{$attendance->user_id}",
            $attendance->user_id,
            'approval_result',
            $approved ? 'Kehadiran disetujui' : 'Kehadiran ditolak',
            $approved
                ? "Kehadiran Anda untuk {$attendance->mataKuliah?->nama} tanggal {$attendance->tanggal?->format('d/m/Y')} telah disetujui."
                : "Kehadiran Anda untuk {$attendance->mataKuliah?->nama} tanggal {$attendance->tanggal?->format('d/m/Y')} ditolak.".($reason ? " Alasan: {$reason}" : ''),
            ['attendance_id' => $attendance->id, 'action' => $action, 'reason' => $reason],
        );
    }
}
