<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\FaceEmbedding;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnrollmentWorkflowService
{
    public function __construct(
        private AuthorizationService $authorization,
        private BiometricDuplicateService $duplicates,
        private BiometricLockService $lock,
    ) {}

    public function approve(User $actor, int $userId, Request $request): bool
    {
        return $this->lock->run(fn (): bool => $this->process($actor, $userId, 'approved', null, $request));
    }

    public function reject(User $actor, int $userId, string $reason, Request $request): void
    {
        $this->process($actor, $userId, 'rejected', $reason, $request);
    }

    private function process(User $actor, int $userId, string $next, ?string $reason, Request $request): bool
    {
        return DB::transaction(function () use ($actor, $userId, $next, $reason, $request): bool {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $this->authorization->assertCanApproveProdiResource($actor, $user->prodi_id);
            abort_unless($user->hasRole('mahasiswa'), 403);
            abort_unless($user->enrollment_status === 'pending', 409);
            $candidate = FaceEmbedding::where('user_id', $user->id)->where('status', 'pending')->lockForUpdate()->sole();
            if ($next === 'approved') {
                if ($this->duplicates->isDuplicate($candidate->embedding, $user->id, $user->prodi_id)) {
                    AuditTrail::create([
                        'user_id' => $actor->id, 'action' => 'enrollment_approval_conflict',
                        'model_type' => FaceEmbedding::class, 'model_id' => $candidate->id,
                        'old_values' => ['status' => 'pending'], 'new_values' => ['outcome' => 'conflict'],
                        'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
                    ]);

                    return false;
                }
                FaceEmbedding::where('user_id', $user->id)->where('status', 'approved')->update(['status' => 'inactive']);
                $candidate->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            } else {
                $candidate->update(['status' => 'rejected', 'rejected_reason' => $reason, 'approved_by' => $actor->id, 'approved_at' => now()]);
            }
            $user->update(['enrollment_status' => $next]);
            AuditTrail::create([
                'user_id' => $actor->id, 'action' => "enrollment_{$next}", 'model_type' => FaceEmbedding::class,
                'model_id' => $candidate->id, 'old_values' => ['status' => 'pending'],
                'new_values' => ['status' => $next, 'target_user_id' => $user->id, 'reason' => $reason],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);

            return true;
        });
    }
}
