<?php

namespace App\Http\Controllers\Api\Kajur;

use App\Http\Controllers\Controller;
use App\Models\SpRecord;
use App\Services\SpWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SpRecord::with(['user:id,nama,nim,kelas,prodi_id', 'user.prodi:id,kode,nama', 'semester']);

        if ($request->filled('level')) {
            $query->where('sp_level', $request->level);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            // Default: yang menunggu tanda tangan kajur (sudah ditandatangani kaprodi)
            $query->where('status', 'menunggu_kajur');
        }

        if ($request->filled('prodi_id')) {
            $query->whereHas('user', fn ($q) => $q->where('prodi_id', $request->prodi_id));
        }

        $data = $query->orderByDesc('created_at')->paginate($this->resolvePerPage($request));

        return $this->paginated($data);
    }

    /**
     * Kajur menandatangani SP (Diketahui)
     */
    public function sign(Request $request, int $id, SpWorkflowService $workflow): JsonResponse
    {
        $workflow->signKajur($request->user(), $id, $request);

        return $this->success(message: 'SP berhasil ditandatangani. Status: FINAL');
    }

    /**
     * Detail SP record (rincian per MK + timeline approval)
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $spRecord = SpRecord::with([
            'user:id,nama,nim,kelas,prodi_id',
            'user.prodi:id,kode,nama',
            'semester',
            'generatedBy:id,nama',
            'signedKaprodiBy:id,nama',
            'signedKajurBy:id,nama',
        ])->findOrFail($id);

        return $this->success($spRecord);
    }
}
