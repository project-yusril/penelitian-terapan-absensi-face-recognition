<?php

namespace App\Services;

use App\Exceptions\SpTransitionConflict;
use App\Jobs\ProcessSpTransitionSideEffects;
use App\Models\AuditTrail;
use App\Models\SpRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SpWorkflowService
{
    public function __construct(private AuthorizationService $authorization, private SpDocumentService $documents) {}

    public function generate(User $actor, int $studentId, string $level, ?int $semesterId, Request $request): SpRecord
    {
        abort_unless($actor->hasAnyRole(['admin_prodi', 'super_admin']), 403);

        return DB::transaction(function () use ($actor, $studentId, $level, $semesterId, $request): SpRecord {
            $student = User::whereKey($studentId)->lockForUpdate()->firstOrFail();
            abort_unless($student->hasRole('mahasiswa'), 422);
            $this->authorization->assertCanAccessProdi($actor, $student->prodi_id, ['admin_prodi']);
            $sp = $this->documents->generate($studentId, $level, $semesterId, $actor->id);
            $this->audit($actor, $sp, 'sp_generated', [], [
                'status' => 'draft', 'student_user_id' => $student->id,
                'student_prodi_id' => $student->prodi_id, 'semester_id' => $sp->semester_id,
                'sp_level' => $sp->sp_level, 'nomor_surat' => $sp->nomor_surat,
            ], $request);

            return $sp;
        });
    }

    public function sendToKaprodi(User $actor, int $id, Request $request): SpRecord
    {
        abort_unless($actor->hasAnyRole(['admin_prodi', 'super_admin']), 403);

        return $this->transition($actor, $id, 'draft', 'menunggu_kaprodi', 'sp_sent_to_kaprodi', $request,
            fn (SpRecord $sp) => $this->authorization->assertCanAccessProdi($actor, $sp->user?->prodi_id, ['admin_prodi']),
            fn (SpRecord $sp) => ProcessSpTransitionSideEffects::dispatch($sp->id, 'sent_to_kaprodi'));
    }

    public function signKaprodi(User $actor, int $id, Request $request): SpRecord
    {
        abort_unless($actor->hasAnyRole(['kaprodi', 'super_admin']), 403);

        return $this->transition($actor, $id, 'menunggu_kaprodi', 'menunggu_kajur', 'sp_signed_by_kaprodi', $request,
            fn (SpRecord $sp) => $this->authorization->assertCanAccessProdi($actor, $sp->user?->prodi_id, ['kaprodi']),
            function (SpRecord $sp) use ($actor): void {
                $sp->signed_kaprodi_by = $actor->id;
                $sp->signed_kaprodi_at = now();
                ProcessSpTransitionSideEffects::dispatch($sp->id, 'signed_kaprodi');
            });
    }

    public function signKajur(User $actor, int $id, Request $request): SpRecord
    {
        abort_unless($actor->hasAnyRole(['ketua_jurusan', 'super_admin']), 403);

        return $this->transition($actor, $id, 'menunggu_kajur', 'final', 'sp_finalized_by_kajur', $request,
            fn (SpRecord $sp) => abort_unless($sp->signed_kaprodi_by && $sp->signed_kaprodi_at, 409),
            function (SpRecord $sp) use ($actor): void {
                $sp->signed_kajur_by = $actor->id;
                $sp->signed_kajur_at = now();
                ProcessSpTransitionSideEffects::dispatch($sp->id, 'finalized');
            });
    }

    public function cancel(User $actor, int $id, string $reason, Request $request): SpRecord
    {
        return DB::transaction(function () use ($actor, $id, $reason, $request): SpRecord {
            $sp = SpRecord::with('user')->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->hasAnyRole(['admin_prodi', 'kaprodi', 'super_admin']), 403);
            $this->authorization->assertCanAccessProdi($actor, $sp->user?->prodi_id, ['admin_prodi', 'kaprodi']);
            if (! in_array($sp->status, ['draft', 'menunggu_kaprodi', 'menunggu_kajur'], true)) {
                throw new SpTransitionConflict('SP tidak dapat dibatalkan dari status saat ini');
            }
            $oldStatus = $sp->status;
            $oldValues = [
                'status' => $oldStatus, 'notes' => $sp->notes,
                'signed_kaprodi_by' => $sp->signed_kaprodi_by,
                'signed_kaprodi_at' => $sp->signed_kaprodi_at,
                'signed_kajur_by' => $sp->signed_kajur_by,
                'signed_kajur_at' => $sp->signed_kajur_at,
                'document_path' => $sp->document_path,
            ];
            $sp->status = 'dibatalkan';
            $sp->notes = ($sp->notes ? $sp->notes.' | ' : '').'Dibatalkan: '.$reason;
            $sp->signed_kaprodi_by = null;
            $sp->signed_kaprodi_at = null;
            $sp->signed_kajur_by = null;
            $sp->signed_kajur_at = null;
            $sp->document_path = null;
            $sp->save();
            $this->audit($actor, $sp, 'sp_cancelled', $oldValues,
                ['status' => 'dibatalkan', 'notes' => $sp->notes, 'reason' => $reason,
                    'signed_kaprodi_by' => null, 'signed_kajur_by' => null, 'document_path' => null], $request);
            ProcessSpTransitionSideEffects::dispatch($sp->id, 'cancelled');

            return $sp->fresh();
        });
    }

    private function transition(User $actor, int $id, string $expected, string $next, string $action,
        Request $request, callable $authorize, callable $mutate): SpRecord
    {
        return DB::transaction(function () use ($actor, $id, $expected, $next, $action, $request, $authorize, $mutate): SpRecord {
            $sp = SpRecord::with('user')->whereKey($id)->lockForUpdate()->firstOrFail();
            $authorize($sp);
            if ($sp->status !== $expected) {
                throw new SpTransitionConflict("Transisi SP memerlukan status {$expected}");
            }
            $old = ['status' => $sp->status];
            $sp->status = $next;
            $mutate($sp);
            $sp->save();
            $this->audit($actor, $sp, $action, $old, [
                'status' => $next, 'signed_kaprodi_by' => $sp->signed_kaprodi_by,
                'signed_kajur_by' => $sp->signed_kajur_by,
                'student_prodi_id' => $sp->user?->prodi_id,
            ], $request);

            return $sp->fresh();
        });
    }

    private function audit(User $actor, SpRecord $sp, string $action, array $old, array $new, Request $request): void
    {
        AuditTrail::create([
            'user_id' => $actor->id, 'action' => $action, 'model_type' => SpRecord::class,
            'model_id' => $sp->id, 'old_values' => $old, 'new_values' => $new,
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
    }
}
