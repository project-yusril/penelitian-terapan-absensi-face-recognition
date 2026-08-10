<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M-19: ubah lifecycle master historis menjadi RESTRICT.
 *
 * Sebelumnya FK dari rekam akademik (attendances, sp_records, leave_requests,
 * alpha_accumulations, attendance_logs, face_embeddings) ke master
 * (users, jadwals, mata_kuliahs, semesters) memakai ON DELETE CASCADE.
 * Akibatnya satu hard delete master dapat menghapus sejarah kehadiran dan
 * dokumen disipliner secara transitif.
 *
 * Migration ini mengganti CASCADE menjadi RESTRICT untuk spine historis
 * sehingga database menolak hard delete master yang masih memiliki riwayat.
 * Arsip (soft delete) tetap menjadi jalur normal untuk menonaktifkan master;
 * kolom aktor (approved_by/overridden_by/generated_by/signed_*) tetap SET NULL
 * karena bukan tulang punggung riwayat.
 *
 * Idempotent: setiap FK hanya diubah bila aturan delete-nya masih CASCADE,
 * sehingga aman dijalankan ulang serta rollback/re-migrate dan migrate:fresh.
 */
return new class extends Migration
{
    /**
     * @return array<int, array{table:string, column:string, references:string, constraint:string}>
     */
    private function historicalForeignKeys(): array
    {
        return [
            ['table' => 'attendances', 'column' => 'user_id', 'references' => 'users', 'constraint' => 'attendances_user_id_foreign'],
            ['table' => 'attendances', 'column' => 'jadwal_id', 'references' => 'jadwals', 'constraint' => 'attendances_jadwal_id_foreign'],
            ['table' => 'attendances', 'column' => 'mata_kuliah_id', 'references' => 'mata_kuliahs', 'constraint' => 'attendances_mata_kuliah_id_foreign'],
            ['table' => 'sp_records', 'column' => 'user_id', 'references' => 'users', 'constraint' => 'sp_records_user_id_foreign'],
            ['table' => 'sp_records', 'column' => 'semester_id', 'references' => 'semesters', 'constraint' => 'sp_records_semester_id_foreign'],
            ['table' => 'leave_requests', 'column' => 'user_id', 'references' => 'users', 'constraint' => 'leave_requests_user_id_foreign'],
            ['table' => 'leave_requests', 'column' => 'mata_kuliah_id', 'references' => 'mata_kuliahs', 'constraint' => 'leave_requests_mata_kuliah_id_foreign'],
            ['table' => 'alpha_accumulations', 'column' => 'user_id', 'references' => 'users', 'constraint' => 'alpha_accumulations_user_id_foreign'],
            ['table' => 'alpha_accumulations', 'column' => 'semester_id', 'references' => 'semesters', 'constraint' => 'alpha_accumulations_semester_id_foreign'],
            ['table' => 'attendance_logs', 'column' => 'user_id', 'references' => 'users', 'constraint' => 'attendance_logs_user_id_foreign'],
            ['table' => 'face_embeddings', 'column' => 'user_id', 'references' => 'users', 'constraint' => 'face_embeddings_user_id_foreign'],
        ];
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->historicalForeignKeys() as $fk) {
            $this->recreateForeignKey($fk, 'RESTRICT');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->historicalForeignKeys() as $fk) {
            $this->recreateForeignKey($fk, 'CASCADE');
        }
    }

    /**
     * @param  array{table:string, column:string, references:string, constraint:string}  $fk
     */
    private function recreateForeignKey(array $fk, string $rule): void
    {
        if (! Schema::hasTable($fk['table']) || ! Schema::hasTable($fk['references'])) {
            return;
        }

        if ($this->deleteRule($fk['table'], $fk['constraint']) === $rule) {
            return;
        }

        if ($this->constraintExists($fk['table'], $fk['constraint'])) {
            DB::statement("ALTER TABLE `{$fk['table']}` DROP FOREIGN KEY `{$fk['constraint']}`");
        }

        DB::statement(
            "ALTER TABLE `{$fk['table']}` ADD CONSTRAINT `{$fk['constraint']}` "
            ."FOREIGN KEY (`{$fk['column']}`) REFERENCES `{$fk['references']}` (`id`) "
            ."ON DELETE {$rule} ON UPDATE NO ACTION"
        );
    }

    private function constraintExists(string $table, string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $name)
            ->exists();
    }

    private function deleteRule(string $table, string $constraint): ?string
    {
        $row = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->first();

        return $row->DELETE_RULE ?? null;
    }
};
