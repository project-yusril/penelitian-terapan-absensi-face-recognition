<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Generate sepasang kunci VAPID untuk Web Push.
 * Jalankan sekali, lalu salin output ke file .env.
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid {--write : Tulis langsung ke file .env (timpa nilai lama)}';

    protected $description = 'Generate sepasang kunci VAPID untuk Web Push (salin ke .env)';

    public function handle(): int
    {
        $this->ensureOpenSslConfig();

        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            $this->error('Gagal generate kunci VAPID: '.$e->getMessage());
            $this->newLine();
            $this->warn('Penyebab umum di Windows: OpenSSL tidak menemukan openssl.cnf.');
            $this->line('Solusi: set environment variable OPENSSL_CONF ke file openssl.cnf yang valid,');
            $this->line('mis. set OPENSSL_CONF=C:\\php\\extras\\ssl\\openssl.cnf — lalu jalankan ulang.');
            $this->line('Alternatif: generate kunci di server Linux, lalu salin ke .env.');

            return self::FAILURE;
        }

        if ($this->option('write')) {
            $this->writeToEnv($keys['publicKey'], $keys['privateKey']);
            $this->info('Kunci VAPID berhasil ditulis ke .env. Restart server agar config ter-reload.');

            return self::SUCCESS;
        }

        $this->info('Kunci VAPID berhasil dibuat. Salin ke file .env Anda:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:admin@contoh.ac.id');
        $this->newLine();
        $this->warn('Simpan VAPID_PRIVATE_KEY dengan aman — jangan commit ke repository.');
        $this->line('Tip: jalankan dengan --write untuk menulis otomatis ke .env.');

        return self::SUCCESS;
    }

    /**
     * Tulis/timpa nilai VAPID di file .env tanpa mengganggu baris lain.
     */
    private function writeToEnv(string $publicKey, string $privateKey): void
    {
        $path = base_path('.env');

        if (! is_file($path)) {
            $this->warn('File .env tidak ditemukan, dilewati.');

            return;
        }

        $content = (string) file_get_contents($path);

        $values = [
            'VAPID_PUBLIC_KEY' => $publicKey,
            'VAPID_PRIVATE_KEY' => $privateKey,
        ];

        foreach ($values as $key => $value) {
            $line = $key.'='.$value;
            if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $content)) {
                $content = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $content);
            } else {
                $content = rtrim($content, "\r\n").PHP_EOL.$line.PHP_EOL;
            }
        }

        // Pastikan ada VAPID_SUBJECT default bila belum ada.
        if (! preg_match('/^VAPID_SUBJECT=.*$/m', $content)) {
            $content = rtrim($content, "\r\n").PHP_EOL.'VAPID_SUBJECT="mailto:admin@contoh.ac.id"'.PHP_EOL;
        }

        file_put_contents($path, $content);
    }

    /**
     * Di Windows, OpenSSL sering gagal generate EC key karena openssl.cnf tidak
     * ditemukan. Bila OPENSSL_CONF belum di-set, coba deteksi lokasi umum dan
     * set otomatis untuk proses ini saja.
     */
    private function ensureOpenSslConfig(): void
    {
        if (getenv('OPENSSL_CONF')) {
            return;
        }

        $candidates = [
            'C:\\php\\extras\\ssl\\openssl.cnf',
            'C:\\Program Files\\Git\\usr\\ssl\\openssl.cnf',
            'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\openssl.cnf',
            'C:\\xampp\\apache\\conf\\openssl.cnf',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                putenv('OPENSSL_CONF='.$path);
                $_ENV['OPENSSL_CONF'] = $path;
                $this->line('Menggunakan OPENSSL_CONF: '.$path);

                return;
            }
        }
    }
}
