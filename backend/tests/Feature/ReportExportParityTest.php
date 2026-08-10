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
use OpenSpout\Reader\XLSX\Reader;
use Tests\SeedsEssentialData;
use Tests\TestCase;

/**
 * Regression M-16: export harus menghormati filter yang dipakai layar,
 * termasuk filter mahasiswa pada tab "Per Mahasiswa".
 */
class ReportExportParityTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    public function test_export_respects_student_filter(): void
    {
        $this->seedEssentialData();
        $prodi = Prodi::where('kode', 'TI')->firstOrFail();

        $actor = User::factory()->create([
            'nim' => null, 'prodi_id' => $prodi->id, 'status' => 'aktif',
            'enrollment_status' => 'not_required',
        ]);
        $actor->roles()->attach(Role::where('name', 'super_admin')->value('id'));

        $year = TahunAjaran::create([
            'kode' => 'PAR-1', 'nama' => '2026/2027', 'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $semester = Semester::create([
            'tahun_ajaran_id' => $year->id, 'kode' => 'PAR-G', 'nama' => 'Ganjil',
            'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $course = MataKuliah::create([
            'kode_mk' => 'PAR101', 'nama' => 'Parity', 'sks' => 2,
            'semester_id' => $semester->id, 'prodi_id' => $prodi->id, 'status' => 'aktif',
        ]);
        $geofence = Geofence::create([
            'nama' => 'Parity Lab', 'latitude' => -0.02, 'longitude' => 109.3,
            'radius' => 50, 'prodi_id' => $prodi->id, 'status' => 'aktif',
        ]);
        $schedule = Jadwal::create([
            'mata_kuliah_id' => $course->id, 'geofence_id' => $geofence->id,
            'hari' => 'Senin', 'jam_mulai' => '08:00', 'jam_selesai' => '10:00', 'status' => 'aktif',
        ]);

        $target = $this->student($prodi->id, 'PAR001', 'Target Student');
        $other = $this->student($prodi->id, 'PAR002', 'Other Student');

        foreach ([$target, $other] as $student) {
            Attendance::create([
                'user_id' => $student->id, 'jadwal_id' => $schedule->id,
                'mata_kuliah_id' => $course->id, 'tanggal' => '2026-07-20',
                'checkin_time' => '2026-07-20 08:01:00', 'status' => 'hadir', 'alpha_menit' => 0,
            ]);
        }

        $path = (new AttendanceExport($actor, $semester->id, null, null, null, $target->id))->generate();
        $sheets = $this->readSheets($path);
        @unlink($path);

        $summaryNims = collect($sheets['Summary'])->skip(1)->pluck(1)->all();

        $this->assertContains('PAR001', $summaryNims);
        $this->assertNotContains('PAR002', $summaryNims);
    }

    private function student(int $prodiId, string $nim, string $nama): User
    {
        $student = User::factory()->create([
            'nim' => $nim, 'nama' => $nama, 'kelas' => 'A',
            'prodi_id' => $prodiId, 'status' => 'aktif',
        ]);
        $student->roles()->attach(Role::where('name', 'mahasiswa')->value('id'));

        return $student;
    }

    private function readSheets(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }

        $reader->close();

        return $sheets;
    }
}
