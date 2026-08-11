<?php

namespace App\Observers;

use App\Models\User;
use App\Services\MahasiswaEnrollmentSynchronizer;

/**
 * Menjaga invarian "KRS mahasiswa selalu mengikuti kelasnya".
 *
 * Dipasang di level model, bukan di controller, karena `kelas` bisa berubah
 * lewat beberapa jalur yang terpisah: panel admin (Web\UserController),
 * API admin (Api\Admin\UserController), pembaruan massal, dan import CSV.
 * Menambal satu per satu berarti setiap jalur baru berpotensi melewatkannya
 * lagi; observer menutup semuanya di satu tempat.
 */
class UserObserver
{
    public function __construct(
        private readonly MahasiswaEnrollmentSynchronizer $synchronizer,
    ) {}

    public function updated(User $user): void
    {
        // Pindah prodi ikut diperhitungkan: section pengganti dicari dalam
        // prodi mahasiswa, sehingga KRS lama menjadi tidak sah juga saat
        // prodi-nya berubah.
        if (! $user->wasChanged('kelas') && ! $user->wasChanged('prodi_id')) {
            return;
        }

        $this->synchronizer->syncAfterClassChange($user);
    }
}
