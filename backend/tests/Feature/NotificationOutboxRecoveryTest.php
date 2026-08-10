<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\NotificationOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class NotificationOutboxRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_poison_row_retries_without_blocking_later_valid_row_and_dead_letters(): void
    {
        Carbon::setTestNow('2026-07-18 09:00:00');
        $user = User::factory()->create();
        $service = app(NotificationOutboxService::class);
        $service->enqueue('poison', $user->id, 'system', 'Poison', 'Fails');
        $service->enqueue('valid', $user->id, 'system', 'Valid', 'Succeeds');
        Event::listen('eloquent.creating: '.Notification::class, function (Notification $notification): void {
            if ($notification->idempotency_key === 'poison') {
                throw new RuntimeException('Injected notification failure');
            }
        });

        $this->artisan('notifications:process-outbox')->assertSuccessful();

        $poison = NotificationOutbox::where('idempotency_key', 'poison')->firstOrFail();
        $this->assertSame(1, $poison->attempt_count);
        $this->assertTrue($poison->next_attempt_at->equalTo(now()->addMinute()));
        $this->assertSame('Injected notification failure', $poison->last_error);
        $this->assertNull($poison->locked_at);
        $this->assertDatabaseHas('notifications', ['idempotency_key' => 'valid']);

        foreach ([1, 2, 4, 8] as $minutes) {
            Carbon::setTestNow(now()->addMinutes($minutes));
            $this->artisan('notifications:process-outbox')->assertSuccessful();
        }

        $poison->refresh();
        $this->assertSame(5, $poison->attempt_count);
        $this->assertNotNull($poison->failed_at);
        $this->assertNull($poison->next_attempt_at);

        Carbon::setTestNow(now()->addDay());
        $this->artisan('notifications:process-outbox')->assertSuccessful();
        $this->assertSame(5, $poison->fresh()->attempt_count);
    }

    public function test_repeated_materialization_keeps_one_notification(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationOutboxService::class);
        $service->enqueue('idempotent', $user->id, 'system', 'Title', 'Body');

        $this->assertSame(1, $service->process());
        NotificationOutbox::where('idempotency_key', 'idempotent')->update(['processed_at' => null]);
        $this->assertSame(1, $service->process());

        $this->assertSame(1, Notification::where('idempotency_key', 'idempotent')->count());
    }
}
