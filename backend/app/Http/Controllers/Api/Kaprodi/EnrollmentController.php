<?php

namespace App\Http\Controllers\Api\Kaprodi;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\EnrollmentWorkflowService;
use App\Services\PrivateFileUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    private const CONFLICT = [
        'code' => 'BIOMETRIC_CONFLICT',
        'message' => 'Data biometrik tidak dapat digunakan untuk pendaftaran.',
    ];

    /**
     * List enrollment pending
     */
    public function index(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $user = $request->user();

        $query = User::where('enrollment_status', 'pending')
            ->whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'));

        // Filter by prodi kaprodi
        if (($prodiId = $authorization->requiredApprovalProdi($user)) !== null) {
            $query->where('prodi_id', $prodiId);
        }

        $data = $query->select('id', 'nama', 'nim', 'email', 'kelas', 'foto_enrollment', 'enrollment_status', 'created_at')
            ->orderByDesc('updated_at')
            ->paginate($this->resolvePerPage($request));

        // Append foto URL
        $data->getCollection()->transform(function ($item) {
            $item->foto_enrollment_url = $item->foto_enrollment
                ? app(PrivateFileUrlService::class)->enrollmentPhoto($item)
                : null;
            $item->makeHidden('foto_enrollment');

            return $item;
        });

        return $this->paginated($data);
    }

    /**
     * Approve enrollment
     */
    public function approve(Request $request, int $id, AuthorizationService $authorization, EnrollmentWorkflowService $workflow): JsonResponse
    {
        $user = User::findOrFail($id);
        $authorization->assertCanApproveProdiResource($request->user(), $user->prodi_id);
        abort_unless($user->hasRole('mahasiswa'), 403);

        if ($user->enrollment_status !== 'pending') {
            return $this->error('User ini tidak dalam status pending enrollment', 422);
        }

        if (! $workflow->approve($request->user(), $user->id, $request)) {
            return response()->json(self::CONFLICT, 409)->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        return $this->success(message: 'Enrollment berhasil disetujui');
    }

    /**
     * Reject enrollment
     */
    public function reject(Request $request, int $id, AuthorizationService $authorization, EnrollmentWorkflowService $workflow): JsonResponse
    {
        $request->validate([
            'alasan' => 'required|string|max:500',
        ]);

        $user = User::findOrFail($id);
        $authorization->assertCanApproveProdiResource($request->user(), $user->prodi_id);
        abort_unless($user->hasRole('mahasiswa'), 403);

        if ($user->enrollment_status !== 'pending') {
            return $this->error('User ini tidak dalam status pending enrollment', 422);
        }

        $workflow->reject($request->user(), $user->id, $request->alasan, $request);

        return $this->success(message: 'Enrollment ditolak');
    }
}
