<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_student', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id');
            $table->unsignedBigInteger('student_id');
            $table->enum('hubungan', ['ayah', 'ibu', 'wali']);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('parent_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('student_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['parent_id', 'student_id'], 'unique_parent_student');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_student');
    }
};
