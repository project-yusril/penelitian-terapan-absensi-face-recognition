<?php

namespace Tests\Feature;

use App\Models\AlphaAccumulation;
use App\Models\Attendance;
use App\Models\Geofence;
use App\Models\Jadwal;
use App\Models\MataKuliah;
use App\Models\Prodi;
use App\Models\ProdiSetting;
use App\Models\Role;
use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class SeedSpDemoTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private Semester $semester;

    /** @var array<string, array<int, User>> */
    private array $studentsByKelas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();

        $year = TahunAjaran::create([
            'kode' => '2026-SP', 'nama' => '2026/2027', 'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $this->semester = Semester::create([
            'tahun_ajaran_id' => $year->id, 'kode' => '2026-SP-G', 'nama' => 'Genap',
            'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);

        $prodi = Prodi::where('kode', 'TI')->firstOrFail();
        ProdiSetting::updateOrCreate(
            ['prodi_id' => $prodi->id],
            ['sp1_jam_mulai' => 16, 'sp2_jam_mulai' => 32, 'sp3_jam_mulai' => 38, 'do_jam_mulai' => 46]
        );

        Geofence::create(['nama' => 'Lab Test', 'latitude' => 0, 'longitude' => 0, 'radius' => 100, 'status' => 'aktif']);

        foreach (['A', 'C', 'D'] as $kelas) {
            $mk = MataKuliah::create([
                'kode_mk' => 'TI-401', 'nama' => 'Pemrograman Mobile', 'sks' => 3,
                'semester_id' => $this->semester->id, 'prodi_id' => $prodi->id,
                'kelas' => $kelas, 'status' => 'aktif',
            ]);
            $students = [$this->mahasiswa($prodi, $kelas), $this->mahasiswa($prodi, $kelas)];
            $mk->mahasiswas()->attach(array_map(fn ($s) => $s->id, $students));
            $this->studentsByKelas[$kelas] = $students;
        }
    }

    private function mahasiswa(Prodi $prodi, string $kelas): User
    {
        $user = User::factory()->create([
            'prodi_id' => $prodi->id, 'kelas' => $kelas, 'status' => 'aktif', 'semester' => 4,
        ]);
        $user->roles()->attach(Role::where('name', 'mahasiswa')->value('id'));

        return $user;
    }

    public function test_seed_sp_demo_creates_one_candidate_per_class_with_correct_level(): void
    {
        $this->artisan('attendance:seed-sp-demo')->assertSuccessful();

        $expected = ['A' => 'sp1', 'C' => 'sp2', 'D' => 'sp3'];
        foreach ($expected as $kelas => $level) {
            $count = AlphaAccumulation::whereHas('user', fn ($q) => $q->where('kelas', $kelas))
                ->where('sp_status', $level)->count();
            $this->assertSame(1, $count, "Kelas {$kelas} harus punya tepat 1 kandidat {$level}");
        }
    }

    public function test_seed_sp_demo_is_idempotent(): void
    {
        $this->artisan('attendance:seed-sp-demo')->assertSuccessful();
        $this->artisan('attendance:seed-sp-demo')->assertSuccessful();

        $totalCandidates = AlphaAccumulation::where('sp_status', '!=', 'aman')->count();
        $this->assertSame(3, $totalCandidates, 'Run ulang tidak boleh menambah kandidat SP');
    }

    public function test_seed_sp_demo_does_not_touch_other_classes(): void
    {
        $prodi = Prodi::where('kode', 'TI')->firstOrFail();
        $other = $this->mahasiswa($prodi, 'B');
        $mkB = MataKuliah::create([
            'kode_mk' => 'TI-402', 'nama' => 'MK Valid', 'sks' => 3,
            'semester_id' => $this->semester->id, 'prodi_id' => $prodi->id,
            'kelas' => 'B', 'status' => 'aktif',
        ]);
        $mkB->mahasiswas()->attach($other->id);
        Attendance::create([
            'user_id' => $other->id, 'jadwal_id' => $this->jadwal($mkB)->id,
            'mata_kuliah_id' => $mkB->id, 'tanggal' => '2026-08-01', 'status' => 'hadir', 'alpha_menit' => 0,
        ]);

        $this->artisan('attendance:seed-sp-demo')->assertSuccessful();

        $this->assertNull(AlphaAccumulation::where('user_id', $other->id)->first());
        $this->assertDatabaseHas('attendances', ['user_id' => $other->id, 'status' => 'hadir']);
    }

    private function jadwal(MataKuliah $mk): Jadwal
    {
        $geofence = Geofence::firstOrFail();

        return Jadwal::create([
            'mata_kuliah_id' => $mk->id, 'geofence_id' => $geofence->id,
            'hari' => 'Senin', 'jam_mulai' => '08:00', 'jam_selesai' => '10:30',
            'durasi_menit' => 150, 'status' => 'aktif',
        ]);
    }
}
