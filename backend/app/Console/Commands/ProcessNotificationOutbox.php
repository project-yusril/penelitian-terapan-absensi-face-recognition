<?php

namespace App\Console\Commands;

use App\Services\NotificationOutboxService;
use Illuminate\Console\Command;

class ProcessNotificationOutbox extends Command
{
    protected $signature = 'notifications:process-outbox {--limit=100}';

    protected $description = 'Materialize durable notification outbox records';

    public function handle(NotificationOutboxService $outbox): int
    {
        $count = $outbox->process((int) $this->option('limit'));
        $this->info("Processed {$count} notification outbox records.");

        return self::SUCCESS;
    }
}
