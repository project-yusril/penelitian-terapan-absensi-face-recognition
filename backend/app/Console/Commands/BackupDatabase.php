<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Backup database harian → storage/app/backups/db-YYYYMMDD-HHmmss.sql
 * Dijalankan oleh scheduler (lihat routes/console.php). Mendukung MySQL.
 *
 * Konfigurasi via .env:
 *   DB_BACKUP_RETENTION_DAYS=14   (default 14 hari)
 *   DB_BACKUP_MYSQLDUMP_PATH=mysqldump (default cari di PATH)
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:database {--keep=14 : Jumlah hari backup yang dipertahankan}';

    protected $description = 'Dump database (mysqldump) ke storage/app/backups dan rotasi file lama';

    public function handle(): int
    {
        $conn = config('database.default');
        $cfg = config("database.connections.{$conn}");

        if (($cfg['driver'] ?? null) !== 'mysql') {
            $this->error('Hanya driver mysql yang didukung saat ini.');

            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        if (! $disk->exists('backups')) {
            $disk->makeDirectory('backups');
        }

        $filename = 'db-'.now()->format('Ymd_His').'.sql';
        $absPath = storage_path('app/backups/'.$filename);

        $mysqldump = env('DB_BACKUP_MYSQLDUMP_PATH', 'mysqldump');
        $args = array_filter([
            $mysqldump,
            '-h', $cfg['host'] ?? '127.0.0.1',
            '-P', (string) ($cfg['port'] ?? '3306'),
            '-u', $cfg['username'],
            $cfg['password'] ? '-p'.$cfg['password'] : null,
            '--single-transaction',
            '--quick',
            '--default-character-set='.($cfg['charset'] ?? 'utf8mb4'),
            $cfg['database'],
        ]);

        $process = new Process($args);
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) use ($absPath) {
            if ($type === Process::OUT) {
                file_put_contents($absPath, $buffer, FILE_APPEND);
            }
        });

        if (! $process->isSuccessful()) {
            $this->error('mysqldump gagal: '.$process->getErrorOutput());
            @unlink($absPath);

            return self::FAILURE;
        }

        $size = filesize($absPath);
        $this->info("Backup dibuat: {$filename} (".number_format($size / 1024, 1).' KB)');

        // Rotasi: hapus file lebih tua dari N hari.
        $keep = (int) ($this->option('keep') ?? env('DB_BACKUP_RETENTION_DAYS', 14));
        $cutoff = now()->subDays($keep)->getTimestamp();
        $removed = 0;
        foreach ($disk->files('backups') as $f) {
            if ($disk->lastModified($f) < $cutoff) {
                $disk->delete($f);
                $removed++;
            }
        }
        if ($removed > 0) {
            $this->info("Dirotasi: {$removed} file lama dihapus (>{$keep} hari).");
        }

        return self::SUCCESS;
    }
}
