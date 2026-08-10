<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\LeaveRequest;
use App\Models\ReEnrollmentRequest;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateFileController extends Controller
{
    public function enrollmentPhoto(Request $request, User $user): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor->id === $user->id || $actor->hasAnyRole(['kaprodi', 'super_admin']), 403);
        if ($actor->id !== $user->id) {
            app(AuthorizationService::class)->assertCanApproveProdiResource($actor, $user->prodi_id);
        }

        return $this->serve($request, 'face', $user->foto_enrollment, $user, 'enrollment_photo_accessed');
    }

    public function reEnrollmentPhoto(Request $request, ReEnrollmentRequest $reEnrollment): StreamedResponse
    {
        $reEnrollment->loadMissing('user');
        if ($request->user()->id !== $reEnrollment->user_id) {
            app(AuthorizationService::class)->assertCanApproveProdiResource($request->user(), $reEnrollment->user?->prodi_id);
        }

        return $this->serve($request, 'face', $reEnrollment->foto_baru, $reEnrollment, 're_enrollment_photo_accessed');
    }

    public function leaveDocument(Request $request, LeaveRequest $leaveRequest): StreamedResponse
    {
        $leaveRequest->loadMissing(['user', 'mataKuliah']);
        $actor = $request->user();
        abort_unless($actor->id === $leaveRequest->user_id || $actor->hasAnyRole(['kaprodi', 'super_admin']), 403);
        if ($actor->id !== $leaveRequest->user_id) {
            app(AuthorizationService::class)->assertCanApproveProdiResource($actor, $leaveRequest->user?->prodi_id);
            abort_unless($leaveRequest->mataKuliah?->prodi_id === $leaveRequest->user?->prodi_id, 403);
        }

        return $this->serve($request, 'documents', $leaveRequest->file_surat, $leaveRequest, 'leave_document_accessed');
    }

    private function serve(Request $request, string $disk, ?string $path, object $model, string $action): StreamedResponse
    {
        abort_unless($path && Storage::disk($disk)->exists($path), 404);
        AuditTrail::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'model_type' => $model::class,
            'model_id' => $model->id,
            'old_values' => [],
            'new_values' => [],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return Storage::disk($disk)->response($path, null, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
