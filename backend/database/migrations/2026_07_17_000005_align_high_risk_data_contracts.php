<?php

use App\Services\BiometricEncryptionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'foto_baru' => fn (Blueprint $table) => $table->string('foto_baru', 255)->nullable()->after('keterangan'),
            'new_embedding' => fn (Blueprint $table) => $table->json('new_embedding')->nullable()->after('foto_baru'),
            'new_embedding_ciphertext' => fn (Blueprint $table) => $table->text('new_embedding_ciphertext')->nullable()->after('new_embedding'),
            'new_embedding_key_id' => fn (Blueprint $table) => $table->string('new_embedding_key_id', 100)->nullable()->after('new_embedding_ciphertext'),
        ];
        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('re_enrollment_requests', $column)) {
                Schema::table('re_enrollment_requests', $definition);
            }
        }

        $encryption = app(BiometricEncryptionService::class);
        DB::table('re_enrollment_requests')
            ->whereNotNull('new_embedding')
            ->whereNull('new_embedding_ciphertext')
            ->orderBy('id')
            ->each(function (object $row) use ($encryption): void {
                $embedding = json_decode($row->new_embedding, true);
                if (! is_array($embedding) || $embedding === []) {
                    throw new RuntimeException("Embedding legacy tidak valid pada re-enrollment {$row->id}");
                }
                DB::table('re_enrollment_requests')->where('id', $row->id)->update([
                    'new_embedding' => json_encode([]),
                    'new_embedding_ciphertext' => $encryption->encrypt(array_map('floatval', $embedding)),
                    'new_embedding_key_id' => config('biometric.key_id'),
                ]);
            });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY enrollment_status ENUM('belum','pending','approved','rejected','not_required') NOT NULL DEFAULT 'belum'");
        }

        foreach (['re_enrollment_requests', 'leave_requests'] as $table) {
            if (Schema::hasColumn($table, 'rejection_reason')) {
                DB::table($table)->whereNull('rejected_reason')->update([
                    'rejected_reason' => DB::raw('rejection_reason'),
                ]);
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('rejection_reason'));
            }
        }

        $this->privatizeExistingFiles('face', ['enrollment', 're-enrollment']);
        $this->privatizeExistingFiles('documents', ['leave-requests']);
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback ditolak karena dapat membuang data biometrik dan status user yang valid.');
    }

    private function privatizeExistingFiles(string $targetDisk, array $directories): void
    {
        foreach ($directories as $directory) {
            foreach (Storage::disk('public')->allFiles($directory) as $path) {
                if (Storage::disk($targetDisk)->exists($path)) {
                    $sourceHash = hash('sha256', Storage::disk('public')->get($path));
                    $targetHash = hash('sha256', Storage::disk($targetDisk)->get($path));
                    if (! hash_equals($sourceHash, $targetHash)) {
                        throw new RuntimeException("Konflik file privat pada path: {$path}");
                    }
                } else {
                    $stream = Storage::disk('public')->readStream($path);
                    if ($stream === null || ! Storage::disk($targetDisk)->writeStream($path, $stream)) {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                        throw new RuntimeException("Gagal memindahkan file privat: {$path}");
                    }
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                if (! Storage::disk('public')->delete($path) || Storage::disk('public')->exists($path)) {
                    throw new RuntimeException("Gagal menghapus salinan publik: {$path}");
                }
            }
        }
    }
};
