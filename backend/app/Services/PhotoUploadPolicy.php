<?php

namespace App\Services;

/**
 * Rule validasi foto wajah canonical untuk seluruh upload biometrik
 * (enrollment, re-enrollment, dan foto profil).
 *
 * Foto dikirim sebagai JPEG kamera; HP modern (50–108 MP) kerap menghasilkan
 * file 3–8 MB, sedangkan file hasil kompresi klien biasanya < 500 KB. Nilai
 * ini adalah sabuk pengaman server: klien sudah mengecilkan foto sebelum
 * upload, tetapi server tidak boleh menolak foto kamera wajar yang belum
 * dikompresi. Hostinger mengizinkan `upload_max_filesize`/`post_max_size`
 * 2 GB, jadi batas ini murni policy aplikasi, bukan limit hosting.
 */
final class PhotoUploadPolicy
{
    /**
     * Batas ukuran foto wajah (KB) yang diterima server.
     */
    public const MAX_FOTO_KB = 10240;

    /**
     * Batas dimensi foto wajah (px). Mencegah file besar tidak wajar lolos
     * walau ukuran byte-nya di bawah [MAX_FOTO_KB].
     */
    public const MAX_FOTO_DIMENSION_PX = 6000;

    /**
     * Rule validasi foto wajah canonical.
     *
     * @return array<int, string>
     */
    public static function facePhotoRules(): array
    {
        return [
            'required',
            'image',
            'mimes:jpeg,jpg,png',
            'max:'.self::MAX_FOTO_KB,
            'dimensions:max_width='.self::MAX_FOTO_DIMENSION_PX.',max_height='.self::MAX_FOTO_DIMENSION_PX,
        ];
    }
}
