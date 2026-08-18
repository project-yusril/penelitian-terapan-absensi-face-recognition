<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ServeAll extends Command
{
    protected $signature = 'serve:all
        {--host=0.0.0.0 : Host yang dipakai dev server}
        {--port=8000 : Port yang dipakai dev server}';

    protected $description = 'Jalankan dev server dan scheduler sekaligus (php artisan serve + schedule:work)';

    /** @var array<string, Process> */
    private array $processes = [];

    public function handle(): int
    {
        $php = PHP_BINARY;

        $this->processes['serve'] = new Process(
            [$php, 'artisan', 'serve', "--host={$this->option('host')}", "--port={$this->option('port')}"],
            base_path(),
        );
        $this->processes['schedule'] = new Process(
            [$php, 'artisan', 'schedule:work'],
            base_path(),
        );

        foreach ($this->processes as $name => $process) {
            $process->setTimeout(null);
            $process->setIdleTimeout(null);
            $process->start(function (string $type, string $buffer) use ($name): void {
                if (trim($buffer) !== '') {
                    $this->output->write("[{$name}] {$buffer}");
                }
            });
        }

        // Windows: pastikan Ctrl+C ikut menghentikan child process.
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(function (int $event): void {
                foreach ($this->processes as $process) {
                    $process->stop(5);
                }
                exit(0);
            });
        }

        $this->info("Server: http://{$this->option('host')}:{$this->option('port')}");

        while (true) {
            foreach ($this->processes as $name => $process) {
                if (! $process->isRunning()) {
                    $this->error("Proses '{$name}' berhenti (exit {$process->getExitCode()}). Menghentikan proses lain...");
                    foreach ($this->processes as $other) {
                        $other->stop(10);
                    }

                    return Command::FAILURE;
                }
            }
            usleep(200_000);
        }
    }
}
