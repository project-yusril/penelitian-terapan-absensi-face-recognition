<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class NotificationOutboxService
{
    private const CLAIM_TIMEOUT_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    private const MAX_BACKOFF_SECONDS = 3600;

    public function enqueue(string $key, int $userId, string $type, string $title, string $body, array $data = []): void
    {
        NotificationOutbox::firstOrCreate(['idempotency_key' => $key], [
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);
    }

    public function process(int $limit = 100): int
    {
        $processed = 0;
        $now = now();
        $ids = NotificationOutbox::whereNull('processed_at')
            ->whereNull('failed_at')
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('locked_at')
                    ->orWhere('locked_at', '<=', $now->copy()->subMinutes(self::CLAIM_TIMEOUT_MINUTES));
            })
            ->orderBy('id')
            ->limit(max(0, $limit))
            ->pluck('id');

        foreach ($ids as $id) {
            $lockToken = $this->claim((int) $id);
            if (! $lockToken) {
                continue;
            }

            try {
                $processed += (int) $this->materialize((int) $id, $lockToken);
            } catch (Throwable $exception) {
                $this->recordFailure((int) $id, $lockToken, $exception);
                report($exception);
            }
        }

        return $processed;
    }

    private function claim(int $id): ?string
    {
        return DB::transaction(function () use ($id): ?string {
            $now = now();
            $item = NotificationOutbox::whereKey($id)->lockForUpdate()->first();
            if (! $item || $item->processed_at || $item->failed_at
                || ($item->next_attempt_at && $item->next_attempt_at->gt($now))
                || ($item->locked_at && $item->locked_at->gt($now->copy()->subMinutes(self::CLAIM_TIMEOUT_MINUTES)))) {
                return null;
            }

            $lockToken = (string) Str::uuid();
            $item->update(['locked_at' => $now, 'lock_token' => $lockToken]);

            return $lockToken;
        });
    }

    private function materialize(int $id, string $lockToken): bool
    {
        return DB::transaction(function () use ($id, $lockToken): bool {
            $item = NotificationOutbox::whereKey($id)->lockForUpdate()->first();
            if (! $item || $item->processed_at || $item->failed_at || $item->lock_token !== $lockToken) {
                return false;
            }

            Notification::firstOrCreate(['idempotency_key' => $item->idempotency_key], [
                'user_id' => $item->user_id,
                'type' => $item->type,
                'title' => $item->title,
                'body' => $item->body,
                'data' => $item->data,
            ]);
            $item->update([
                'processed_at' => now(),
                'lock_token' => null,
                'locked_at' => null,
                'last_error' => null,
            ]);

            return true;
        });
    }

    private function recordFailure(int $id, string $lockToken, Throwable $exception): void
    {
        DB::transaction(function () use ($id, $lockToken, $exception): void {
            $item = NotificationOutbox::whereKey($id)->lockForUpdate()->first();
            if (! $item || $item->processed_at || $item->failed_at || $item->lock_token !== $lockToken) {
                return;
            }

            $attempts = $item->attempt_count + 1;
            $failed = $attempts >= self::MAX_ATTEMPTS;
            $item->update([
                'attempt_count' => $attempts,
                'next_attempt_at' => $failed ? null : now()->addSeconds(min(60 * (2 ** ($attempts - 1)), self::MAX_BACKOFF_SECONDS)),
                'last_error' => Str::limit($exception->getMessage(), 2000, ''),
                'lock_token' => null,
                'locked_at' => null,
                'failed_at' => $failed ? now() : null,
            ]);
        });
    }
}
