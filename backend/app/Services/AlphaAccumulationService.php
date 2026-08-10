<?php

namespace App\Services;

use App\Enums\SpLevel;
use App\Models\AlphaAccumulation;
use App\Models\Attendance;
use App\Models\ProdiSetting;
use App\Models\Semester;
use App\Models\User;

class AlphaAccumulationService
{
    /**
     * Recalculate total alpha untuk user di semester tertentu
     */
    public function recalculate(int $userId, ?int $semesterId = null): ?AlphaAccumulation
    {
        if (! $semesterId) {
            $semesterAktif = Semester::where('status', 'aktif')->first();
            $semesterId = $semesterAktif?->id;
        }

        if (! $semesterId) {
            return null;
        }

        // PRD-02B FR-SP-001: SUM alpha_menit dari SEMUA attendance (H-01)
        // CASE 1: hadir tepat waktu → alpha_menit = 0
        // CASE 2: hadir_terlambat → alpha_menit = keterlambatan
        // CASE 3: pulang awal (status tetap "hadir") → alpha_menit > 0, WAJIB ikut dihitung
        // CASE 4: alpha → alpha_menit = durasi MK
        // CASE 5: izin/sakit approved → alpha_menit = 0
        // CASE 6: pending → alpha_menit = sementara alpha penuh
        // Catatan: filter status DIHAPUS agar alpha "pulang awal" pada status `hadir`
        // ikut terhitung. Status izin/sakit yang approved sudah ber-alpha_menit = 0.
        $totalAlphaMenit = Attendance::where('user_id', $userId)
            ->whereHas('mataKuliah', fn ($q) => $q->where('semester_id', $semesterId))
            ->sum('alpha_menit');

        $totalAlphaJam = round($totalAlphaMenit / 60.0, 2);

        $accumulation = AlphaAccumulation::updateOrCreate(
            [
                'user_id' => $userId,
                'semester_id' => $semesterId,
            ],
            [
                'total_alpha_menit' => $totalAlphaMenit,
                'total_alpha_jam' => $totalAlphaJam,
                'last_calculated_at' => now(),
            ]
        );

        // Use total_alpha_jam for SP evaluation (thresholds are in JAM) — H-06: hapus baris duplikat
        $spLevel = $this->evaluateSpLevel($userId, $totalAlphaJam);
        $accumulation->update(['sp_status' => $spLevel->value]);

        return $accumulation->fresh();
    }

    /**
     * Evaluasi SP level berdasarkan total alpha (dalam JAM)
     */
    public function evaluateSpLevel(int $userId, float $totalAlphaJam): SpLevel
    {
        $user = User::find($userId);
        $thresholds = $this->getSpThresholds($user?->prodi_id);

        if ($totalAlphaJam >= $thresholds['do']) {
            return SpLevel::Do;
        } elseif ($totalAlphaJam >= $thresholds['sp3']) {
            return SpLevel::Sp3;
        } elseif ($totalAlphaJam >= $thresholds['sp2']) {
            return SpLevel::Sp2;
        } elseif ($totalAlphaJam >= $thresholds['sp1']) {
            return SpLevel::Sp1;
        }

        return SpLevel::Aman;
    }

    /**
     * Ambil threshold SP dari prodi settings (dalam JAM)
     */
    public function getSpThresholds(?int $prodiId): array
    {
        $defaults = [
            'sp1' => 16,
            'sp2' => 32,
            'sp3' => 38,
            'do' => 46,
        ];

        if (! $prodiId) {
            return $defaults;
        }

        $setting = ProdiSetting::where('prodi_id', $prodiId)->first();

        if (! $setting) {
            return $defaults;
        }

        return [
            'sp1' => $setting->sp1_jam_mulai ?? $defaults['sp1'],
            'sp2' => $setting->sp2_jam_mulai ?? $defaults['sp2'],
            'sp3' => $setting->sp3_jam_mulai ?? $defaults['sp3'],
            'do' => $setting->do_jam_mulai ?? $defaults['do'],
        ];
    }

    /**
     * Cek apakah mahasiswa mendekati threshold SP berikutnya (80%)
     */
    public function isApproachingNextLevel(int $userId, float $totalAlphaJam): ?string
    {
        $user = User::find($userId);
        $thresholds = $this->getSpThresholds($user?->prodi_id);
        $currentLevel = $this->evaluateSpLevel($userId, $totalAlphaJam);

        $nextLevel = $currentLevel->next();
        $nextThreshold = $nextLevel ? $thresholds[$nextLevel->value] : null;

        if (! $nextThreshold) {
            return null;
        }

        $approachingAt = $nextThreshold * 0.8;

        if ($totalAlphaJam >= $approachingAt && $totalAlphaJam < $nextThreshold) {
            return $nextLevel->approachingCode();
        }

        return null;
    }

    /**
     * Recalculate untuk semua mahasiswa aktif di semester
     */
    public function recalculateAll(?int $semesterId = null): int
    {
        if (! $semesterId) {
            $semesterAktif = Semester::where('status', 'aktif')->first();
            $semesterId = $semesterAktif?->id;
        }

        if (! $semesterId) {
            return 0;
        }

        $mahasiswas = User::whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))
            ->where('status', 'aktif')
            ->pluck('id');

        $count = 0;
        foreach ($mahasiswas as $mahasiswaId) {
            $this->recalculate($mahasiswaId, $semesterId);
            $count++;
        }

        return $count;
    }
}
