<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Geofence;
use App\Models\Jadwal;
use App\Models\MataKuliah;
use App\Models\Prodi;
use App\Models\Role;
use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class CanonicalScopeRegressionTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();
    }

    public function test_admin_prodi_cannot_use_report_object_id_from_another_prodi(): void
    {
        [$actor] = $this->userWithRole('admin_prodi', 'TI');
        [, $otherCourse] = $this->attendanceInProdi('TE');

        $this->actingAs($actor)
            ->getJson('/api/admin/reports/by-mata-kuliah?mata_kuliah_id='.$otherCourse->id)
            ->assertNotFound();
    }

    public function test_admin_jurusan_dashboard_is_fail_closed_to_actor_prodi(): void
    {
        [$actor] = $this->userWithRole('admin_jurusan', 'TI');
        $this->attendanceInProdi('TI');
        $this->attendanceInProdi('TE');

        $this->actingAs($actor)->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.attendance_today.total', 1)
            ->assertJsonPath('data.users.total_mahasiswa', 1);
    }

    public function test_super_admin_dashboard_remains_global(): void
    {
        [$actor] = $this->userWithRole('super_admin', 'TI');
        $this->attendanceInProdi('TI');
        $this->attendanceInProdi('TE');

        $this->actingAs($actor)->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.attendance_today.total', 2)
            ->assertJsonPath('data.users.total_mahasiswa', 2);
    }

    public function test_non_super_admin_cannot_read_or_update_global_system_settings(): void
    {
        [$actor] = $this->userWithRole('admin_prodi', 'TI');

        $this->actingAs($actor)->getJson('/api/admin/settings')->assertForbidden();
        $this->actingAs($actor)->putJson('/api/admin/settings', ['settings' => []])->assertForbidden();
    }

    private function attendanceInProdi(string $prodiCode): array
    {
        [$student, $prodi] = $this->userWithRole('mahasiswa', $prodiCode);
        $tahun = TahunAjaran::firstOrCreate(
            ['kode' => '2026'],
            ['nama' => '2026/2027', 'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif'],
        );
        $semester = Semester::firstOrCreate(
            ['kode' => '2026-G'],
            ['tahun_ajaran_id' => $tahun->id, 'nama' => 'Ganjil', 'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif'],
        );
        $course = MataKuliah::create([
            'kode_mk' => 'MK-'.$prodiCode.'-'.fake()->unique()->numberBetween(1, 9999),
            'nama' => 'Course '.$prodiCode,
            'sks' => 2,
            'semester_id' => $semester->id,
            'prodi_id' => $prodi->id,
            'status' => 'aktif',
        ]);
        $student->mataKuliahs()->attach($course->id);
        $geofence = Geofence::create([
            'nama' => 'Geo '.$prodiCode,
            'latitude' => -0.0263,
            'longitude' => 109.3425,
            'radius' => 100,
            'prodi_id' => $prodi->id,
            'status' => 'aktif',
        ]);
        $schedule = Jadwal::create([
            'mata_kuliah_id' => $course->id,
            'geofence_id' => $geofence->id,
            'hari' => 'Sabtu',
            'jam_mulai' => '08:00',
            'jam_selesai' => '10:00',
            'status' => 'aktif',
        ]);
        Attendance::create([
            'user_id' => $student->id,
            'jadwal_id' => $schedule->id,
            'mata_kuliah_id' => $course->id,
            'tanggal' => today(),
            'status' => 'hadir',
        ]);

        return [$student, $course];
    }

    private function userWithRole(string $role, string $prodiCode): array
    {
        $prodi = Prodi::where('kode', $prodiCode)->firstOrFail();
        $user = User::factory()->create([
            'prodi_id' => $prodi->id,
            'status' => 'aktif',
            'enrollment_status' => 'belum',
        ]);
        $user->roles()->attach(Role::where('name', $role)->value('id'));

        return [$user, $prodi];
    }
}
