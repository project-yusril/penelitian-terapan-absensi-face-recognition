<?php

namespace App\Listeners;

use App\Events\AttendanceApproved;
use App\Events\AttendancePendingCreated;
use App\Events\EnrollmentProcessed;
use App\Events\LeaveRequestProcessed;
use App\Events\SpDocumentFinalized;
use App\Models\Notification;

class SendNotificationListener
{
    /**
     * Handle pending attendance → notif ke dosen pengampu
     */
    public function handlePendingAttendance(AttendancePendingCreated $event): void
    {
        $attendance = $event->attendance;
        $mk = $attendance->mataKuliah;

        if (! $mk || ! $mk->dosen_id) {
            return;
        }

        $mahasiswa = $attendance->user;

        Notification::create([
            'user_id' => $mk->dosen_id,
            'type' => 'approval_needed',
            'title' => 'Approval kehadiran baru',
            'body' => "{$mahasiswa->nama} ({$mahasiswa->nim}) membutuhkan approval kehadiran untuk {$mk->nama}.",
            'data' => [
                'attendance_id' => $attendance->id,
                'mahasiswa_id' => $mahasiswa->id,
                'mata_kuliah_id' => $mk->id,
            ],
        ]);
    }

    /**
     * Handle approval result → notif ke mahasiswa
     */
    public function handleAttendanceApproved(AttendanceApproved $event): void
    {
        $attendance = $event->attendance;
        $action = $event->action;

        $title = $action === 'approved'
            ? 'Kehadiran disetujui'
            : 'Kehadiran ditolak';

        $body = $action === 'approved'
            ? "Kehadiran Anda untuk {$attendance->mataKuliah?->nama} tanggal {$attendance->tanggal?->format('d/m/Y')} telah disetujui."
            : "Kehadiran Anda untuk {$attendance->mataKuliah?->nama} tanggal {$attendance->tanggal?->format('d/m/Y')} ditolak.".($event->reason ? " Alasan: {$event->reason}" : '');

        Notification::create([
            'user_id' => $attendance->user_id,
            'type' => 'approval_result',
            'title' => $title,
            'body' => $body,
            'data' => [
                'attendance_id' => $attendance->id,
                'action' => $action,
                'reason' => $event->reason,
            ],
        ]);
    }

    /**
     * Handle enrollment result → notif ke mahasiswa
     */
    public function handleEnrollmentProcessed(EnrollmentProcessed $event): void
    {
        $embedding = $event->embedding;
        $action = $event->action;

        $title = $action === 'approved'
            ? 'Enrollment wajah disetujui'
            : 'Enrollment wajah ditolak';

        $body = $action === 'approved'
            ? 'Enrollment wajah Anda telah disetujui. Anda sekarang bisa melakukan absensi.'
            : 'Enrollment wajah Anda ditolak.'.($event->reason ? " Alasan: {$event->reason}. Silakan ajukan ulang." : ' Silakan ajukan ulang.');

        Notification::create([
            'user_id' => $embedding->user_id,
            'type' => 'enrollment_result',
            'title' => $title,
            'body' => $body,
            'data' => [
                'action' => $action,
                'reason' => $event->reason,
            ],
        ]);
    }

    /**
     * Handle leave request result → notif ke mahasiswa
     */
    public function handleLeaveRequestProcessed(LeaveRequestProcessed $event): void
    {
        $leave = $event->leaveRequest;
        $action = $event->action;

        $title = $action === 'approved'
            ? 'Izin/sakit disetujui'
            : 'Izin/sakit ditolak';

        $body = $action === 'approved'
            ? "Pengajuan {$leave->jenis} Anda untuk {$leave->mataKuliah?->nama} tanggal {$leave->tanggal_mulai?->format('d/m/Y')} telah disetujui."
            : "Pengajuan {$leave->jenis} Anda untuk {$leave->mataKuliah?->nama} tanggal {$leave->tanggal_mulai?->format('d/m/Y')} ditolak.".($event->reason ? " Alasan: {$event->reason}" : '');

        Notification::create([
            'user_id' => $leave->user_id,
            'type' => 'approval_result',
            'title' => $title,
            'body' => $body,
            'data' => [
                'leave_request_id' => $leave->id,
                'action' => $action,
                'reason' => $event->reason,
            ],
        ]);
    }

    /**
     * Handle SP document finalized → notif ke mahasiswa
     */
    public function handleSpDocumentFinalized(SpDocumentFinalized $event): void
    {
        $sp = $event->spRecord;

        Notification::create([
            'user_id' => $sp->user_id,
            'type' => 'sp_issued',
            'title' => "Dokumen {$sp->sp_level} telah final",
            'body' => "Surat Peringatan {$sp->sp_level} Anda telah ditandatangani dan bisa diunduh.",
            'data' => [
                'sp_record_id' => $sp->id,
                'level' => $sp->sp_level,
            ],
        ]);
    }

    /**
     * Subscribe to events
     */
    public function subscribe($events): array
    {
        return [
            AttendancePendingCreated::class => 'handlePendingAttendance',
            AttendanceApproved::class => 'handleAttendanceApproved',
            EnrollmentProcessed::class => 'handleEnrollmentProcessed',
            LeaveRequestProcessed::class => 'handleLeaveRequestProcessed',
            SpDocumentFinalized::class => 'handleSpDocumentFinalized',
        ];
    }
}
