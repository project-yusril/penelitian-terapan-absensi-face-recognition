<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\MataKuliah;
use App\Models\Prodi;
use App\Models\SpRecord;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\SeedsEssentialData;
use Tests\TestCase;

/**
 * Regression M-19: lifecycle master historis adalah restrict/archive.
 *
 * Database menolak hard delete master (user/jadwal/mata_kuliah/semester)
 * selama masih ada rekam akademik (attendance, SP, alpha, leave, embedding,
 * log). Arsip via soft delete tetap tersedia dan dapat di-restore tanpa
 * kehilangan sejarah.
 */
class HistoricalMasterLifecycleTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();
    }

    private function prodiId(): int
    {
        return Prodi::where('kode', 'TI')->value('id');
    }

    /**
     * @return array{user:int, semester:int, mata_kuliah:int, jadwal:int}
     */
    private function seedAcademicRecords(): array
    {
        $userId = User::create([
            'nama' => 'Mahasiswa Riwayat',
            'email' => 'riwayat@example.test',
            'password' => 'password',
            'nim' => 'RWT-001',
            'prodi_id' => $this->prodiId(),
            'status' => 'aktif',
        ])->id;

        $tahunId = DB::table('tahun_ajarans')->insertGetId([
            'kode' => 'RWT-TA', 'nama' => '2026/2027', 'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);

        $semesterId = DB::table('semesters')->insertGetId([
            'tahun_ajaran_id' => $tahunId, 'nama' => 'Ganjil', 'kode' => 'RWT-G',
            'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-06-30', 'status' => 'aktif',
        ]);

        $mkId = DB::table('mata_kuliahs')->insertGetId([
            'kode_mk' => 'RWT101', 'nama' => 'Riwayat', 'sks' => 2,
            'semester_id' => $semesterId, 'prodi_id' => $this->prodiId(),
            'kelas' => 'A', 'total_pertemuan' => 16, 'status' => 'aktif',
        ]);

        $geofenceId = DB::table('geofences')->insertGetId([
            'nama' => 'Kampus', 'latitude' => -0.02, 'longitude' => 109.3,
            'radius' => 50, 'prodi_id' => $this->prodiId(), 'status' => 'aktif',
        ]);

        $jadwalId = DB::table('jadwals')->insertGetId([
            'mata_kuliah_id' => $mkId, 'geofence_id' => $geofenceId, 'hari' => 'Senin',
            'jam_mulai' => '08:00:00', 'jam_selesai' => '10:00:00', 'status' => 'aktif',
        ]);

        Attendance::create([
            'user_id' => $userId,
            'jadwal_id' => $jadwalId,
            'mata_kuliah_id' => $mkId,
            'tanggal' => '2026-03-02',
            'status' => 'hadir',
        ]);

        SpRecord::create([
            'user_id' => $userId,
            'semester_id' => $semesterId,
            'sp_level' => 'sp1',
            'total_alpha_jam' => 12.5,
            'status' => 'final',
        ]);

        return ['user' => $userId, 'semester' => $semesterId, 'mata_kuliah' => $mkId, 'jadwal' => $jadwalId];
    }

    public function test_hard_delete_user_with_history_is_rejected(): void
    {
        $ids = $this->seedAcademicRecords();

        try {
            DB::table('users')->where('id', $ids['user'])->delete();
            $this->fail('Hard delete user dengan riwayat seharusnya ditolak database.');
        } catch (QueryException $e) {
            // expected: FK RESTRICT
        }

        $this->assertDatabaseHas('attendances', ['user_id' => $ids['user']]);
        $this->assertDatabaseHas('sp_records', ['user_id' => $ids['user']]);
    }

    public function test_hard_delete_semester_with_sp_history_is_rejected(): void
    {
        $ids = $this->seedAcademicRecords();

        $this->expectException(QueryException::class);
        DB::table('semesters')->where('id', $ids['semester'])->delete();
    }

    public function test_hard_delete_mata_kuliah_with_attendance_is_rejected(): void
    {
        $ids = $this->seedAcademicRecords();

        try {
            DB::table('mata_kuliahs')->where('id', $ids['mata_kuliah'])->delete();
            $this->fail('Hard delete mata kuliah dengan kehadiran seharusnya ditolak database.');
        } catch (QueryException $e) {
            // expected: FK RESTRICT
        }

        // Retention: rekam kehadiran tetap utuh setelah percobaan hard delete.
        $this->assertDatabaseHas('attendances', ['mata_kuliah_id' => $ids['mata_kuliah']]);
    }

    public function test_hard_delete_jadwal_with_attendance_is_rejected(): void
    {
        $ids = $this->seedAcademicRecords();

        $this->expectException(QueryException::class);
        DB::table('jadwals')->where('id', $ids['jadwal'])->delete();
    }

    public function test_master_can_be_archived_and_restored_without_losing_history(): void
    {
        $ids = $this->seedAcademicRecords();

        $mk = MataKuliah::findOrFail($ids['mata_kuliah']);
        $mk->delete(); // soft delete = archive

        // Arsip: default query menyembunyikan, tetapi baris + sejarah tetap ada.
        $this->assertNull(MataKuliah::find($ids['mata_kuliah']));
        $this->assertNotNull(MataKuliah::withTrashed()->find($ids['mata_kuliah']));
        $this->assertDatabaseHas('attendances', ['mata_kuliah_id' => $ids['mata_kuliah']]);

        MataKuliah::withTrashed()->findOrFail($ids['mata_kuliah'])->restore();

        $this->assertNotNull(MataKuliah::find($ids['mata_kuliah']));
        $this->assertDatabaseHas('attendances', ['mata_kuliah_id' => $ids['mata_kuliah']]);
    }

    public function test_master_hard_delete_succeeds_once_history_removed(): void
    {
        $ids = $this->seedAcademicRecords();

        // Retention lifecycle: setelah sejarah dipindah/dihapus terkontrol,
        // master baru boleh dibuang permanen.
        DB::table('attendances')->where('jadwal_id', $ids['jadwal'])->delete();
        DB::table('sp_records')->where('semester_id', $ids['semester'])->delete();
        DB::table('jadwals')->where('id', $ids['jadwal'])->delete();

        DB::table('mata_kuliahs')->where('id', $ids['mata_kuliah'])->delete();
        DB::table('semesters')->where('id', $ids['semester'])->delete();

        $this->assertDatabaseMissing('mata_kuliahs', ['id' => $ids['mata_kuliah']]);
        $this->assertDatabaseMissing('semesters', ['id' => $ids['semester']]);
    }
}
