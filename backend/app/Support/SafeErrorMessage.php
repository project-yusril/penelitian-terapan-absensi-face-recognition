<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * M-22: ubah exception menjadi pesan yang aman ditampilkan ke pengguna.
 *
 * Pesan exception mentah dapat memuat fragmen SQL, nama kolom, nilai baris,
 * path file, atau PII. Detail lengkap tetap dicatat ke log aplikasi dengan
 * correlation ID sehingga operator masih dapat menelusuri penyebabnya.
 */
class SafeErrorMessage
{
    /**
     * Kembalikan pesan aman untuk ditampilkan, dan catat detail ke log.
     *
     * @param  array<string, mixed>  $context
     */
    public static function forDisplay(Throwable $e, string $fallback, array $context = []): string
    {
        // Pesan validasi dibuat aplikasi dan memang ditujukan untuk pengguna.
        if ($e instanceof ValidationException) {
            return $e->validator->errors()->first() ?: $fallback;
        }

        $correlationId = bin2hex(random_bytes(8));

        Log::warning('Handled exception surfaced to user', $context + [
            'correlation_id' => $correlationId,
            'exception' => $e::class,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            // Pesan QueryException memuat SQL beserta nilai baris, sehingga
            // hanya SQLSTATE yang dicatat. SQLSTATE cukup untuk diagnosis
            // (misalnya 23000 untuk constraint violation) tanpa memuat data.
            'message' => $e instanceof QueryException
                ? 'sqlstate:'.($e->errorInfo[0] ?? 'unknown')
                : $e->getMessage(),
        ]);

        return "{$fallback} (ref: {$correlationId})";
    }
}
