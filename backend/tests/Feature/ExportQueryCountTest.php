<?php

namespace Tests\Feature;

use App\Exports\AttendanceExport;
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
use Illuminate\Support\Facades\DB;
use Tests\SeedsEssentialData;
use Tests\TestCase;

/**
 * Regression M-17: export tidak boleh melakukan query per mahasiswa atau
 * per kombinasi mahasiswa x mata kuliah. Jumlah query harus tetap stabil
 * ketika jumlah mahasiswa bertambah.
 */
class ExportQueryCountTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();
    }

    private function buildDataset(int $studentCount, int $courseCount): array
    {
        $prodi = Prodi::where('kode', 'TI')->firstOrFail();
        $actor = User::factory()->create([
            'nim' => null, 'prodi_id' => $prodi->id, 'status' => 'aktif',
            'enrollment_status' => 'not_required',
        ]);
        $actor->roles()->attach(Role::where('name', 'super_admin')->value('id'));

        $year = TahunAjaran::create([
            'kode' => 'QC-'.$studentCount, 'nama' => '2026/2027', 'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $semester = Semester::create([
            'tahun_ajaran_id' => $year->id, 'kode' => 'QC-G-'.$studentCount, 'nama' => 'Ganjil',
            'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $geofence = Geofence::create([
            'nama' => 'QC Lab '.$studentCount, 'latitude' => -0.02, 'longitude' => 109.3,
            'radius' => 50, 'prodi_id' => $prodi->id, 'status' => 'aktif',
        ]);

        $courses = [];
        for ($c = 0; $c < $courseCount; $c++) {
            $course = MataKuliah::create([
                'kode_mk' => "QC{$studentCount}{$c}", 'nama' => "Course {$c}", 'sks' => 2,
                'semester_id' => $semester->id, 'prodi_id' => $prodi->id, 'status' => 'aktif',
            ]);
            $courses[] = $course;
            Jadwal::create([
                'mata_kuliah_id' => $course->id, 'geofence_id' => $geofence->id,
                'hari' => 'Senin', 'jam_mulai' => '08:00', 'jam_selesai' => '10:00', 'status' => 'aktif',
            ]);
        }

        for ($s = 0; $s < $studentCount; $s++) {
            $student = User::factory()->create([
                'nim' => "QC{$studentCount}{$s}", 'nama' => "Student {$s}", 'kelas' => 'A',
                'prodi_id' => $prodi->id, 'status' => 'aktif',
            ]);
            $student->roles()->attach(Role::where('name', 'mahasiswa')->value('id'));

            foreach ($courses as $course) {
                Attendance::create([
                    'user_id' => $student->id,
                    'jadwal_id' => Jadwal::where('mata_kuliah_id', $course->id)->value('id'),
                    'mata_kuliah_id' => $course->id, 'tanggal' => '2026-07-20',
                    'checkin_time' => '2026-07-20 08:01:00', 'status' => 'alpha', 'alpha_menit' => 100,
                ]);
            }
        }

        return [$actor, $semester->id];
    }

    private function countQueriesForExport(User $actor, int $semesterId): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $path = (new AttendanceExport($actor, $semesterId))->generate();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        @unlink($path);

        return $count;
    }

    public function test_export_query_count_does_not_grow_with_student_count(): void
    {
        [$smallActor, $smallSemester] = $this->buildDataset(2, 2);
        $smallQueries = $this->countQueriesForExport($smallActor, $smallSemester);

        [$largeActor, $largeSemester] = $this->buildDataset(8, 2);
        $largeQueries = $this->countQueriesForExport($largeActor, $largeSemester);

        // Jumlah query harus konstan terhadap jumlah mahasiswa.
        $this->assertSame(
            $smallQueries,
            $largeQueries,
            "Export melakukan query per mahasiswa: {$smallQueries} vs {$largeQueries}."
        );
    }
}
