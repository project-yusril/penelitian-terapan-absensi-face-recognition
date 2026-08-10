<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SpRecord;
use App\Services\AuthorizationService;
use App\Services\SpDocumentService;
use App\Services\SpWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpController extends Controller
{
    protected SpDocumentService $spDocumentService;

    public function __construct(SpDocumentService $spDocumentService)
    {
        $this->spDocumentService = $spDocumentService;
    }

    /**
     * List semua SP records
     */
    public function index(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $query = SpRecord::with(['user:id,nama,nim,kelas,prodi_id', 'user.prodi:id,kode,nama', 'semester']);
        if (! $authorization->isSuperAdmin($request->user())) {
            abort_unless($request->user()->prodi_id, 403);
            $query->whereHas('user', fn ($q) => $q->where('prodi_id', $request->user()->prodi_id));
        }

        if ($request->filled('level')) {
            $query->where('sp_level', $request->level);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('prodi_id')) {
            $query->whereHas('user', fn ($q) => $q->where('prodi_id', $request->prodi_id));
        }

        if ($request->filled('semester_id')) {
            $query->where('semester_id', $request->semester_id);
        }

        $data = $query->orderByDesc('created_at')->paginate($this->resolvePerPage($request));

        return $this->paginated($data);
    }

    /**
     * Generate SP document
     */
    public function generate(Request $request, SpWorkflowService $workflow): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'level' => 'required|in:sp1,sp2,sp3,do',
            'semester_id' => 'nullable|exists:semesters,id',
        ]);

        $spRecord = $workflow->generate($request->user(), $request->user_id, $request->level, $request->semester_id, $request);

        $spRecord->load(['user:id,nama,nim,kelas', 'semester']);

        return $this->created($spRecord, 'Dokumen SP berhasil di-generate');
    }

    /**
     * Send SP to kaprodi
     */
    public function sendToKaprodi(Request $request, int $id, SpWorkflowService $workflow): JsonResponse
    {
        $workflow->sendToKaprodi($request->user(), $id, $request);

        return $this->success(message: 'SP berhasil dikirim ke Kaprodi untuk ditandatangani');
    }

    /**
     * Download SP document
     */
    public function download(Request $request, int $id, AuthorizationService $authorization)
    {
        $spRecord = SpRecord::findOrFail($id);
        if (! $authorization->isSuperAdmin($request->user())) {
            $authorization->assertCanAccessProdi($request->user(), $spRecord->user?->prodi_id, ['admin_jurusan', 'admin_prodi']);
        }

        if (! $spRecord->document_path) {
            return $this->error('Dokumen belum di-generate', 404);
        }

        $fullPath = storage_path("app/public/{$spRecord->document_path}");

        if (! file_exists($fullPath)) {
            return $this->error('File dokumen tidak ditemukan', 404);
        }

        return response()->download($fullPath, basename($spRecord->document_path));
    }

    /**
     * Detail SP record (rincian per MK + timeline approval)
     */
    public function show(Request $request, int $id, AuthorizationService $authorization): JsonResponse
    {
        $spRecord = SpRecord::with([
            'user:id,nama,nim,kelas,prodi_id',
            'user.prodi:id,kode,nama',
            'semester',
            'generatedBy:id,nama',
            'signedKaprodiBy:id,nama',
            'signedKajurBy:id,nama',
        ])->findOrFail($id);
        if (! $authorization->isSuperAdmin($request->user())) {
            $authorization->assertCanAccessProdi($request->user(), $spRecord->user?->prodi_id, ['admin_jurusan', 'admin_prodi']);
        }

        return $this->success($spRecord);
    }

    /**
     * Cancel SP record (jika ada kesalahan)
     */
    public function cancel(Request $request, int $id, SpWorkflowService $workflow): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $spRecord = $workflow->cancel($request->user(), $id, $request->reason, $request);

        return $this->success($spRecord->fresh(), 'SP berhasil dibatalkan');
    }
}
