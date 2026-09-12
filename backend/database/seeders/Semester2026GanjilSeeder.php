<?php

namespace Database\Seeders;

use App\Models\AlphaAccumulation;
use App\Models\Kelas;
use App\Models\MahasiswaKelas;
use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Menata ulang periode akademik menjadi TA 2026/2027 Ganjil (semester 1, 3, 5).
 *
 * Masalah yang dibereskan:
 *  - Dua semester bertanda "aktif" sekaligus (Genap 2025/2026 lama + Ganjil
 *    2026/2027 debug) karena seeder lama membiarkan Genap tetap aktif. Aturan
 *    bisnis: hanya SATU semester aktif, dan kini itu adalah Ganjil 2026/2027.
 *  - Kelas 4A-4E/5A (milik Genap 2025/2026) dan kelas 1A-1E baru (milik Ganjil
 *    2026/2027) hidup berdampingan di semester yang berbeda — itu bukan
 *    redundansi, melainkan per-periodisasi yang benar: kelas adalah snapshot
 *    per semester (RENCANA 2). Yang salah adalah semester lamanya masih
 *    aktif, sehingga UI menyatukan keduanya.
 *  - Snapshot `users.semester` mahasiswa angkatan 2024 masih 4, padahal di
 *    Ganjil mereka naik ke tingkat 5.
 *
 * Yang dilakukan seeder ini:
 *  1. Menonaktifkan SEMUA semester, lalu mengaktifkan `2026/2027-1` (Ganjil).
 *  2. Membuat semester Ganjil utuh: kode `2026/2027-1`, rentang Jul-Des 2026.
 *  3. Membuat kelas master tingkat 1, 3, 5 (A-E) di semester aktif untuk
 *     prodi TI, sesuai struktur "Tahun Ajaran 2026 Ganjil" pada UI.
 *  4. Menaikkan mahasiswa angkatan 2024: snapshot kelas 4x → 5x, semester 5,
 *     pivot `mahasiswa_kelas` semester aktif mengikuti kelas 5x.
 *  5. Riwayat (attendances, SP, alpha di semester lama) TIDAK disentuh —
 *     itu sejarah; kelas lama tetap hidup sebagai arsip nonaktif.
 */
class Semester2026GanjilSeeder extends Seeder
{
    public function run(): void
    {
        $ta = TahunAjaran::where('kode', '2026/2027')->first();
        if (! $ta) {
            $this->command?->warn('TA 2026/2027 tidak ditemukan — seeder dilewati.');

            return;
        }

        DB::transaction(function () use ($ta) {
            // 1. Pastikan hanya satu semester aktif: Ganjil 2026/2027.
            Semester::where('status', 'aktif')->update(['status' => 'nonaktif']);

            // Kelas milik semester nonaktif menjadi arsip: ikut nonaktif agar
            // tabel Kelas tidak menampilkan kelas periode lalu seolah berjalan.
            Kelas::whereHas('semester', fn ($q) => $q->where('status', 'nonaktif'))
                ->where('status', 'aktif')
                ->update(['status' => 'nonaktif']);

            $ganjil = Semester::updateOrCreate(
                ['tahun_ajaran_id' => $ta->id, 'kode' => '2026/2027-1'],
                [
                    'nama' => 'Ganjil',
                    'tanggal_mulai' => '2026-07-01',
                    'tanggal_selesai' => '2026-12-31',
                    'status' => 'aktif',
                ]
            );

            // 2. Struktur kelas Ganjil: tingkat ganjil (1, 3, 5) × huruf A-E.
            foreach (['1', '3', '5'] as $tingkat) {
                foreach (['A', 'B', 'C', 'D', 'E'] as $huruf) {
                    Kelas::updateOrCreate(
                        [
                            'prodi_id' => 2,
                            'semester_id' => $ganjil->id,
                            'tingkat' => $tingkat,
                            'nama' => $huruf,
                        ],
                        ['status' => 'aktif']
                    );
                }
            }

            // 3. Kenaikan tingkat mahasiswa angkatan 2024: 4x → 5x di Ganjil.
            foreach (User::where('angkatan', 2024)->whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))->get() as $user) {
                $labelLama = $user->kelas; // contoh "4B"
                if ($labelLama === null || $labelLama === '') {
                    continue;
                }
                $labelBaru = '5'.substr($labelLama, 1);

                $kelasBaru = Kelas::where('semester_id', $ganjil->id)
                    ->where('tingkat', '5')
                    ->where('nama', substr($labelLama, 1))
                    ->first();
                if (! $kelasBaru) {
                    continue;
                }

                $user->forceFill(['kelas' => $labelBaru, 'semester' => 5])->saveQuietly();
                MahasiswaKelas::updateOrCreate(
                    ['user_id' => $user->id, 'semester_id' => $ganjil->id],
                    ['kelas_id' => $kelasBaru->id]
                );
            }

            // 4. Mahasiswa angkatan 2026 (tingkat 1) selaraskan pivot ke semester
            //    aktif — kelas 1A-1E milik mereka di Ganjil ini.
            $kelasTingkat1 = Kelas::where('semester_id', $ganjil->id)->where('tingkat', '1')->get()->keyBy('nama');
            foreach (User::where('angkatan', 2026)->whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))->get() as $user) {
                $huruf = substr((string) $user->kelas, 1);
                $kelas = $kelasTingkat1->get($huruf);
                if (! $kelas) {
                    continue;
                }
                $user->forceFill(['semester' => 1])->saveQuietly();
                MahasiswaKelas::updateOrCreate(
                    ['user_id' => $user->id, 'semester_id' => $ganjil->id],
                    ['kelas_id' => $kelas->id]
                );
            }

            // 5. Akumulasi alpha untuk semester aktif baru (selaras perilaku
            //    SemesterController saat mengaktifkan semester).
            $mahasiswaIds = User::whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))
                ->where('status', 'aktif')
                ->pluck('id');
            foreach ($mahasiswaIds as $mhsId) {
                AlphaAccumulation::firstOrCreate(
                    ['user_id' => $mhsId, 'semester_id' => $ganjil->id],
                    [
                        'total_alpha_menit' => 0,
                        'sp_status' => 'aman',
                        'last_calculated_at' => now(),
                    ]
                );
            }
        });

        $this->command?->line('Semester aktif sekarang: 2026/2027-1 (Ganjil). Kelas 1/3/5 A-E dibuat; angkatan 2024 naik ke tingkat 5.');
    }
}
