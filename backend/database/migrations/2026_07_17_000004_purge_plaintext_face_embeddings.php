<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('face_embeddings')->whereNotNull('embedding_ciphertext')->update(['embedding' => json_encode([])]);
    }

    public function down(): void
    {
        throw new RuntimeException('Plaintext biometrik tidak boleh dipulihkan melalui rollback');
    }
};
