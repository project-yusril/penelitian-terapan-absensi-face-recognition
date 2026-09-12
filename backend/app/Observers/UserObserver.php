<?php

namespace App\Observers;

use App\Models\User;
use App\Services\MahasiswaEnrollmentSynchronizer;

/**
 * Menjaga invarian "kelas mahasiswa selalu konsisten" lewat semua jalur:
 * panel admin (Web\UserController), API admin (Api\Admin\UserController),
 * pembaruan massal, dan import Excel. Satu titik penjaga untuk semuanya.
 *
 * RENCANA 2: mutasi kolom snapshot `users.kelas` disinkronkan ke pivot
 * `mahasiswa_kelas` (kelas master) + snapshot semester mengikuti tingkat.
 */
class UserObserver
{
    public function __construct(
        private readonly MahasiswaEnrollmentSynchronizer $synchronizer,
    ) {}

    public function updated(User $user): void
    {
        // Pindah prodi ikut diperhitungkan: label kelas lama menjadi tidak
        // sah di prodi baru, sehingga tautan pivot perlu diselaraskan ulang.
        if (! $user->wasChanged('kelas') && ! $user->wasChanged('prodi_id')) {
            return;
        }

        $this->synchronizer->syncAfterClassChange($user);
    }
}
