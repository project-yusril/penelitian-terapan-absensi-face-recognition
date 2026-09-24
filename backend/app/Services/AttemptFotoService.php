<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * FOTO ATTEMPT BERISIKO (keputusan diskusi 23 Sep 2026).
 *
 * Foto check-in/checkout tidak disimpan untuk semua attempt — pada 500
 * mahasiswa x 5 sesi/minggu itu ~16 GB per semester. Foto hanya disimpan
 * ketika attempt masuk kriteria risiko:
 *
 *  - face_not_match      : verifikasi wajah gagal (calon impostor)
 *  - face_borderline     : face_distance mendekati threshold (zona abu-abu)
 *  - mock_location       : sinyal lokasi palsu terdeteksi
 *  - liveness_failed     : liveness gagal
 *  - offline_sync        : attempt disinkronkan offline (jendela curang terluas)
 *
 * Foto disimpan ke disk privat `face` (pola sama dengan enrollment), diakses
 * hanya via signed URL + otorisasi. Retensi 30 hari di-purge oleh command
 * `attendance:purge-attempt-fotos` — angka/metadata tetap disimpan selamanya
 * karena itulah sumber data R-03/R-04/FAR-FRR.
 */
final class AttemptFotoService
{
    public const DISK = 'face';

    public const DIRECTORY = 'attempt';

    /**
     * Attempt dengan face_distance > ambang ini dianggap borderline (masih
     * lolos match, tetapi dekat threshold) sehingga fotonya disimpan untuk
     * audit visual. Nilai relatif terhadap threshold prodi.
     */
    public const BORDERLINE_RATIO = 0.75;

    /**
     * Kriteria risiko satu attempt check-in/checkout.
     *
     * @return array<int, string>
     */
    public static function riskReasons(
        float $faceDistance,
        float $faceThreshold,
        bool $mockLocationDetected,
        bool $livenessPassed,
        bool $isOfflineSync = false,
    ): array {
        $reasons = [];

        if ($faceDistance > $faceThreshold) {
            $reasons[] = 'face_not_match';
        } elseif ($faceDistance >= $faceThreshold * self::BORDERLINE_RATIO) {
            $reasons[] = 'face_borderline';
        }

        if ($mockLocationDetected) {
            $reasons[] = 'mock_location';
        }

        if (! $livenessPassed) {
            $reasons[] = 'liveness_failed';
        }

        if ($isOfflineSync) {
            $reasons[] = 'offline_sync';
        }

        return $reasons;
    }

    /**
     * Apakah request membawa foto attempt yang sah untuk disimpan.
     */
    public function hasFoto(Request $request): bool
    {
        return $request->hasFile('attempt_foto');
    }

    /**
     * Simpan foto attempt ke disk privat. Return path relatif atau null bila
     * tidak ada file. Validasi ketat: jpeg/png, maks 10240 KB (sabuk pengaman
     * server, klien sudah mengompres < 500 KB).
     */
    public function store(Request $request): ?string
    {
        if (! $request->hasFile('attempt_foto')) {
            return null;
        }

        $file = $request->file('attempt_foto');

        if (! $file->isValid()) {
            return null;
        }

        $allowedMimes = ['image/jpeg', 'image/png'];
        if (! in_array($file->getMimeType(), $allowedMimes, true)) {
            return null;
        }

        if ($file->getSize() > PhotoUploadPolicy::MAX_FOTO_KB * 1024) {
            return null;
        }

        return $file->store(self::DIRECTORY, self::DISK);
    }

    /**
     * Signed URL untuk dashboard. Selalu dinamis — file bisa sudah di-purge.
     */
    public function url(?string $path, bool $web = false): ?string
    {
        if (! $path) {
            return null;
        }

        if (! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return route($web ? 'web.private.attempt-fotos.show' : 'private.attempt-fotos.show', ['attendanceLog' => basename($path)]);
    }

    /**
     * Hapus file foto attempt (dipakai purge retensi).
     */
    public function delete(?string $path): void
    {
        if ($path && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
