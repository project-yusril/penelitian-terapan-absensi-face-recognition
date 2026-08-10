<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M-20: tambahkan CHECK constraint, unique, dan composite index agar
 * invariant domain dijaga database, bukan hanya application layer.
 *
 * Catatan: CHECK constraint di-enforce mulai MySQL 8.0.16. Migration ini
 * fail-safe terhadap constraint yang sudah ada sehingga aman dijalankan ulang.
 */
return new class extends Migration
{
    /**
     * @return array<string, array<string, string>> tabel => [nama constraint => ekspresi]
     */
    private function checks(): array
    {
        return [
            'geofences' => [
                'chk_geofence_latitude' => 'latitude >= -90 AND latitude <= 90',
                'chk_geofence_longitude' => 'longitude >= -180 AND longitude <= 180',
                'chk_geofence_radius' => 'radius > 0',
            ],
            'jadwals' => [
                'chk_jadwal_time_order' => 'jam_selesai > jam_mulai',
            ],
            'semesters' => [
                'chk_semester_date_order' => 'tanggal_selesai >= tanggal_mulai',
            ],
            'tahun_ajarans' => [
                'chk_tahun_date_order' => 'tanggal_selesai >= tanggal_mulai',
            ],
            'mata_kuliahs' => [
                'chk_mk_sks_positive' => 'sks > 0',
                'chk_mk_total_pertemuan' => 'total_pertemuan > 0',
            ],
            'prodi_settings' => [
                'chk_setting_toleransi' => 'toleransi_masuk_menit >= 0 AND toleransi_pulang_menit >= 0',
                'chk_setting_geofence' => 'default_radius_meter > 0 AND gps_accuracy_minimum > 0',
                'chk_setting_sp_order' => 'sp1_jam_mulai <= sp1_jam_akhir AND sp1_jam_akhir < sp2_jam_mulai '
                    .'AND sp2_jam_mulai <= sp2_jam_akhir AND sp2_jam_akhir < sp3_jam_mulai '
                    .'AND sp3_jam_mulai <= sp3_jam_akhir AND sp3_jam_akhir < do_jam_mulai',
                'chk_setting_persen' => 'batas_terlambat_persen BETWEEN 0 AND 100 AND sp_warning_percentage BETWEEN 0 AND 100',
            ],
        ];
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->checks() as $table => $constraints) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($constraints as $name => $expression) {
                if ($this->constraintExists($table, $name)) {
                    continue;
                }

                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
            }
        }

        $this->addMataKuliahUniqueness();
        $this->addQueryIndexes();
    }

    /**
     * MySQL memperlakukan NULL sebagai nilai distinct sehingga unique
     * (kode_mk, semester_id, kelas) masih mengizinkan duplikat saat
     * kelas IS NULL. Generated column menormalkan NULL menjadi string kosong.
     */
    private function addMataKuliahUniqueness(): void
    {
        if (! Schema::hasTable('mata_kuliahs') || Schema::hasColumn('mata_kuliahs', 'kelas_key')) {
            return;
        }

        DB::statement(
            "ALTER TABLE `mata_kuliahs` ADD COLUMN `kelas_key` VARCHAR(10) "
            ."GENERATED ALWAYS AS (COALESCE(`kelas`, '')) STORED"
        );

        if (! $this->indexExists('mata_kuliahs', 'unique_mk_semester_kelas_key')) {
            DB::statement(
                'ALTER TABLE `mata_kuliahs` ADD UNIQUE `unique_mk_semester_kelas_key` '
                .'(`kode_mk`, `semester_id`, `kelas_key`)'
            );
        }
    }

    /**
     * Composite index mengikuti query utama monitoring/report.
     */
    private function addQueryIndexes(): void
    {
        $indexes = [
            'attendances' => [
                'idx_att_user_tanggal' => '(`user_id`, `tanggal`)',
                'idx_att_jadwal_tanggal' => '(`jadwal_id`, `tanggal`)',
                'idx_att_mk_status' => '(`mata_kuliah_id`, `status`)',
            ],
            'jadwals' => [
                'idx_jadwal_hari_status' => '(`hari`, `status`)',
            ],
            'face_embeddings' => [
                'idx_embedding_user_status' => '(`user_id`, `status`)',
            ],
        ];

        foreach ($indexes as $table => $definitions) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($definitions as $name => $columns) {
                if ($this->indexExists($table, $name)) {
                    continue;
                }

                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` {$columns}");
            }
        }
    }

    private function constraintExists(string $table, string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $name)
            ->exists();
    }

    private function indexExists(string $table, string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->exists();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->checks() as $table => $constraints) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($constraints) as $name) {
                if ($this->constraintExists($table, $name)) {
                    DB::statement("ALTER TABLE `{$table}` DROP CHECK `{$name}`");
                }
            }
        }

        if (Schema::hasTable('mata_kuliahs') && Schema::hasColumn('mata_kuliahs', 'kelas_key')) {
            if ($this->indexExists('mata_kuliahs', 'unique_mk_semester_kelas_key')) {
                DB::statement('ALTER TABLE `mata_kuliahs` DROP INDEX `unique_mk_semester_kelas_key`');
            }
            DB::statement('ALTER TABLE `mata_kuliahs` DROP COLUMN `kelas_key`');
        }

        $indexes = [
            'attendances' => ['idx_att_user_tanggal', 'idx_att_jadwal_tanggal', 'idx_att_mk_status'],
            'jadwals' => ['idx_jadwal_hari_status'],
            'face_embeddings' => ['idx_embedding_user_status'],
        ];

        foreach ($indexes as $table => $names) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($names as $name) {
                if ($this->indexExists($table, $name)) {
                    DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
                }
            }
        }
    }
};
