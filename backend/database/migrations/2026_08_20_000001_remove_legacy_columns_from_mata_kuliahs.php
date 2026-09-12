<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RENCANA 2 (5.3): hapus kolom lama `mata_kuliahs` yang sudah dipindah ke
 * `jadwals` — `dosen_id`, `kelas`, dan generated column `kelas_key` beserta
 * unique constraint `unique_mk_semester_kelas_key`.
 *
 * `users.kelas` & `users.semester` DI-PERTAHANKAN sebagai snapshot (5.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mata_kuliahs') && Schema::hasColumn('mata_kuliahs', 'kelas_key')) {
            if ($this->indexExists('mata_kuliahs', 'unique_mk_semester_kelas_key')) {
                DB::statement('ALTER TABLE `mata_kuliahs` DROP INDEX `unique_mk_semester_kelas_key`');
            }
            DB::statement('ALTER TABLE `mata_kuliahs` DROP COLUMN `kelas_key`');
        }

        // Drop unique lama (kode_mk, semester_id, kelas) SEBELUM kolom `kelas`
        // dihapus — kolom kelas ikut dalam index, sehingga MySQL menolak drop
        // bila index masih ada dan data menjadi duplikat setelah normalisasi.
        if ($this->indexExists('mata_kuliahs', 'unique_mk_semester_kelas')) {
            DB::statement('ALTER TABLE `mata_kuliahs` DROP INDEX `unique_mk_semester_kelas`');
        }

        Schema::table('mata_kuliahs', function (\Illuminate\Database\Schema\Blueprint $table) {
            if (Schema::hasColumn('mata_kuliahs', 'dosen_id')) {
                $table->dropForeign(['dosen_id']);
                $table->dropColumn('dosen_id');
            }
            if (Schema::hasColumn('mata_kuliahs', 'kelas')) {
                $table->dropColumn('kelas');
            }
        });

        // RENCANA 2: master kurikulum — satu kode MK per semester per prodi.
        if (! $this->indexExists('mata_kuliahs', 'unique_mk_semester')) {
            DB::statement(
                'ALTER TABLE `mata_kuliahs` ADD UNIQUE `unique_mk_semester` (`kode_mk`, `semester_id`, `prodi_id`)'
            );
        }
    }

    public function down(): void
    {
        Schema::table('mata_kuliahs', function (\Illuminate\Database\Schema\Blueprint $table) {
            if (! Schema::hasColumn('mata_kuliahs', 'dosen_id')) {
                $table->unsignedBigInteger('dosen_id')->nullable()->after('prodi_id');
                $table->foreign('dosen_id')->references('id')->on('users')->onDelete('set null');
            }
            if (! Schema::hasColumn('mata_kuliahs', 'kelas')) {
                $table->string('kelas', 10)->nullable()->after('dosen_id');
            }
        });

        // Pulihkan unique lama & generated column agar skema identik dengan
        // sebelum migrasi (rollback simetris).
        if ($this->indexExists('mata_kuliahs', 'unique_mk_semester')) {
            DB::statement('ALTER TABLE `mata_kuliahs` DROP INDEX `unique_mk_semester`');
        }
        if (! $this->indexExists('mata_kuliahs', 'unique_mk_semester_kelas')) {
            DB::statement(
                'ALTER TABLE `mata_kuliahs` ADD UNIQUE `unique_mk_semester_kelas` (`kode_mk`, `semester_id`, `kelas`)'
            );
        }
        if (! Schema::hasColumn('mata_kuliahs', 'kelas_key')) {
            DB::statement(
                'ALTER TABLE `mata_kuliahs` ADD COLUMN `kelas_key` VARCHAR(10) '
                ."GENERATED ALWAYS AS (COALESCE(`kelas`, '')) STORED"
            );
        }
        if (! $this->indexExists('mata_kuliahs', 'unique_mk_semester_kelas_key')) {
            DB::statement(
                'ALTER TABLE `mata_kuliahs` ADD UNIQUE `unique_mk_semester_kelas_key` '
                .'(`kode_mk`, `semester_id`, `kelas_key`)'
            );
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->exists();
    }
};
