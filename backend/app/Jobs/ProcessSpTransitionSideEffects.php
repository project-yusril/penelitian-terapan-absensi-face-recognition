<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\SpRecord;
use App\Services\SpDocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessSpTransitionSideEffects implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $spRecordId, public string $transition)
    {
        $this->afterCommit();
    }

    public function handle(SpDocumentService $documents): void
    {
        $sp = SpRecord::with('user')->findOrFail($this->spRecordId);

        match ($this->transition) {
            'sent_to_kaprodi' => $documents->notifyKaprodi($sp),
            'signed_kaprodi' => $this->signedKaprodi($documents, $sp),
            'finalized' => $this->finalized($documents, $sp),
            'cancelled' => $this->cancelled($sp),
            default => null,
        };
    }

    private function signedKaprodi(SpDocumentService $documents, SpRecord $sp): void
    {
        $documents->regeneratePdfWithSignatures($sp);
        $documents->notifyKajur($sp);
    }

    private function finalized(SpDocumentService $documents, SpRecord $sp): void
    {
        $documents->regeneratePdfWithSignatures($sp);
        Notification::firstOrCreate([
            'user_id' => $sp->user_id,
            'type' => 'sp_issued',
            'data->sp_record_id' => $sp->id,
        ], [
            'title' => "Surat Peringatan {$sp->sp_level} telah final",
            'body' => "Surat Peringatan {$sp->sp_level} telah ditandatangani dan dapat diunduh.",
            'data' => ['sp_record_id' => $sp->id, 'level' => $sp->sp_level],
        ]);
    }

    private function cancelled(SpRecord $sp): void
    {
        Notification::where('type', 'approval_needed')
            ->where('data->sp_record_id', $sp->id)
            ->delete();
    }
}
