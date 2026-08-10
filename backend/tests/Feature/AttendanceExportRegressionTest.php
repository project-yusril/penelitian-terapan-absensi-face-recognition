<?php

namespace Tests\Feature;

use App\Exports\AttendanceExport;
use App\Models\AlphaAccumulation;
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
use Illuminate\Support\Carbon;
use OpenSpout\Reader\XLSX\Reader;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class AttendanceExportRegressionTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    public function test_export_uses_unique_path_and_canonical_status_and_time_fields(): void
    {
        $this->seedEssentialData();
        Carbon::setTestNow('2026-07-18 10:11:12');
        $prodi = Prodi::where('kode', 'TI')->firstOrFail();
        $actor = $this->user('super_admin', $prodi, ['nim' => null]);
        $student = $this->user('mahasiswa', $prodi, [
            'nim' => 'EXP001', 'nama' => 'Export Student', 'kelas' => 'A',
        ]);
        $year = TahunAjaran::create([
            'kode' => '2026-EXP', 'nama' => '2026/2027', 'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $semester = Semester::create([
            'tahun_ajaran_id' => $year->id, 'kode' => '2026-EXP-G', 'nama' => 'Ganjil',
            'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $course = MataKuliah::create([
            'kode_mk' => 'EXP101', 'nama' => 'Export', 'sks' => 2, 'semester_id' => $semester->id,
            'prodi_id' => $prodi->id, 'status' => 'aktif',
        ]);
        $geofence = Geofence::create([
            'nama' => 'Export Lab', 'latitude' => -6.2, 'longitude' => 106.8,
            'radius' => 50, 'prodi_id' => $prodi->id, 'status' => 'aktif',
        ]);
        $schedule = Jadwal::create([
            'mata_kuliah_id' => $course->id, 'geofence_id' => $geofence->id,
            'hari' => 'Sabtu', 'jam_mulai' => '08:00', 'jam_selesai' => '10:00', 'status' => 'aktif',
        ]);
        Attendance::create([
            'user_id' => $student->id, 'jadwal_id' => $schedule->id, 'mata_kuliah_id' => $course->id,
            'tanggal' => '2026-07-18', 'checkin_time' => '2026-07-18 08:01:02',
            'checkout_time' => '2026-07-18 09:03:04', 'status' => 'hadir', 'alpha_menit' => 0,
        ]);
        AlphaAccumulation::create([
            'user_id' => $student->id, 'semester_id' => $semester->id,
            'total_alpha_menit' => 1920, 'total_alpha_jam' => 32, 'sp_status' => 'sp2',
        ]);

        $firstPath = (new AttendanceExport($actor, $semester->id))->generate();
        $secondPath = (new AttendanceExport($actor, $semester->id))->generate();

        $this->assertNotSame($firstPath, $secondPath);
        $this->assertFileExists($firstPath);
        $sheets = $this->readSheets($firstPath);
        $this->assertSame('sp2', $sheets['Summary'][1][11]);
        $this->assertSame('08:01:02', $sheets['Detail Pertemuan'][1][5]);
        $this->assertSame('09:03:04', $sheets['Detail Pertemuan'][1][6]);

        @unlink($firstPath);
        @unlink($secondPath);
    }

    private function user(string $role, Prodi $prodi, array $attributes): User
    {
        $user = User::factory()->create($attributes + ['prodi_id' => $prodi->id, 'status' => 'aktif']);
        $user->roles()->attach(Role::where('name', $role)->value('id'));

        return $user;
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
