<?php

namespace App\Services;

use App\Models\MataKuliah;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Menjaga KRS mahasiswa tetap selaras dengan kelasnya.
 *
 * Jadwal yang dilihat mahasiswa di aplikasi diambil murni dari pivot
 * `mahasiswa_mata_kuliah` (lihat JadwalController), bukan dari kolom
 * `users.kelas`. Akibatnya, memindahkan mahasiswa antar kelas lewat panel
 * admin tidak mengubah apa pun yang dia lihat: KRS-nya tetap menunjuk section
 * kelas lama, sehingga jadwalnya salah — atau kosong sama sekali bila hari
 * jadwal kedua section itu berbeda.
 *
 * Konvensi penautan mengikuti MahasiswaMataKuliahSeeder: mahasiswa mengambil
 * section mata kuliah yang `kelas`-nya sama dengan kelas dia.
 */
class MahasiswaEnrollmentSynchronizer
{
    /**
     * Pindahkan KRS mahasiswa ke section yang sesuai kelas barunya.
     *
     * Hanya memindahkan mata kuliah yang SUDAH ada di KRS-nya; tidak pernah
     * mendaftarkan mata kuliah baru. Pilihan ini disengaja supaya perubahan
     * kelas tidak diam-diam menambah beban studi — penambahan mata kuliah
     * tetap lewat halaman peserta mata kuliah.
     *
     * @return array{moved: list<array{from: int, to: int}>, unmatched: list<int>}
     */
    public function syncAfterClassChange(User $user): array
    {
        $moved = [];
        $unmatched = [];

        $kelas = $user->kelas;
        if ($kelas === null || $kelas === '') {
            return ['moved' => $moved, 'unmatched' => $unmatched];
        }

        if (! $user->roles()->where('name', 'mahasiswa')->exists()) {
            return ['moved' => $moved, 'unmatched' => $unmatched];
        }

        $enrolled = $user->mataKuliahs()->get();

        foreach ($enrolled as $mataKuliah) {
            if ($mataKuliah->kelas === $kelas) {
                continue;
            }

            $target = $this->findSiblingSection($mataKuliah, $kelas, $user->prodi_id);

            if ($target === null) {
                $unmatched[] = $mataKuliah->id;

                continue;
            }

            // Lewati bila mahasiswa sudah terdaftar di section tujuan; cukup
            // lepas tautan lama agar tidak tersisa KRS ganda.
            DB::transaction(function () use ($user, $mataKuliah, $target): void {
                $user->mataKuliahs()->detach($mataKuliah->id);
                $user->mataKuliahs()->syncWithoutDetaching([$target->id]);
            });

            $moved[] = ['from' => $mataKuliah->id, 'to' => $target->id];
        }

        if ($moved !== [] || $unmatched !== []) {
            Log::info('KRS disinkronkan setelah kelas mahasiswa berubah', [
                'user_id' => $user->id,
                'kelas_baru' => $kelas,
                'dipindahkan' => $moved,
                'tanpa_padanan' => $unmatched,
            ]);
        }

        return ['moved' => $moved, 'unmatched' => $unmatched];
    }

    /**
     * Cari section kelas [$kelas] yang sepadan dengan [$source].
     *
     * Pencocokan bertingkat: `kode_mk` lebih dulu karena itu identitas resmi
     * mata kuliah. Bila tidak ketemu, jatuh ke `nama` — di data nyata section
     * mata kuliah yang sama bisa punya kode berbeda (mis. TI-401 untuk kelas
     * A/C/D/E tetapi TI-402 untuk kelas B), sehingga pencocokan kode saja akan
     * gagal menemukan padanannya.
     */
    private function findSiblingSection(MataKuliah $source, string $kelas, ?int $prodiId): ?MataKuliah
    {
        $base = fn () => MataKuliah::query()
            ->where('kelas', $kelas)
            ->where('status', 'aktif')
            ->where('semester_id', $source->semester_id)
            ->where('prodi_id', $prodiId ?? $source->prodi_id)
            ->whereKeyNot($source->id);

        return $base()->where('kode_mk', $source->kode_mk)->first()
            ?? $base()->where('nama', $source->nama)->first();
    }
}
