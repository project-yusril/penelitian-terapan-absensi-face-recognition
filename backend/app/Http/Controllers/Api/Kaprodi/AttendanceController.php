<?php

namespace App\Http\Controllers\Api\Kaprodi;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\AttendanceWorkflowService;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    /**
     * Override status kehadiran (kaprodi)
     */
    public function override(Request $request, int $id, AuthorizationService $authorization, AttendanceWorkflowService $workflow): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:hadir,hadir_terlambat,alpha,izin,sakit',
            'alasan' => 'required|string|max:500',
        ]);

        $user = $request->user();

        $attendance = Attendance::with(['user', 'mataKuliah'])->findOrFail($id);
        $authorization->assertCanApproveProdiResource($user, $attendance->user?->prodi_id);
        abort_unless($attendance->mataKuliah?->prodi_id === $attendance->user?->prodi_id, 403);

        $oldStatus = $attendance->status;

        $workflow->override($user, $attendance->id, $request->status, $request->alasan, $request);

        return $this->success([
            'old_status' => $oldStatus,
            'new_status' => $request->status,
        ], 'Status kehadiran berhasil diubah');
    }
}
