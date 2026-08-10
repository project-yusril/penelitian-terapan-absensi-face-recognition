<?php

namespace App\Services;

use App\Models\Jadwal;
use App\Models\ProdiSetting;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class AttendancePolicyService
{
    public const DEFAULT_MAX_ACCURACY_METERS = 20;

    public const DEFAULT_MAX_AGE_SECONDS = 10;

    public function setting(int $prodiId): ?ProdiSetting
    {
        return ProdiSetting::where('prodi_id', $prodiId)->first();
    }

    public function windows(Jadwal $jadwal, Carbon $occurrence, ?ProdiSetting $setting): array
    {
        $date = $occurrence->toDateString();
        $start = Carbon::parse("{$date} {$jadwal->jam_mulai}");
        $end = Carbon::parse("{$date} {$jadwal->jam_selesai}");

        return [
            'starts_at' => $start,
            'ends_at' => $end,
            'not_before' => $start->copy()->subMinutes((int) ($setting?->toleransi_masuk_menit ?? 15)),
            'expires_at' => $end->copy()->addMinutes((int) ($setting?->toleransi_pulang_menit ?? 15)),
        ];
    }

    public function locationPolicy(?ProdiSetting $setting): array
    {
        return [
            'max_accuracy_meters' => (float) ($setting?->gps_accuracy_minimum ?? self::DEFAULT_MAX_ACCURACY_METERS),
            'max_age_seconds' => (int) ($setting?->gps_max_age_seconds ?? self::DEFAULT_MAX_AGE_SECONDS),
        ];
    }

    public function assertLocationEvidence(float $accuracy, int $ageMs, ?ProdiSetting $setting): void
    {
        $policy = $this->locationPolicy($setting);
        if (! is_finite($accuracy) || $accuracy < 0) {
            throw new UnprocessableEntityHttpException('gps_accuracy_invalid');
        }
        if ($accuracy > $policy['max_accuracy_meters']) {
            throw new UnprocessableEntityHttpException('gps_accuracy_exceeded');
        }
        if ($ageMs < 0) {
            throw new UnprocessableEntityHttpException('location_age_invalid');
        }
        if ($ageMs > $policy['max_age_seconds'] * 1000) {
            throw new UnprocessableEntityHttpException('location_too_old');
        }
    }

    public function iso(Carbon $value): string
    {
        return $value->toISOString();
    }
}
