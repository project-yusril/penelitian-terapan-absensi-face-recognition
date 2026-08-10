<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestModeController extends Controller
{
    /**
     * Get current test mode status
     */
    /**
     * Key tunggal yang dibaca oleh AttendanceController::buildResearchMetadata
     * dan Web\TestModeController. Sebelumnya API memakai 'test_mode_active'
     * sehingga toggle via API tidak berpengaruh pada alur pelabelan.
     */
    private const KEY = 'test_mode_enabled';

    public function status(): JsonResponse
    {
        $value = SystemSetting::where('key', self::KEY)->value('value');
        $isActive = $value === '1' || $value === 'true';

        return $this->success([
            'test_mode_active' => $isActive,
        ]);
    }

    /**
     * Toggle test mode on/off
     */
    public function toggle(Request $request): JsonResponse
    {
        $request->validate([
            'active' => 'required|boolean',
        ]);

        SystemSetting::updateOrCreate(
            ['key' => self::KEY],
            [
                'group' => 'testing',
                'value' => $request->active ? '1' : '0',
                'type' => 'boolean',
                'description' => 'Mode pengujian FAR/FRR (pelabelan log verifikasi wajah)',
            ]
        );

        $status = $request->active ? 'diaktifkan' : 'dinonaktifkan';

        return $this->success([
            'test_mode_active' => $request->active,
        ], "Mode pengujian {$status}");
    }

    /**
     * Get test mode logs (attendance logs with is_test_mode context)
     */
    public function logs(Request $request): JsonResponse
    {
        $query = AttendanceLog::with(['attendance.user:id,nama,nim', 'attendance.mataKuliah:id,kode_mk,nama'])
            ->whereJsonContains('metadata->is_test_mode', true);

        if ($request->filled('label')) {
            $query->whereJsonContains('metadata->label', $request->label);
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }

        $data = $query->orderByDesc('created_at')->paginate($this->resolvePerPage($request, 20));

        return $this->paginated($data);
    }

    /**
     * Label attendance log sebagai genuine atau impostor
     */
    public function labelLog(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'label' => 'required|in:genuine,impostor',
        ]);

        $log = AttendanceLog::findOrFail($id);

        $metadata = $log->metadata ?? [];
        $metadata['label'] = $request->label;
        $metadata['labeled_by'] = $request->user()->id;
        $metadata['labeled_at'] = now()->toISOString();

        // Sinkron kolom enum terindeks (genuine|impostor) dengan metadata->label.
        $log->update([
            'metadata' => $metadata,
            'is_test_mode' => true,
            'test_type' => $request->label,
        ]);

        return $this->success([
            'log' => $log->fresh(),
        ], "Log berhasil dilabeli sebagai {$request->label}");
    }

    /**
     * Get summary statistik test mode
     */
    public function summary(): JsonResponse
    {
        $totalLogs = AttendanceLog::whereJsonContains('metadata->is_test_mode', true)->count();
        $genuineLogs = AttendanceLog::whereJsonContains('metadata->label', 'genuine')->count();
        $impostorLogs = AttendanceLog::whereJsonContains('metadata->label', 'impostor')->count();
        $unlabeledLogs = $totalLogs - $genuineLogs - $impostorLogs;

        return $this->success([
            'total_logs' => $totalLogs,
            'genuine' => $genuineLogs,
            'impostor' => $impostorLogs,
            'unlabeled' => $unlabeledLogs,
        ]);
    }
}
