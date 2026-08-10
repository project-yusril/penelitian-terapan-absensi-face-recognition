<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('attendances', 'catatan')) {
                $table->text('catatan')->nullable()->after('approval_status');
            }

            if (! Schema::hasColumn('attendances', 'override_at')) {
                $table->timestamp('override_at')->nullable()->after('override_reason');
            }
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('attendance_logs', 'status_before')) {
                $table->string('status_before', 50)->nullable()->after('action');
            }

            if (! Schema::hasColumn('attendance_logs', 'status_after')) {
                $table->string('status_after', 50)->nullable()->after('status_before');
            }

            if (! Schema::hasColumn('attendance_logs', 'keterangan')) {
                $table->text('keterangan')->nullable()->after('error_message');
            }

            if (! Schema::hasColumn('attendance_logs', 'metadata')) {
                $table->json('metadata')->nullable()->after('keterangan');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            foreach (['metadata', 'keterangan', 'status_after', 'status_before'] as $column) {
                if (Schema::hasColumn('attendance_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('attendances', function (Blueprint $table) {
            foreach (['override_at', 'catatan'] as $column) {
                if (Schema::hasColumn('attendances', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
