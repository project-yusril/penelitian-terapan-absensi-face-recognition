<?php

namespace Tests\Feature;

use App\Models\Prodi;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\SeedsEssentialData;
use Tests\TestCase;

/**
 * Regression M-20: database menolak state yang melanggar invariant domain.
 */
class DomainInvariantConstraintTest extends TestCase
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

    public function test_geofence_rejects_out_of_range_coordinates(): void
    {
        $this->expectException(QueryException::class);

        DB::table('geofences')->insert([
            'nama' => 'Invalid', 'latitude' => 95.0, 'longitude' => 109.3,
            'radius' => 50, 'prodi_id' => $this->prodiId(), 'status' => 'aktif',
        ]);
    }

    public function test_geofence_rejects_non_positive_radius(): void
    {
        $this->expectException(QueryException::class);

        DB::table('geofences')->insert([
            'nama' => 'Invalid radius', 'latitude' => -0.02, 'longitude' => 109.3,
            'radius' => 0, 'prodi_id' => $this->prodiId(), 'status' => 'aktif',
        ]);
    }

    public function test_jadwal_rejects_end_time_before_start(): void
    {
        $this->expectException(QueryException::class);

        DB::table('jadwals')->insert([
            'mata_kuliah_id' => 1, 'geofence_id' => 1, 'hari' => 'Senin',
            'jam_mulai' => '10:00:00', 'jam_selesai' => '09:00:00', 'status' => 'aktif',
        ]);
    }

    public function test_tahun_ajaran_rejects_reversed_date_range(): void
    {
        $this->expectException(QueryException::class);

        DB::table('tahun_ajarans')->insert([
            'kode' => 'BAD-1', 'nama' => 'Bad', 'tanggal_mulai' => '2026-12-31',
            'tanggal_selesai' => '2026-01-01', 'status' => 'nonaktif',
        ]);
    }

    public function test_prodi_setting_rejects_overlapping_sp_thresholds(): void
    {
        $this->expectException(QueryException::class);

        DB::table('prodi_settings')->insert([
            'prodi_id' => $this->prodiId(),
            'sp1_jam_mulai' => 16, 'sp1_jam_akhir' => 40,
            'sp2_jam_mulai' => 32, 'sp2_jam_akhir' => 37,
            'sp3_jam_mulai' => 38, 'sp3_jam_akhir' => 45,
            'do_jam_mulai' => 46,
        ]);
    }

    public function test_mata_kuliah_null_kelas_cannot_duplicate(): void
    {
        $semesterId = DB::table('tahun_ajarans')->insertGetId([
            'kode' => 'MK-INV', 'nama' => '2026/2027', 'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $semesterId = DB::table('semesters')->insertGetId([
            'tahun_ajaran_id' => $semesterId, 'nama' => 'Ganjil', 'kode' => 'MK-INV-G',
            'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);

        $row = [
            'kode_mk' => 'DUP101', 'nama' => 'Duplikat', 'sks' => 2,
            'semester_id' => $semesterId, 'prodi_id' => $this->prodiId(),
            'kelas' => null, 'total_pertemuan' => 16, 'status' => 'aktif',
        ];

        DB::table('mata_kuliahs')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('mata_kuliahs')->insert($row);
    }
}
