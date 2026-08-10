<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->string('email', 100)->unique();
            $table->string('password', 255);
            $table->string('nim', 20)->unique()->nullable();
            $table->string('nidn', 20)->unique()->nullable();
            $table->string('nip', 30)->nullable();
            $table->string('no_hp', 20)->nullable();
            $table->string('tempat_lahir', 100)->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->enum('jenis_kelamin', ['L', 'P'])->nullable();
            $table->text('alamat')->nullable();
            $table->unsignedBigInteger('prodi_id')->nullable();
            $table->string('kelas', 10)->nullable();
            $table->year('angkatan')->nullable();
            $table->integer('semester')->nullable();
            $table->string('jabatan_fungsional', 50)->nullable();
            $table->string('pendidikan_terakhir', 50)->nullable();
            $table->string('bidang_keahlian', 255)->nullable();
            $table->string('foto_profil', 255)->nullable();
            $table->string('foto_enrollment', 255)->nullable();
            $table->string('tanda_tangan', 255)->nullable();
            $table->enum('status', ['aktif', 'nonaktif', 'do'])->default('aktif');
            $table->boolean('must_change_password')->default(true);
            $table->enum('enrollment_status', ['belum', 'pending', 'approved', 'rejected', 'not_required'])->default('belum');
            $table->text('fcm_token')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('prodi_id')->references('id')->on('prodis')->onDelete('set null');

            $table->index('prodi_id', 'idx_users_prodi');
            $table->index('nim', 'idx_users_nim');
            $table->index('nidn', 'idx_users_nidn');
            $table->index('status', 'idx_users_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
