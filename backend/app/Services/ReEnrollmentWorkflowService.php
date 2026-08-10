<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\FaceEmbedding;
use App\Models\ReEnrollmentRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReEnrollmentWorkflowService
{
    public function approve(User $actor, int $requestId, Request $httpRequest): void
    {
        $oldPhoto = DB::transaction(function () use ($actor, $requestId, $httpRequest): ?string {
            $reEnrollment = ReEnrollmentRequest::with('user')->lockForUpdate()->findOrFail($requestId);
            app(AuthorizationService::class)->assertCanApproveProdiResource($actor, $reEnrollment->user?->prodi_id);
            abort_unless($reEnrollment->status === 'pending', 409, 'Request ini sudah diproses');
            abort_unless($reEnrollment->foto_baru && Storage::disk('face')->exists($reEnrollment->foto_baru), 422, 'Foto re-enrollment tidak ditemukan');

            $user = User::whereKey($reEnrollment->user_id)->lockForUpdate()->firstOrFail();
            abort_if(ReEnrollmentRequest::where('user_id', $user->id)->where('id', '>', $reEnrollment->id)->exists(), 409, 'Terdapat request re-enrollment yang lebih baru');
            $version = (int) FaceEmbedding::where('user_id', $user->id)->max('version') + 1;
            FaceEmbedding::where('user_id', $user->id)->where('status', 'approved')->update(['status' => 'inactive']);
            $embedding = FaceEmbedding::create([
                'user_id' => $user->id,
                'embedding' => $reEnrollment->new_embedding,
                'version' => $version,
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'liveness_passed' => true,
            ]);
            $oldPhoto = $user->foto_enrollment;
            $user->update(['foto_enrollment' => $reEnrollment->foto_baru, 'enrollment_status' => 'approved']);
            $reEnrollment->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit($actor, $reEnrollment, 're_enrollment_approved', ['embedding_id' => $embedding->id, 'version' => $version], $httpRequest);

            return $oldPhoto !== $reEnrollment->foto_baru ? $oldPhoto : null;
        });

        if ($oldPhoto) {
            Storage::disk('face')->delete($oldPhoto);
        }
    }

    public function reject(User $actor, int $requestId, string $reason, Request $httpRequest): void
    {
        $photo = DB::transaction(function () use ($actor, $requestId, $reason, $httpRequest): ?string {
            $reEnrollment = ReEnrollmentRequest::with('user')->lockForUpdate()->findOrFail($requestId);
            app(AuthorizationService::class)->assertCanApproveProdiResource($actor, $reEnrollment->user?->prodi_id);
            abort_unless($reEnrollment->status === 'pending', 409, 'Request ini sudah diproses');
            $reEnrollment->update([
                'status' => 'rejected', 'rejected_reason' => $reason,
                'approved_by' => $actor->id, 'approved_at' => now(),
            ]);
            $this->audit($actor, $reEnrollment, 're_enrollment_rejected', ['reason' => $reason], $httpRequest);

            return $reEnrollment->foto_baru;
        });

        if ($photo) {
            Storage::disk('face')->delete($photo);
        }
    }

    private function audit(User $actor, ReEnrollmentRequest $request, string $action, array $values, Request $httpRequest): void
    {
        AuditTrail::create([
            'user_id' => $actor->id, 'action' => $action, 'model_type' => ReEnrollmentRequest::class,
            'model_id' => $request->id, 'old_values' => ['status' => 'pending'], 'new_values' => $values,
            'ip_address' => $httpRequest->ip(), 'user_agent' => $httpRequest->userAgent(),
        ]);
    }
}
