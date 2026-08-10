<?php

namespace App\Http\Controllers\Api\Kaprodi;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Services\AuthorizationService;
use App\Services\LeaveApprovalService;
use App\Services\PrivateFileUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveRequestController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $user = $request->user();

        $query = LeaveRequest::with(['user:id,nama,nim,kelas', 'mataKuliah:id,kode_mk,nama']);

        // Filter by prodi
        if (($prodiId = $authorization->requiredApprovalProdi($user)) !== null) {
            $query->whereHas('user', fn ($q) => $q->where('prodi_id', $prodiId));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            $query->where('status', 'pending');
        }

        $data = $query->orderByDesc('created_at')->paginate($this->resolvePerPage($request));
        $data->getCollection()->each(function (LeaveRequest $item): void {
            $item->file_surat_url = app(PrivateFileUrlService::class)->leaveDocument($item);
        });

        return $this->paginated($data);
    }

    public function approve(Request $request, int $id, AuthorizationService $authorization, LeaveApprovalService $workflow): JsonResponse
    {
        $leave = LeaveRequest::with(['user', 'mataKuliah'])->findOrFail($id);
        $authorization->assertCanApproveProdiResource($request->user(), $leave->user?->prodi_id);
        abort_unless($leave->mataKuliah?->prodi_id === $leave->user?->prodi_id, 403);

        $workflow->approve($request->user(), $leave->id, $request);

        return $this->success(message: 'Izin berhasil disetujui');
    }

    public function reject(Request $request, int $id, AuthorizationService $authorization, LeaveApprovalService $workflow): JsonResponse
    {
        $request->validate([
            'alasan' => 'required|string|max:500',
        ]);

        $leave = LeaveRequest::with(['user', 'mataKuliah'])->findOrFail($id);
        $authorization->assertCanApproveProdiResource($request->user(), $leave->user?->prodi_id);
        abort_unless($leave->mataKuliah?->prodi_id === $leave->user?->prodi_id, 403);

        $workflow->reject($request->user(), $leave->id, $request->alasan, $request);

        return $this->success(message: 'Izin ditolak');
    }
}
