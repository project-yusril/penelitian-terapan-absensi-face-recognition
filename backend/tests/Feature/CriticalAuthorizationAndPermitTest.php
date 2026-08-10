<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\AttendancePermit;
use App\Models\AuditTrail;
use App\Models\FaceEmbedding;
use App\Models\Geofence;
use App\Models\Jadwal;
use App\Models\MataKuliah;
use App\Models\Prodi;
use App\Models\ProdiSetting;
use App\Models\Role;
use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class CriticalAuthorizationAndPermitTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();
    }

    public function test_admin_prodi_cannot_create_super_admin(): void
    {
        [$actor, $prodi] = $this->userWithRole('admin_prodi', 'TI');

        $this->actingAs($actor)->postJson('/api/admin/users', [
            'nama' => 'Attacker Admin', 'email' => 'attacker@test.com',
            'password' => 'password12345', 'prodi_id' => $prodi->id,
            'roles' => ['super_admin'],
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'attacker@test.com']);
    }

    public function test_web_admin_prodi_cannot_create_super_admin(): void
    {
        [$actor, $prodi] = $this->userWithRole('admin_prodi', 'TI');
        $superAdminRole = Role::where('name', 'super_admin')->firstOrFail();

        $this->actingAs($actor)->post('/users', [
            'nama' => 'Web Escalation', 'email' => 'web-escalation@test.com',
            'password' => 'password123', 'role_id' => $superAdminRole->id,
            'prodi_id' => $prodi->id, 'status' => 'aktif',
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'web-escalation@test.com']);
    }

    public function test_admin_prodi_cannot_manage_user_from_another_prodi(): void
    {
        [$actor] = $this->userWithRole('admin_prodi', 'TI');
        [$target] = $this->userWithRole('mahasiswa', 'TE');

        $this->actingAs($actor)->putJson("/api/admin/users/{$target->id}/toggle-status")
            ->assertForbidden();
        $this->actingAs($actor)->deleteJson("/api/admin/users/{$target->id}")
            ->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'status' => 'aktif']);
    }

    public function test_admin_jurusan_cannot_manage_user_from_another_prodi(): void
    {
        [$actor] = $this->userWithRole('admin_jurusan', 'TI');
        [$target] = $this->userWithRole('mahasiswa', 'TE');

        $this->actingAs($actor)->putJson("/api/admin/users/{$target->id}/toggle-status")
            ->assertForbidden();
        $this->actingAs($actor)->deleteJson("/api/admin/users/{$target->id}")
            ->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'status' => 'aktif']);
    }

    public function test_admin_prodi_cannot_remove_prodi_from_managed_user(): void
    {
        [$actor, $prodi] = $this->userWithRole('admin_prodi', 'TI');
        [$target] = $this->userWithRole('mahasiswa', 'TI');

        $this->actingAs($actor)->putJson("/api/admin/users/{$target->id}", [
            'prodi_id' => null,
        ])->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'prodi_id' => $prodi->id]);
    }

    public function test_kaprodi_cannot_approve_enrollment_from_another_prodi(): void
    {
        [$kaprodi] = $this->userWithRole('kaprodi', 'TI');
        [$student] = $this->userWithRole('mahasiswa', 'TE', ['enrollment_status' => 'pending']);

        $this->actingAs($kaprodi)->putJson("/api/kaprodi/enrollments/{$student->id}/approve")
            ->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $student->id, 'enrollment_status' => 'pending']);
    }

    public function test_web_kaprodi_cannot_approve_enrollment_from_another_prodi(): void
    {
        [$kaprodi] = $this->userWithRole('kaprodi', 'TI');
        [$student] = $this->userWithRole('mahasiswa', 'TE', ['enrollment_status' => 'pending']);

        $this->actingAs($kaprodi)->put("/enrollments/{$student->id}/approve")
            ->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $student->id, 'enrollment_status' => 'pending']);
    }

    public function test_attendance_without_permit_is_rejected(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();

        $this->actingAs($student)->postJson('/api/mahasiswa/attendance/check-in',
            $this->evidence($jadwal))->assertUnprocessable();
    }

    public function test_permit_is_bound_to_user_schedule_action_and_is_single_use(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        [$other] = $this->userWithRole('mahasiswa', 'TE', ['enrollment_status' => 'approved']);
        $uuid = fake()->uuid();

        $response = $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', [
            'jadwal_id' => $jadwal->id, 'action' => 'check_in', 'client_uuid' => $uuid,
        ])->assertCreated();
        $permit = $response->json('data');

        $wrongUser = $this->evidence($jadwal, $permit, $uuid);
        $this->actingAs($other)->postJson('/api/mahasiswa/attendance/check-in', $wrongUser)
            ->assertForbidden();

        $payload = $this->evidence($jadwal, $permit, $uuid);
        $this->actingAs($student)->postJson('/api/mahasiswa/attendance/check-in', $payload)
            ->assertCreated();
        $this->actingAs($student)->postJson('/api/mahasiswa/attendance/check-in', $payload)
            ->assertCreated()
            ->assertJsonPath('data.attendance.id', Attendance::where('user_id', $student->id)->value('id'));
    }

    public function test_permit_issue_retry_returns_same_encrypted_token_and_challenge(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        $uuid = fake()->uuid();
        $payload = ['jadwal_id' => $jadwal->id, 'action' => 'check_in', 'client_uuid' => $uuid];

        $first = $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', $payload)
            ->assertCreated()->json('data');
        $second = $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', $payload)
            ->assertCreated()->json('data');

        $this->assertSame($first['permit_token'], $second['permit_token']);
        $this->assertSame($first['liveness_challenge'], $second['liveness_challenge']);
        $this->assertSame(1, AttendancePermit::where('user_id', $student->id)->where('client_uuid', $uuid)->count());
        $raw = DB::table('attendance_permits')->where('client_uuid', $uuid)->first();
        $this->assertStringNotContainsString($first['permit_token'], $raw->permit_token);
        $this->assertStringNotContainsString($first['liveness_challenge'], $raw->encrypted_challenge);
        $this->assertNull($raw->liveness_challenge);
    }

    public function test_permit_idempotency_key_rejects_different_binding_and_consumed_retry(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        $uuid = fake()->uuid();
        $permit = $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', [
            'jadwal_id' => $jadwal->id, 'action' => 'check_in', 'client_uuid' => $uuid,
        ])->assertCreated()->json('data');
        $other = $jadwal->replicate();
        $other->ruangan = 'Other';
        $other->save();

        $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', [
            'jadwal_id' => $other->id, 'action' => 'check_in', 'client_uuid' => $uuid,
        ])->assertConflict();

        $this->actingAs($student)->postJson('/api/mahasiswa/attendance/check-in', $this->evidence($jadwal, $permit, $uuid))
            ->assertCreated();
        $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', [
            'jadwal_id' => $jadwal->id, 'action' => 'check_in', 'client_uuid' => $uuid,
        ])->assertConflict();
    }

    public function test_online_checkin_same_occurrence_with_different_uuid_is_explicit_conflict(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        [$first] = $this->offlineItem($student, $jadwal, 'check_in');
        $this->actingAs($student)->postJson('/api/mahasiswa/attendance/check-in', $first)->assertCreated();

        $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', [
            'jadwal_id' => $jadwal->id, 'action' => 'check_in', 'client_uuid' => fake()->uuid(),
        ])->assertConflict();
        $this->assertSame(1, Attendance::where('user_id', $student->id)->where('jadwal_id', $jadwal->id)->count());
        $this->assertSame(1, AttendanceLog::where('action', 'checkin_success')->count());
        $this->assertSame(1, AuditTrail::where('action', 'checkin_attendance')->count());
    }

    public function test_permit_rejects_wrong_schedule_action_and_client_uuid(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        $uuid = fake()->uuid();
        $permit = $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', [
            'jadwal_id' => $jadwal->id, 'action' => 'check_in', 'client_uuid' => $uuid,
        ])->assertCreated()->json('data');

        $wrongUuid = $this->evidence($jadwal, $permit, fake()->uuid());
        $this->actingAs($student)->postJson('/api/mahasiswa/attendance/check-in', $wrongUuid)
            ->assertForbidden();

        $wrongAction = $this->evidence($jadwal, $permit, $uuid) + ['attendance_id' => 999999];
        $this->actingAs($student)->postJson('/api/mahasiswa/attendance/check-out', $wrongAction)
            ->assertUnprocessable();

        $otherJadwal = $jadwal->replicate();
        $otherJadwal->ruangan = 'Lab 2';
        $otherJadwal->save();
        $wrongSchedule = $this->evidence($otherJadwal, $permit, $uuid);
        $this->actingAs($student)->postJson('/api/mahasiswa/attendance/check-in', $wrongSchedule)
            ->assertForbidden();
    }

    public function test_offline_permit_rejects_historical_and_future_capture(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        $uuid = fake()->uuid();
        $permit = $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', [
            'jadwal_id' => $jadwal->id, 'action' => 'check_in', 'client_uuid' => $uuid,
        ])->assertCreated()->json('data');

        foreach ([now()->subDay(), now()->addHour()] as $timestamp) {
            $item = $this->evidence($jadwal, $permit, $uuid) + [
                'type' => 'check_in', 'timestamp' => $timestamp->toIso8601String(),
            ];
            $this->actingAs($student)->postJson('/api/mahasiswa/attendance/sync-offline', [
                'attendances' => [$item],
            ])->assertOk()->assertJsonPath('data.results.0.status', 'failed');
        }
    }

    public function test_permit_window_accepts_boundaries_and_rejects_outside_them(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();

        foreach ([
            ['2026-07-20 08:45:00', 201],
            ['2026-07-20 11:15:00', 201],
            ['2026-07-20 08:44:59', 422],
            ['2026-07-20 11:15:01', 422],
        ] as [$serverTime, $expectedStatus]) {
            Carbon::setTestNow($serverTime);
            $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', [
                'jadwal_id' => $jadwal->id,
                'action' => 'check_in',
                'client_uuid' => fake()->uuid(),
            ])->assertStatus($expectedStatus);
        }
    }

    public function test_permit_requires_all_academic_resources_to_be_active(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();

        foreach (['jadwal', 'mata_kuliah', 'geofence', 'semester', 'tahun_ajaran'] as $resource) {
            $model = match ($resource) {
                'jadwal' => $jadwal,
                'mata_kuliah' => $jadwal->mataKuliah,
                'geofence' => $jadwal->geofence,
                'semester' => $jadwal->mataKuliah->semester,
                'tahun_ajaran' => $jadwal->mataKuliah->semester->tahunAjaran,
            };
            $model->update(['status' => 'nonaktif']);

            $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', [
                'jadwal_id' => $jadwal->id,
                'action' => 'check_in',
                'client_uuid' => fake()->uuid(),
            ])->assertUnprocessable();
            $model->update(['status' => 'aktif']);
        }
    }

    public function test_permit_requires_enrollment_and_current_academic_date(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        $student->mataKuliahs()->detach($jadwal->mata_kuliah_id);

        $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', [
            'jadwal_id' => $jadwal->id,
            'action' => 'check_in',
            'client_uuid' => fake()->uuid(),
        ])->assertForbidden();

        $student->mataKuliahs()->attach($jadwal->mata_kuliah_id);
        $jadwal->mataKuliah->semester->update(['tanggal_selesai' => '2026-07-19']);
        $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', [
            'jadwal_id' => $jadwal->id,
            'action' => 'check_in',
            'client_uuid' => fake()->uuid(),
        ])->assertUnprocessable();
    }

    public function test_offline_sync_accepts_mixed_checkin_checkout_batch(): void
    {
        [$student, , $checkoutSchedule] = $this->attendanceFixture();
        $attendance = Attendance::create([
            'user_id' => $student->id,
            'jadwal_id' => $checkoutSchedule->id,
            'mata_kuliah_id' => $checkoutSchedule->mata_kuliah_id,
            'pertemuan_ke' => 1,
            'tanggal' => now()->toDateString(),
            'checkin_time' => now()->subHour(),
            'status' => 'hadir',
        ]);

        $checkinCourse = $checkoutSchedule->mataKuliah->replicate();
        $checkinCourse->kode_mk = 'SEC102';
        $checkinCourse->save();
        $student->mataKuliahs()->attach($checkinCourse->id);
        $checkinSchedule = $checkoutSchedule->replicate();
        $checkinSchedule->mata_kuliah_id = $checkinCourse->id;
        $checkinSchedule->ruangan = 'Lab 2';
        $checkinSchedule->save();

        $checkinUuid = fake()->uuid();
        $checkoutUuid = fake()->uuid();
        $checkinPermit = $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', [
            'jadwal_id' => $checkinSchedule->id,
            'action' => 'check_in',
            'client_uuid' => $checkinUuid,
        ])->assertCreated()->json('data');
        $checkoutPermit = $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', [
            'jadwal_id' => $checkoutSchedule->id,
            'attendance_id' => $attendance->id,
            'action' => 'check_out',
            'client_uuid' => $checkoutUuid,
        ])->assertCreated()->json('data');

        $checkin = $this->evidence($checkinSchedule, $checkinPermit, $checkinUuid) + [
            'type' => 'check_in',
            'timestamp' => now()->toIso8601String(),
        ];
        $checkout = $this->evidence($checkoutSchedule, $checkoutPermit, $checkoutUuid) + [
            'attendance_id' => $attendance->id,
            'type' => 'check_out',
            'timestamp' => now()->toIso8601String(),
        ];

        $this->actingAs($student)->postJson('/api/mahasiswa/attendance/sync-offline', [
            'attendances' => [$checkin, $checkout],
        ])->assertOk()
            ->assertJsonPath('data.success', 2)
            ->assertJsonPath('data.failed', 0)
            ->assertJsonPath('data.results.0.client_uuid', $checkinUuid)
            ->assertJsonPath('data.results.1.client_uuid', $checkoutUuid);
    }

    public function test_offline_checkin_retry_returns_duplicate_without_another_record_or_log(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        [$item, $uuid] = $this->offlineItem($student, $jadwal, 'check_in');

        $firstId = $this->sync($student, [$item])
            ->assertJsonPath('data.results.0.status', 'success')
            ->json('data.results.0.attendance_id');

        $this->sync($student, [$item])
            ->assertJsonPath('data.success', 1)
            ->assertJsonPath('data.failed', 0)
            ->assertJsonPath('data.results.0.status', 'duplicate')
            ->assertJsonPath('data.results.0.attendance_id', $firstId);

        $this->assertSame(1, Attendance::where('user_id', $student->id)->where('client_uuid', $uuid)->count());
        $this->assertSame(1, AttendanceLog::where('attendance_id', $firstId)->where('action', 'offline_checkin')->count());
        $this->assertSame(1, AuditTrail::where('model_id', $firstId)->where('action', 'offline_checkin_attendance')->count());
    }

    public function test_offline_checkout_retry_uses_same_attendance_and_does_not_duplicate_logs(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        $attendance = $this->checkedInAttendance($student, $jadwal);
        [$item, $uuid] = $this->offlineItem($student, $jadwal, 'check_out', $attendance);

        $this->sync($student, [$item])->assertJsonPath('data.results.0.status', 'success');
        $checkoutTime = $attendance->fresh()->checkout_time;

        $this->sync($student, [$item])
            ->assertJsonPath('data.success', 1)
            ->assertJsonPath('data.results.0.status', 'duplicate')
            ->assertJsonPath('data.results.0.attendance_id', $attendance->id);

        $this->assertTrue($checkoutTime->equalTo($attendance->fresh()->checkout_time));
        $this->assertSame($uuid, $attendance->fresh()->checkout_client_uuid);
        $this->assertSame(1, AttendanceLog::where('attendance_id', $attendance->id)->where('action', 'offline_checkout')->count());
        $this->assertSame(1, AuditTrail::where('model_id', $attendance->id)->where('action', 'offline_checkout_attendance')->count());
    }

    public function test_stale_offline_checkout_permit_cannot_overwrite_first_checkout(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        $attendance = $this->checkedInAttendance($student, $jadwal);
        [$first, $firstUuid] = $this->offlineItem($student, $jadwal, 'check_out', $attendance);
        [$stale] = $this->offlineItem($student, $jadwal, 'check_out', $attendance);

        $this->sync($student, [$first])->assertJsonPath('data.results.0.status', 'success');
        $this->sync($student, [$stale])
            ->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.reason', 'Attendance sudah di-check-out dengan permit lain');

        $this->assertSame($firstUuid, $attendance->fresh()->checkout_client_uuid);
        $this->assertSame(1, AttendanceLog::where('attendance_id', $attendance->id)->where('action', 'offline_checkout')->count());
    }

    public function test_stale_online_checkout_permit_cannot_overwrite_first_checkout(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        $attendance = $this->checkedInAttendance($student, $jadwal);
        [$first, $firstUuid] = $this->onlineCheckoutItem($student, $jadwal, $attendance);
        [$stale] = $this->onlineCheckoutItem($student, $jadwal, $attendance);

        $this->actingAs($student)->postJson('/api/mahasiswa/attendance/check-out', $first)->assertOk();
        $this->actingAs($student)->postJson('/api/mahasiswa/attendance/check-out', $stale)->assertConflict();

        $this->assertSame($firstUuid, $attendance->fresh()->checkout_client_uuid);
        $this->assertSame(1, AttendanceLog::where('attendance_id', $attendance->id)->where('action', 'checkout_success')->count());
    }

    public function test_duplicate_item_in_same_batch_counts_as_success(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        [$item] = $this->offlineItem($student, $jadwal, 'check_in');

        $this->sync($student, [$item, $item])
            ->assertJsonPath('data.success', 2)
            ->assertJsonPath('data.failed', 0)
            ->assertJsonPath('data.results.0.status', 'success')
            ->assertJsonPath('data.results.1.status', 'duplicate');
    }

    public function test_consumed_permit_replay_after_expiry_returns_duplicate(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        [$item] = $this->offlineItem($student, $jadwal, 'check_in');
        $attendanceId = $this->sync($student, [$item])->json('data.results.0.attendance_id');
        AttendancePermit::where('client_uuid', $item['client_uuid'])->update(['sync_expires_at' => now()->subMinute()]);

        $this->sync($student, [$item])
            ->assertJsonPath('data.results.0.status', 'duplicate')
            ->assertJsonPath('data.results.0.attendance_id', $attendanceId);
    }

    public function test_consumed_permit_does_not_leak_outcome_for_wrong_user_token_or_binding(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        [$item] = $this->offlineItem($student, $jadwal, 'check_in');
        $this->sync($student, [$item])->assertJsonPath('data.results.0.status', 'success');
        [$other] = $this->userWithRole('mahasiswa', 'TE', ['enrollment_status' => 'approved']);
        $otherJadwal = $jadwal->replicate();
        $otherJadwal->ruangan = 'Lab 2';
        $otherJadwal->save();

        $wrongToken = $item;
        $wrongToken['permit_token'] = str_repeat('a', 64);
        $wrongUuid = $item;
        $wrongUuid['client_uuid'] = fake()->uuid();
        $wrongSchedule = $item;
        $wrongSchedule['jadwal_id'] = $otherJadwal->id;
        $wrongAction = $item;
        $wrongAction['type'] = 'check_out';
        $wrongAction['attendance_id'] = Attendance::where('user_id', $student->id)->value('id');

        foreach ([[$student, $wrongToken], [$student, $wrongUuid], [$student, $wrongSchedule], [$student, $wrongAction], [$other, $item]] as [$actor, $invalid]) {
            $this->sync($actor, [$invalid])->assertJsonPath('data.results.0.status', 'failed');
        }
    }

    public function test_consumed_checkout_permit_does_not_leak_outcome_for_wrong_attendance_binding(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        $attendance = $this->checkedInAttendance($student, $jadwal);
        [$item] = $this->offlineItem($student, $jadwal, 'check_out', $attendance);
        $this->sync($student, [$item])->assertJsonPath('data.results.0.status', 'success');
        [$other] = $this->userWithRole('mahasiswa', 'TE');
        $otherAttendance = Attendance::create([
            'user_id' => $other->id,
            'jadwal_id' => $jadwal->id,
            'mata_kuliah_id' => $jadwal->mata_kuliah_id,
            'pertemuan_ke' => 1,
            'tanggal' => now()->toDateString(),
            'checkin_time' => now()->subHour(),
            'status' => 'hadir',
        ]);
        $item['attendance_id'] = $otherAttendance->id;

        $this->sync($student, [$item])
            ->assertJsonPath('data.success', 0)
            ->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.reason', 'Permit atau binding absensi tidak valid');
    }

    public function test_consumed_permit_without_committed_outcome_fails_without_reexecution(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        [$item] = $this->offlineItem($student, $jadwal, 'check_in');
        AttendancePermit::where('client_uuid', $item['client_uuid'])->update(['consumed_at' => now()]);

        $this->sync($student, [$item])
            ->assertJsonPath('data.success', 0)
            ->assertJsonPath('data.failed', 1)
            ->assertJsonPath('data.results.0.status', 'failed');
        $this->assertDatabaseMissing('attendances', ['user_id' => $student->id, 'client_uuid' => $item['client_uuid']]);
    }

    public function test_checkout_client_uuid_is_unique_per_user(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        $uuid = fake()->uuid();
        $this->checkedInAttendance($student, $jadwal)->update(['checkout_client_uuid' => $uuid]);
        $other = $this->checkedInAttendance($student, $jadwal, now()->subDay());

        $this->expectException(QueryException::class);
        $other->update(['checkout_client_uuid' => $uuid]);
    }

    public function test_auto_close_defers_to_valid_checkout_permit_until_offline_sync_or_expiry(): void
    {
        [$student, , $jadwal] = $this->attendanceFixture();
        $attendance = $this->checkedInAttendance($student, $jadwal);
        [$item] = $this->offlineItem($student, $jadwal, 'check_out', $attendance);

        Carbon::setTestNow('2026-07-20 11:16:00');
        $this->artisan('attendance:auto-close')->assertSuccessful();
        $this->assertNull($attendance->fresh()->checkout_time);

        $this->sync($student, [$item])->assertJsonPath('data.results.0.status', 'success');
        $this->assertFalse($attendance->fresh()->is_auto_closed);

        $attendance->update(['checkout_time' => null, 'checkout_client_uuid' => null]);
        AttendancePermit::where('attendance_id', $attendance->id)->update([
            'consumed_at' => null,
            'sync_expires_at' => now()->subSecond(),
        ]);
        $this->artisan('attendance:auto-close')->assertSuccessful();
        $this->assertTrue($attendance->fresh()->is_auto_closed);
    }

    public function test_permit_and_today_schedule_expose_authoritative_metadata_for_student_prodi(): void
    {
        [$student, $prodi, $jadwal] = $this->attendanceFixture();
        ProdiSetting::where('prodi_id', $prodi->id)->update([
            'gps_accuracy_minimum' => 12,
            'gps_max_age_seconds' => 7,
        ]);

        $this->actingAs($student)->getJson('/api/mahasiswa/jadwal/today')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.timezone', config('app.timezone'))
            ->assertJsonPath('meta.location_policy.max_accuracy_meters', 12)
            ->assertJsonPath('meta.location_policy.max_age_seconds', 7)
            ->assertJsonStructure(['meta' => ['server_time'], 'data' => [['window', 'eligibility']]]);

        $permit = $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', [
            'jadwal_id' => $jadwal->id,
            'action' => 'check_in',
            'client_uuid' => fake()->uuid(),
        ])->assertCreated();
        $permit->assertJsonPath('data.location_policy.max_accuracy_meters', 12)
            ->assertJsonPath('data.location_policy.max_age_seconds', 7)
            ->assertJsonStructure(['data' => ['issued_at', 'server_time', 'windows' => ['not_before', 'expires_at', 'sync_expires_at']]]);
    }

    public function test_location_policy_boundaries_apply_online_and_offline(): void
    {
        [$student, $prodi, $jadwal] = $this->attendanceFixture();
        ProdiSetting::where('prodi_id', $prodi->id)->update([
            'gps_accuracy_minimum' => 5,
            'gps_max_age_seconds' => 2,
        ]);

        $uuid = fake()->uuid();
        $permit = $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', [
            'jadwal_id' => $jadwal->id, 'action' => 'check_in', 'client_uuid' => $uuid,
        ])->assertCreated()->json('data');
        $this->actingAs($student)->postJson('/api/mahasiswa/attendance/check-in',
            $this->evidence($jadwal, $permit, $uuid) + ['gps_accuracy' => 5, 'location_age_ms' => 2000])
            ->assertCreated();

        $secondCourse = $jadwal->mataKuliah->replicate();
        $secondCourse->kode_mk = 'SEC103';
        $secondCourse->save();
        $student->mataKuliahs()->attach($secondCourse->id);
        $secondSchedule = $jadwal->replicate();
        $secondSchedule->mata_kuliah_id = $secondCourse->id;
        $secondSchedule->save();
        [$offline] = $this->offlineItem($student, $secondSchedule, 'check_in');
        $offline['location_age_ms'] = 2001;

        $this->sync($student, [$offline])
            ->assertJsonPath('data.results.0.code', 'location_too_old')
            ->assertJsonPath('data.results.0.retryable', false);
    }

    private function userWithRole(string $role, string $prodiCode, array $attributes = []): array
    {
        $prodi = Prodi::where('kode', $prodiCode)->firstOrFail();
        $user = User::factory()->create($attributes + [
            'prodi_id' => $prodi->id, 'status' => 'aktif', 'enrollment_status' => 'belum',
        ]);
        $user->roles()->attach(Role::where('name', $role)->value('id'));
        if (($attributes['enrollment_status'] ?? null) === 'approved') {
            FaceEmbedding::create(['user_id' => $user->id, 'embedding' => array_fill(0, 192, 0.01), 'version' => 1, 'status' => 'approved']);
        }

        return [$user, $prodi];
    }

    private function attendanceFixture(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 10:00:00')); // Senin
        [$student, $prodi] = $this->userWithRole('mahasiswa', 'TI', ['enrollment_status' => 'approved']);
        $tahun = TahunAjaran::create(['kode' => '2026', 'nama' => '2026/2027', 'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif']);
        $semester = Semester::create(['tahun_ajaran_id' => $tahun->id, 'nama' => 'Ganjil', 'kode' => '2026-G', 'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif']);
        $mk = MataKuliah::create(['kode_mk' => 'SEC101', 'nama' => 'Security', 'sks' => 2, 'semester_id' => $semester->id, 'prodi_id' => $prodi->id, 'status' => 'aktif']);
        $student->mataKuliahs()->attach($mk->id);
        $geo = Geofence::create(['nama' => 'Lab', 'latitude' => -0.0263, 'longitude' => 109.3425, 'radius' => 100, 'prodi_id' => $prodi->id, 'status' => 'aktif']);
        $jadwal = Jadwal::create(['mata_kuliah_id' => $mk->id, 'geofence_id' => $geo->id, 'hari' => 'Senin', 'jam_mulai' => '09:00', 'jam_selesai' => '11:00', 'status' => 'aktif']);
        ProdiSetting::create(['prodi_id' => $prodi->id, 'allow_offline_attendance' => true, 'offline_sync_timeout_menit' => 30]);

        return [$student, $prodi, $jadwal];
    }

    private function evidence(Jadwal $jadwal, ?array $permit = null, ?string $uuid = null): array
    {
        return [
            'jadwal_id' => $jadwal->id, 'client_uuid' => $uuid ?? fake()->uuid(),
            'permit_token' => $permit['permit_token'] ?? null,
            'latitude' => -0.0263, 'longitude' => 109.3425, 'face_distance' => 0.1,
            'mock_location_detected' => false, 'liveness_passed' => true,
            'liveness_challenge' => $permit['liveness_challenge'] ?? 'smile', 'gps_accuracy' => 5,
            'location_age_ms' => 0,
        ];
    }

    private function offlineItem(User $student, Jadwal $jadwal, string $action, ?Attendance $attendance = null): array
    {
        $uuid = fake()->uuid();
        $permit = $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', [
            'jadwal_id' => $jadwal->id,
            'attendance_id' => $attendance?->id,
            'action' => $action,
            'client_uuid' => $uuid,
        ])->assertCreated()->json('data');

        return [$this->evidence($jadwal, $permit, $uuid) + array_filter([
            'attendance_id' => $attendance?->id,
            'type' => $action,
            'timestamp' => now()->toIso8601String(),
        ], fn ($value) => $value !== null), $uuid];
    }

    private function onlineCheckoutItem(User $student, Jadwal $jadwal, Attendance $attendance): array
    {
        $uuid = fake()->uuid();
        $permit = $this->actingAs($student)->postJson('/api/mahasiswa/attendance/permits', [
            'jadwal_id' => $jadwal->id,
            'attendance_id' => $attendance->id,
            'action' => 'check_out',
            'client_uuid' => $uuid,
        ])->assertCreated()->json('data');

        return [$this->evidence($jadwal, $permit, $uuid) + ['attendance_id' => $attendance->id], $uuid];
    }

    private function checkedInAttendance(User $student, Jadwal $jadwal, ?Carbon $date = null): Attendance
    {
        $date ??= now();

        return Attendance::create([
            'user_id' => $student->id,
            'jadwal_id' => $jadwal->id,
            'mata_kuliah_id' => $jadwal->mata_kuliah_id,
            'pertemuan_ke' => 1,
            'tanggal' => $date->toDateString(),
            'checkin_time' => $date->copy()->subHour(),
            'status' => 'hadir',
        ]);
    }

    private function sync(User $student, array $items)
    {
        return $this->actingAs($student)->postJson('/api/mahasiswa/attendance/sync-offline', [
            'attendances' => $items,
        ])->assertOk();
    }
}
