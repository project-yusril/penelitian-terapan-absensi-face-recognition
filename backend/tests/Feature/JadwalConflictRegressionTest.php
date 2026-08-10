<?php

namespace Tests\Feature;

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

/**
 * Regression untuk L-04: validasi bentrok jadwal harus memakai interval
 * setengah terbuka [start, end). Jadwal back-to-back tidak boleh dianggap
 * bentrok, sedangkan jadwal yang benar-benar overlap tetap ditolak.
 */
class JadwalConflictRegressionTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private User $admin;

    private Geofence $geofence;

    private MataKuliah $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();

        $prodi = Prodi::where('kode', 'TI')->firstOrFail();
        $this->admin = User::factory()->create([
            'nim' => null, 'prodi_id' => $prodi->id, 'status' => 'aktif',
            'enrollment_status' => 'not_required',
        ]);
        $this->admin->roles()->attach(Role::where('name', 'super_admin')->value('id'));

        $year = TahunAjaran::create([
            'kode' => '2026-JC', 'nama' => '2026/2027', 'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $semester = Semester::create([
            'tahun_ajaran_id' => $year->id, 'kode' => '2026-JC-G', 'nama' => 'Ganjil',
            'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $this->course = MataKuliah::create([
            'kode_mk' => 'JC101', 'nama' => 'Jadwal Conflict', 'sks' => 2,
            'semester_id' => $semester->id, 'prodi_id' => $prodi->id, 'status' => 'aktif',
        ]);
        $this->geofence = Geofence::create([
            'nama' => 'JC Lab', 'latitude' => -0.0263, 'longitude' => 109.3425,
            'radius' => 100, 'prodi_id' => $prodi->id, 'status' => 'aktif',
        ]);

        Jadwal::create([
            'mata_kuliah_id' => $this->course->id, 'geofence_id' => $this->geofence->id,
            'hari' => 'Senin', 'jam_mulai' => '08:00', 'jam_selesai' => '09:00', 'status' => 'aktif',
        ]);
    }

    private function payload(string $jamMulai, string $jamSelesai): array
    {
        return [
            'mata_kuliah_id' => $this->course->id,
            'geofence_id' => $this->geofence->id,
            'hari' => 'Senin',
            'jam_mulai' => $jamMulai,
            'jam_selesai' => $jamSelesai,
            'status' => 'aktif',
        ];
    }

    public function test_back_to_back_schedule_is_allowed(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/jadwal', $this->payload('09:00', '10:00'))
            ->assertCreated();
    }

    public function test_schedule_ending_exactly_at_existing_start_is_allowed(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/jadwal', $this->payload('07:00', '08:00'))
            ->assertCreated();
    }

    public function test_overlapping_schedule_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/jadwal', $this->payload('08:30', '09:30'))
            ->assertStatus(422);
    }

    public function test_fully_contained_schedule_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/jadwal', $this->payload('08:15', '08:45'))
            ->assertStatus(422);
    }

    public function test_partial_update_cannot_produce_reversed_time_range(): void
    {
        $schedule = Jadwal::create([
            'mata_kuliah_id' => $this->course->id, 'geofence_id' => $this->geofence->id,
            'hari' => 'Selasa', 'jam_mulai' => '13:00', 'jam_selesai' => '15:00', 'status' => 'aktif',
        ]);

        // Hanya jam_selesai dikirim, sehingga rentang menjadi 13:00-11:00.
        $this->actingAs($this->admin)
            ->putJson("/api/admin/jadwal/{$schedule->id}", ['jam_selesai' => '11:00'])
            ->assertStatus(422);

        $this->assertSame('15:00:00', $schedule->fresh()->jam_selesai);
    }
}
