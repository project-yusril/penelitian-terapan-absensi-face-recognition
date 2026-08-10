<?php

namespace Tests\Feature;

use App\Models\AlphaAccumulation;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\AuditTrail;
use App\Models\Geofence;
use App\Models\Jadwal;
use App\Models\LeaveRequest;
use App\Models\MataKuliah;
use App\Models\Notification;
use App\Models\NotificationOutbox;
use App\Models\Prodi;
use App\Models\ProdiSetting;
use App\Models\Role;
use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class AttendanceWorkflowRegressionTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private User $student;

    private User $dosen;

    private User $kaprodi;

    private MataKuliah $course;

    private Semester $semester;

    private Geofence $geofence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();
        $prodi = Prodi::where('kode', 'TI')->firstOrFail();
        $this->student = $this->user('mahasiswa', $prodi);
        $this->dosen = $this->user('dosen', $prodi);
        $this->kaprodi = $this->user('kaprodi', $prodi);
        $year = TahunAjaran::create([
            'kode' => '2026-WF', 'nama' => '2026/2027', 'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $this->semester = Semester::create([
            'tahun_ajaran_id' => $year->id, 'kode' => '2026-WF-G', 'nama' => 'Ganjil',
            'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $this->course = MataKuliah::create([
            'kode_mk' => 'WF101', 'nama' => 'Workflow', 'sks' => 2, 'semester_id' => $this->semester->id,
            'prodi_id' => $prodi->id, 'dosen_id' => $this->dosen->id, 'status' => 'aktif',
        ]);
        $this->course->mahasiswas()->attach($this->student->id);
        $this->geofence = Geofence::create([
            'nama' => 'Lab', 'latitude' => -6.2, 'longitude' => 106.8, 'radius' => 50,
            'prodi_id' => $prodi->id, 'status' => 'aktif',
        ]);
        ProdiSetting::create(['prodi_id' => $prodi->id, 'toleransi_masuk_menit' => 15]);
    }

    public function test_leave_approval_materializes_every_schedule_and_recalculates_existing_alpha(): void
    {
        Carbon::setTestNow('2026-07-13 08:00:00');
        $mondayMorning = $this->schedule('Senin', '08:00', '10:00', 120);
        $mondayAfternoon = $this->schedule('Senin', '13:00', '14:00', 60);
        $tuesday = $this->schedule('Selasa', '08:00', '09:00', 60);
        $existing = $this->attendance($mondayMorning, '2026-07-13', 'alpha', 120);
        $leave = LeaveRequest::create([
            'user_id' => $this->student->id, 'mata_kuliah_id' => $this->course->id, 'jenis' => 'sakit',
            'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-07-14', 'status' => 'pending',
        ]);

        $this->actingAs($this->kaprodi)
            ->putJson("/api/kaprodi/leave-requests/{$leave->id}/approve")
            ->assertOk();

        $this->assertSame('sakit', $existing->fresh()->status);
        $this->assertSame(0, $existing->fresh()->alpha_menit);
        foreach ([[$mondayAfternoon, '2026-07-13'], [$tuesday, '2026-07-14']] as [$schedule, $date]) {
            $this->assertDatabaseHas('attendances', [
                'user_id' => $this->student->id, 'jadwal_id' => $schedule->id,
                'tanggal' => $date, 'status' => 'sakit', 'alpha_menit' => 0,
            ]);
        }
        $this->assertSame(3, Attendance::where('user_id', $this->student->id)->count());
        $this->assertSame(0, AlphaAccumulation::where('user_id', $this->student->id)->value('total_alpha_menit'));
        $this->assertDatabaseHas('audit_trails', [
            'user_id' => $this->kaprodi->id, 'action' => 'leave_request_approved',
            'model_type' => LeaveRequest::class, 'model_id' => $leave->id,
        ]);
    }

    public function test_mark_absent_materializes_approved_legacy_leave_instead_of_skipping(): void
    {
        Carbon::setTestNow('2026-07-13 23:00:00');
        $schedule = $this->schedule('Senin', '08:00', '10:00', 120);
        LeaveRequest::create([
            'user_id' => $this->student->id, 'mata_kuliah_id' => $this->course->id, 'jenis' => 'izin',
            'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-07-13', 'status' => 'approved',
            'approved_by' => $this->kaprodi->id, 'approved_at' => now(),
        ]);

        $this->artisan('attendance:mark-absent')->assertSuccessful();

        $this->assertDatabaseHas('attendances', [
            'user_id' => $this->student->id, 'jadwal_id' => $schedule->id,
            'tanggal' => '2026-07-13', 'status' => 'izin', 'alpha_menit' => 0,
        ]);
    }

    public function test_kaprodi_override_uses_canonical_alpha_and_writes_audit_and_accumulation(): void
    {
        $schedule = $this->schedule('Senin', '08:00', '10:00', 120);
        $attendance = $this->attendance($schedule, '2026-07-13', 'hadir', 0);

        $this->actingAs($this->kaprodi)->putJson("/api/kaprodi/attendance/{$attendance->id}/override", [
            'status' => 'alpha', 'alasan' => 'Koreksi bukti',
        ])->assertOk()->assertJsonPath('data.new_status', 'alpha');

        $this->assertSame(120, $attendance->fresh()->alpha_menit);
        $this->assertSame(120, AlphaAccumulation::where('user_id', $this->student->id)->value('total_alpha_menit'));
        $this->assertDatabaseHas('attendance_logs', ['attendance_id' => $attendance->id, 'action' => 'override']);
        $this->assertDatabaseHas('audit_trails', ['model_id' => $attendance->id, 'action' => 'override_attendance']);

        $this->actingAs($this->kaprodi)->putJson("/api/kaprodi/attendance/{$attendance->id}/override", [
            'status' => 'izin', 'alasan' => 'Dokumen valid',
        ])->assertOk();
        $this->assertSame(0, $attendance->fresh()->alpha_menit);
        $this->assertSame(0, AlphaAccumulation::where('user_id', $this->student->id)->value('total_alpha_menit'));
    }

    public function test_api_approval_applies_within_and_after_prodi_tolerance(): void
    {
        $schedule = $this->schedule('Senin', '08:00', '10:00', 120);
        $within = $this->attendance($schedule, '2026-07-13', 'pending', 120, '2026-07-13 08:15:00');
        $late = $this->attendance($schedule, '2026-07-20', 'pending', 120, '2026-07-20 08:16:00');

        $this->actingAs($this->dosen)->putJson("/api/dosen/attendance/{$within->id}/approve")
            ->assertOk()->assertJsonPath('data.new_status', 'hadir');
        $this->actingAs($this->dosen)->putJson("/api/dosen/attendance/{$late->id}/approve")
            ->assertOk()->assertJsonPath('data.new_status', 'hadir_terlambat');

        $this->assertSame(0, $within->fresh()->alpha_menit);
        $this->assertSame(16, $late->fresh()->alpha_menit);
    }

    public function test_pending_attendance_transition_retry_is_conflict_with_one_log_audit_and_outbox(): void
    {
        $schedule = $this->schedule('Senin', '08:00', '10:00', 120);
        $attendance = $this->attendance($schedule, '2026-07-13', 'pending', 120, '2026-07-13 08:15:00');

        $this->actingAs($this->dosen)->putJson("/api/dosen/attendance/{$attendance->id}/approve")->assertOk();
        $this->actingAs($this->dosen)->putJson("/api/dosen/attendance/{$attendance->id}/reject", ['alasan' => 'stale'])
            ->assertConflict();

        $this->assertSame(1, AttendanceLog::where('attendance_id', $attendance->id)->where('action', 'approve')->count());
        $this->assertSame(1, AuditTrail::where('model_type', Attendance::class)->where('model_id', $attendance->id)->count());
        $this->assertSame(1, NotificationOutbox::where('idempotency_key', "attendance:{$attendance->id}:approved:{$this->student->id}")->count());
    }

    public function test_web_and_api_approval_share_the_same_tolerance_behavior(): void
    {
        $schedule = $this->schedule('Senin', '08:00', '10:00', 120);
        $api = $this->attendance($schedule, '2026-07-13', 'pending', 120, '2026-07-13 08:10:00');
        $web = $this->attendance($schedule, '2026-07-20', 'pending', 120, '2026-07-20 08:10:00');

        $this->actingAs($this->dosen)->putJson("/api/dosen/attendance/{$api->id}/approve")->assertOk();
        $this->actingAs($this->dosen)->from('/dosen/attendance')
            ->put("/dosen/attendance/{$web->id}/approve")
            ->assertRedirect('/dosen/attendance');

        $this->assertSame($api->fresh()->status, $web->fresh()->status);
        $this->assertSame($api->fresh()->alpha_menit, $web->fresh()->alpha_menit);
        $this->assertDatabaseCount('attendance_logs', 2);
        $this->assertDatabaseCount('audit_trails', 2);
    }

    public function test_attendance_workflow_recalculates_the_courses_non_active_semester(): void
    {
        $this->semester->update(['status' => 'nonaktif']);
        $this->createActiveSemester('2026-WF-ACTIVE');
        $schedule = $this->schedule('Senin', '08:00', '10:00', 120);
        $attendance = $this->attendance($schedule, '2026-07-13', 'hadir', 0);

        $this->actingAs($this->kaprodi)->putJson("/api/kaprodi/attendance/{$attendance->id}/override", [
            'status' => 'alpha', 'alasan' => 'Koreksi semester lama',
        ])->assertOk();

        $this->assertDatabaseHas('alpha_accumulations', [
            'user_id' => $this->student->id,
            'semester_id' => $this->semester->id,
            'total_alpha_menit' => 120,
        ]);
    }

    public function test_leave_approval_recalculates_the_courses_non_active_semester(): void
    {
        Carbon::setTestNow('2026-07-13 08:00:00');
        $this->semester->update(['status' => 'nonaktif']);
        $activeSemester = $this->createActiveSemester('2026-WF-ACTIVE-LEAVE');
        $schedule = $this->schedule('Senin', '08:00', '10:00', 120);
        $this->attendance($schedule, '2026-07-13', 'alpha', 120);
        $leave = LeaveRequest::create([
            'user_id' => $this->student->id, 'mata_kuliah_id' => $this->course->id, 'jenis' => 'izin',
            'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-07-13', 'status' => 'pending',
        ]);

        $this->actingAs($this->kaprodi)
            ->putJson("/api/kaprodi/leave-requests/{$leave->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('alpha_accumulations', [
            'user_id' => $this->student->id,
            'semester_id' => $this->semester->id,
            'total_alpha_menit' => 0,
        ]);
        $this->assertDatabaseMissing('alpha_accumulations', [
            'user_id' => $this->student->id,
            'semester_id' => $activeSemester->id,
        ]);
    }

    public function test_leave_approve_and_reject_are_strict_one_winner_with_one_audit_and_outbox(): void
    {
        Carbon::setTestNow('2026-07-13 08:00:00');
        $this->schedule('Senin', '08:00', '10:00', 120);
        $leave = LeaveRequest::create([
            'user_id' => $this->student->id, 'mata_kuliah_id' => $this->course->id, 'jenis' => 'izin',
            'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-07-13', 'status' => 'pending',
        ]);

        $this->actingAs($this->kaprodi)->putJson("/api/kaprodi/leave-requests/{$leave->id}/approve")->assertOk();
        $this->actingAs($this->kaprodi)->putJson("/api/kaprodi/leave-requests/{$leave->id}/reject", ['alasan' => 'stale'])
            ->assertConflict();

        $this->assertSame('approved', $leave->fresh()->status);
        $this->assertSame(1, AuditTrail::where('model_type', LeaveRequest::class)->where('model_id', $leave->id)->count());
        $this->assertSame(1, NotificationOutbox::where('idempotency_key', "leave:{$leave->id}:approved:{$this->student->id}")->count());
        $this->artisan('notifications:process-outbox')->assertSuccessful();
        $this->artisan('notifications:process-outbox')->assertSuccessful();
        $this->assertSame(1, Notification::where('idempotency_key', "leave:{$leave->id}:approved:{$this->student->id}")->count());
    }

    public function test_mark_absent_is_idempotent_across_repeated_scheduler_runs(): void
    {
        Carbon::setTestNow('2026-07-13 23:00:00');
        $schedule = $this->schedule('Senin', '08:00', '10:00', 120);

        $this->artisan('attendance:mark-absent')->assertSuccessful();
        $this->artisan('attendance:mark-absent')->assertSuccessful();

        $this->assertSame(1, Attendance::where('user_id', $this->student->id)->where('jadwal_id', $schedule->id)
            ->whereDate('tanggal', '2026-07-13')->count());
        $this->assertSame(120, AlphaAccumulation::where('user_id', $this->student->id)->value('total_alpha_menit'));
    }

    public function test_auto_close_uses_prodi_tolerance_is_idempotent_and_preserves_manual_checkout(): void
    {
        Carbon::setTestNow('2026-07-13 10:20:00');
        ProdiSetting::where('prodi_id', $this->student->prodi_id)->update(['toleransi_pulang_menit' => 30]);
        $schedule = $this->schedule('Senin', '08:00', '10:00', 120);
        $open = $this->attendance($schedule, '2026-07-13', 'hadir', 0, '2026-07-13 08:00:00');

        $this->artisan('attendance:auto-close')->assertSuccessful();
        $this->assertNull($open->fresh()->checkout_time);

        $manualSchedule = $this->schedule('Senin', '08:00', '10:00', 120);
        $manual = $this->attendance($manualSchedule, '2026-07-13', 'hadir', 0, '2026-07-13 08:00:00');
        $manual->update(['checkout_time' => '2026-07-13 09:55:00']);
        Carbon::setTestNow('2026-07-13 10:31:00');
        $this->artisan('attendance:auto-close')->assertSuccessful();
        $this->artisan('attendance:auto-close')->assertSuccessful();

        $this->assertTrue($open->fresh()->checkout_time->equalTo(Carbon::parse('2026-07-13 10:00:00')));
        $this->assertTrue($open->fresh()->is_auto_closed);
        $this->assertTrue($manual->fresh()->checkout_time->equalTo(Carbon::parse('2026-07-13 09:55:00')));
        $this->assertFalse($manual->fresh()->is_auto_closed);
    }

    private function user(string $role, Prodi $prodi): User
    {
        $user = User::factory()->create(['prodi_id' => $prodi->id, 'status' => 'aktif']);
        $user->roles()->attach(Role::where('name', $role)->value('id'));

        return $user;
    }

    private function schedule(string $day, string $start, string $end, int $duration): Jadwal
    {
        return Jadwal::create([
            'mata_kuliah_id' => $this->course->id, 'geofence_id' => $this->geofence->id,
            'hari' => $day, 'jam_mulai' => $start, 'jam_selesai' => $end,
            'durasi_menit' => $duration, 'status' => 'aktif',
        ]);
    }

    private function attendance(Jadwal $schedule, string $date, string $status, int $alpha, ?string $checkin = null): Attendance
    {
        return Attendance::create([
            'user_id' => $this->student->id, 'jadwal_id' => $schedule->id,
            'mata_kuliah_id' => $this->course->id, 'tanggal' => $date,
            'status' => $status, 'alpha_menit' => $alpha, 'checkin_time' => $checkin,
        ]);
    }

    private function createActiveSemester(string $code): Semester
    {
        return Semester::create([
            'tahun_ajaran_id' => $this->semester->tahun_ajaran_id,
            'kode' => $code,
            'nama' => 'Genap',
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31',
            'status' => 'aktif',
        ]);
    }
}
