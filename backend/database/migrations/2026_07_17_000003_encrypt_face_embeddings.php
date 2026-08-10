<?php

use App\Services\BiometricEncryptionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('face_embeddings', function (Blueprint $table) {
            $table->longText('embedding_ciphertext')->nullable()->after('embedding');
            $table->string('embedding_key_id', 50)->nullable()->after('embedding_ciphertext');
        });
        $crypto = app(BiometricEncryptionService::class);
        DB::table('face_embeddings')->whereNull('embedding_ciphertext')->orderBy('id')->chunkById(100, function ($rows) use ($crypto) {
            foreach ($rows as $row) {
                $embedding = is_string($row->embedding) ? json_decode($row->embedding, true) : $row->embedding;
                DB::table('face_embeddings')->where('id', $row->id)->update([
                    'embedding_ciphertext' => $crypto->encrypt($embedding),
                    'embedding_key_id' => config('biometric.key_id'),
                    'embedding' => json_encode([]),
                ]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migration enkripsi biometrik tidak dapat di-rollback; restore backup terverifikasi');
    }
};
