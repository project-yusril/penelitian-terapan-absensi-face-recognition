<?php

namespace App\Services;

use App\Models\Kelas;
use App\Models\MahasiswaKelas;
use App\Models\Semester;
use App\Models\User;

/**
 * Menjaga konsistensi "kelas mahasiswa" antar jalur penyimpanan.
 *
 * RENCANA 2: KRS mahasiswa diturunkan dari pivot `mahasiswa_kelas`
 * (kelas aktif → jadwal → mata kuliah), bukan dari pivot
 * `mahasiswa_mata_kuliah` maupun kolom string `users.kelas`.
 *
 * Snapshot `users.kelas` (label "4B") & `users.semester` (tingkat)
 * tetap dipertahankan untuk UI cepat dan kompatibilitas mobile; sumber
 * kebenaran kelas adalah pivot. Service ini menyelaraskan keduanya.
 */
class MahasiswaEnrollmentSynchronizer
{
    /**
     * Selaraskan pivot `mahasiswa_kelas` + snapshot dari label kelas.
     *
     * Dipanggil oleh UserObserver setiap kali `kelas`/`prodi_id` user
     * berubah (lewat panel web, API admin, import, atau mass update).
     *
     * @return array{kelas_id: int|null, semester_id: int|null, label: string|null}
     */
    public function syncAfterClassChange(User $user): array
    {
        if (! $user->roles()->where('name', 'mahasiswa')->exists()) {
            return ['kelas_id' => null, 'semester_id' => null, 'label' => $user->kelas];
        }

        $semesterAktif = Semester::where('status', 'aktif')->first();
        if (! $semesterAktif) {
            return ['kelas_id' => null, 'semester_id' => null, 'label' => $user->kelas];
        }

        $label = $user->kelas;
        $kelas = $label !== null && $label !== ''
            ? Kelas::whereRaw('CONCAT(tingkat, nama) = ?', [$label])
                ->where('prodi_id', $user->prodi_id)
                ->where('semester_id', $semesterAktif->id)
                ->first()
            : null;

        if ($kelas) {
            MahasiswaKelas::updateOrCreate(
                ['user_id' => $user->id, 'semester_id' => $semesterAktif->id],
                ['kelas_id' => $kelas->id]
            );

            // Snapshot semester mengikuti tingkat kelas master. saveQuietly
            // menghindari loop observer (event updated tidak dipicu).
            if ((int) $user->semester !== (int) $kelas->tingkat) {
                $user->forceFill(['semester' => (int) $kelas->tingkat])->saveQuietly();
            }

            return ['kelas_id' => $kelas->id, 'semester_id' => $semesterAktif->id, 'label' => $label];
        }

        // Label tidak cocok dengan kelas master di semester aktif: jangan
        // sentuh riwayat semester lain, cukup lepas tautan semester aktif.
        MahasiswaKelas::where('user_id', $user->id)
            ->where('semester_id', $semesterAktif->id)
            ->delete();

        return ['kelas_id' => null, 'semester_id' => $semesterAktif->id, 'label' => $label];
    }
}
