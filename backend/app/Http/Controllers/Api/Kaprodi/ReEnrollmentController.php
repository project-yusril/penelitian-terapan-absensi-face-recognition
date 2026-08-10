<?php

namespace App\Http\Controllers\Api\Kaprodi;

use App\Http\Controllers\Controller;
use App\Models\ReEnrollmentRequest;
use App\Services\AuthorizationService;
use App\Services\PrivateFileUrlService;
use App\Services\ReEnrollmentWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReEnrollmentController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $user = $request->user();

        $query = ReEnrollmentRequest::with('user:id,nama,nim,kelas,prodi_id')
            ->when(($prodiId = $authorization->requiredApprovalProdi($user)) !== null,
                fn ($query) => $query->whereHas('user', fn ($q) => $q->where('prodi_id', $prodiId)));

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            $query->where('status', 'pending');
        }

        $data = $query->orderByDesc('created_at')->paginate($this->resolvePerPage($request));
        $data->getCollection()->each(function (ReEnrollmentRequest $item): void {
            $item->foto_baru_url = app(PrivateFileUrlService::class)->reEnrollmentPhoto($item);
        });

        return $this->paginated($data);
    }

    public function approve(Request $request, int $id, AuthorizationService $authorization, ReEnrollmentWorkflowService $workflow): JsonResponse
    {
        $reEnrollment = ReEnrollmentRequest::with('user')->findOrFail($id);
        $authorization->assertCanApproveProdiResource($request->user(), $reEnrollment->user?->prodi_id);

        if ($reEnrollment->status !== 'pending') {
            return $this->error('Request ini sudah diproses', 422);
        }

        $workflow->approve($request->user(), $reEnrollment->id, $request);

        return $this->success(message: 'Re-enrollment berhasil disetujui');
    }

    public function reject(Request $request, int $id, AuthorizationService $authorization, ReEnrollmentWorkflowService $workflow): JsonResponse
    {
        $request->validate([
            'alasan' => 'required|string|max:500',
        ]);

        $reEnrollment = ReEnrollmentRequest::with('user')->findOrFail($id);
        $authorization->assertCanApproveProdiResource($request->user(), $reEnrollment->user?->prodi_id);

        if ($reEnrollment->status !== 'pending') {
            return $this->error('Request ini sudah diproses', 422);
        }

        $workflow->reject($request->user(), $reEnrollment->id, $request->alasan, $request);

        return $this->success(message: 'Re-enrollment ditolak');
    }
}
