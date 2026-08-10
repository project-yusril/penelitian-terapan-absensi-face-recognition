<?php

namespace App\Http\Controllers\Api\Kaprodi;

use App\Http\Controllers\Controller;
use App\Models\SpRecord;
use App\Services\AuthorizationService;
use App\Services\SpWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $user = $request->user();

        $query = SpRecord::with(['user:id,nama,nim,kelas', 'semester']);

        // Filter by prodi kaprodi
        if (($prodiId = $authorization->requiredApprovalProdi($user)) !== null) {
            $query->whereHas('user', fn ($q) => $q->where('prodi_id', $prodiId));
        }

        if ($request->filled('level')) {
            $query->where('sp_level', $request->level);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $data = $query->orderByDesc('created_at')->paginate($this->resolvePerPage($request));

        return $this->paginated($data);
    }

    /**
     * Kaprodi menandatangani SP
     */
    public function sign(Request $request, int $id, AuthorizationService $authorization, SpWorkflowService $workflow): JsonResponse
    {
        $workflow->signKaprodi($request->user(), $id, $request);

        return $this->success(message: 'SP berhasil ditandatangani oleh Kaprodi');
    }

    /**
     * Detail SP record (rincian per MK + timeline approval)
     */
    public function show(Request $request, int $id, AuthorizationService $authorization): JsonResponse
    {
        $user = $request->user();
        $query = SpRecord::with([
            'user:id,nama,nim,kelas,prodi_id',
            'user.prodi:id,kode,nama',
            'semester',
            'generatedBy:id,nama',
            'signedKaprodiBy:id,nama',
            'signedKajurBy:id,nama',
        ]);

        $spRecord = $query->findOrFail($id);
        $authorization->assertCanApproveProdiResource($user, $spRecord->user?->prodi_id);

        return $this->success($spRecord);
    }

    /**
     * Cancel SP record (jika ada kesalahan)
     */
    public function cancel(Request $request, int $id, AuthorizationService $authorization, SpWorkflowService $workflow): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $spRecord = $workflow->cancel($request->user(), $id, $request->reason, $request);

        return $this->success($spRecord->fresh(), 'SP berhasil dibatalkan');
    }
}
