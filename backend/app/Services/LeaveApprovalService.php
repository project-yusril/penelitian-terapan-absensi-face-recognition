<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\AuditTrail;
use App\Models\Jadwal;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LeaveApprovalService
{
    public function __construct(private SpDetectionService $spDetection) {}

    public function approve(User $actor, int $leaveId, Request $request): LeaveRequest
    {
        $leave = DB::transaction(function () use ($actor, $leaveId, $request): LeaveRequest {
            $userId = LeaveRequest::whereKey($leaveId)->value('user_id');
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $leave = LeaveRequest::with(['user', 'mataKuliah'])->lockForUpdate()->findOrFail($leaveId);
            abort_unless($leave->status === 'pending', 409, 'Request ini sudah diproses');

            $leave->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $count = $this->materializeRange($leave, $actor->id);
            AuditTrail::create([
                'user_id' => $actor->id,
                'action' => 'leave_request_approved',
                'model_type' => LeaveRequest::class,
                'model_id' => $leave->id,
                'old_values' => ['status' => 'pending'],
                'new_values' => ['status' => 'approved', 'attendance_count' => $count],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            $this->resultNotification($leave, 'approved');

            return $leave->fresh();
        });
        $this->spDetection->evaluate($leave->user_id, $leave->mataKuliah->semester_id);

        return $leave;
    }

    public function reject(User $actor, int $leaveId, string $reason, Request $request): LeaveRequest
    {
        return DB::transaction(function () use ($actor, $leaveId, $reason, $request): LeaveRequest {
            $userId = LeaveRequest::whereKey($leaveId)->value('user_id');
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $leave = LeaveRequest::with(['user', 'mataKuliah'])->lockForUpdate()->findOrFail($leaveId);
            abort_unless($leave->status === 'pending', 409, 'Request ini sudah diproses');

            $leave->update([
                'status' => 'rejected',
                'rejected_reason' => $reason,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);
            AuditTrail::create([
                'user_id' => $actor->id,
                'action' => 'leave_request_rejected',
                'model_type' => LeaveRequest::class,
                'model_id' => $leave->id,
                'old_values' => ['status' => 'pending'],
                'new_values' => ['status' => 'rejected', 'reason' => $reason],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            $this->resultNotification($leave, 'rejected', $reason);

            return $leave->fresh();
        });
    }

    public function materializeLegacy(LeaveRequest $leave, Jadwal $jadwal, Carbon $date): Attendance
    {
        $attendance = DB::transaction(function () use ($leave, $jadwal, $date): Attendance {
            User::whereKey($leave->user_id)->lockForUpdate()->firstOrFail();
            $leave = LeaveRequest::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $attendance = $this->materialize($leave, $jadwal, $date, $leave->approved_by ?? $leave->user_id);

            return $attendance;
        });
        $this->spDetection->evaluate($leave->user_id, $leave->mataKuliah()->value('semester_id'));

        return $attendance;
    }

    private function materializeRange(LeaveRequest $leave, int $actorId): int
    {
        $jadwals = Jadwal::where('mata_kuliah_id', $leave->mata_kuliah_id)
            ->where('status', 'aktif')
            ->lockForUpdate()
            ->get()
            ->groupBy('hari');
        $count = 0;

        for ($date = $leave->tanggal_mulai->copy(); $date->lte($leave->tanggal_selesai); $date->addDay()) {
            foreach ($jadwals->get($date->locale('id')->isoFormat('dddd'), collect()) as $jadwal) {
                $this->materialize($leave, $jadwal, $date, $actorId);
                $count++;
            }
        }

        return $count;
    }

    private function materialize(LeaveRequest $leave, Jadwal $jadwal, Carbon $date, int $actorId): Attendance
    {
        $attendance = Attendance::where('user_id', $leave->user_id)
            ->where('jadwal_id', $jadwal->id)
            ->whereDate('tanggal', $date)
            ->lockForUpdate()
            ->first();
        $before = $attendance?->status;

        if (! $attendance) {
            $attendance = Attendance::create([
                'user_id' => $leave->user_id,
                'jadwal_id' => $jadwal->id,
                'mata_kuliah_id' => $leave->mata_kuliah_id,
                'pertemuan_ke' => Attendance::where('jadwal_id', $jadwal->id)
                    ->whereDate('tanggal', '<', $date)->distinct('tanggal')->count() + 1,
                'tanggal' => $date->toDateString(),
                'status' => $leave->jenis,
                'alpha_menit' => 0,
            ]);
        } else {
            $attendance->update(['status' => $leave->jenis, 'alpha_menit' => 0]);
        }

        if ($before === $leave->jenis) {
            return $attendance;
        }

        AttendanceLog::create([
            'attendance_id' => $attendance->id,
            'user_id' => $actorId,
            'action' => 'leave_approved',
            'status_before' => $before,
            'status_after' => $leave->jenis,
            'keterangan' => "Leave request #{$leave->id} disetujui",
            'metadata' => ['leave_request_id' => $leave->id],
        ]);

        return $attendance;
    }

    private function resultNotification(LeaveRequest $leave, string $action, ?string $reason = null): void
    {
        $approved = $action === 'approved';
        app(NotificationOutboxService::class)->enqueue(
            "leave:{$leave->id}:{$action}:{$leave->user_id}",
            $leave->user_id,
            'approval_result',
            $approved ? 'Izin/sakit disetujui' : 'Izin/sakit ditolak',
            $approved
                ? "Pengajuan {$leave->jenis} Anda untuk {$leave->mataKuliah?->nama} tanggal {$leave->tanggal_mulai?->format('d/m/Y')} telah disetujui."
                : "Pengajuan {$leave->jenis} Anda untuk {$leave->mataKuliah?->nama} tanggal {$leave->tanggal_mulai?->format('d/m/Y')} ditolak.".($reason ? " Alasan: {$reason}" : ''),
            ['leave_request_id' => $leave->id, 'action' => $action, 'reason' => $reason],
        );
    }
}
