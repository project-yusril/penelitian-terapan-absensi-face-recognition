<?php

namespace App\Services;

use App\Models\FaceEmbedding;
use App\Models\ProdiSetting;
use RuntimeException;

class BiometricDuplicateService
{
    public const EMBEDDING_SIZE = 192;

    public function isDuplicate(array $candidate, int $userId, int $prodiId): bool
    {
        $candidate = $this->canonicalize($candidate);
        $threshold = (float) (ProdiSetting::where('prodi_id', $prodiId)->value('face_threshold') ?? 1.00);

        if (! is_finite($threshold) || $threshold <= 0) {
            throw new RuntimeException('Biometric matching is unavailable');
        }

        $isDuplicate = false;
        foreach (FaceEmbedding::where('user_id', '!=', $userId)->whereIn('status', ['approved', 'pending'])->cursor() as $other) {
            $stored = $this->canonicalize($other->embedding);
            $sum = 0.0;

            for ($index = 0; $index < self::EMBEDDING_SIZE; $index++) {
                $difference = $candidate[$index] - $stored[$index];
                $sum += $difference * $difference;
            }

            if (! is_finite($sum)) {
                throw new RuntimeException('Biometric matching is unavailable');
            }

            if (sqrt($sum) < $threshold) {
                $isDuplicate = true;
            }
        }

        return $isDuplicate;
    }

    private function canonicalize(array $embedding): array
    {
        if (count($embedding) !== self::EMBEDDING_SIZE) {
            throw new RuntimeException('Biometric matching is unavailable');
        }

        return array_map(function (mixed $value): float {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                throw new RuntimeException('Biometric matching is unavailable');
            }

            return (float) $value;
        }, array_values($embedding));
    }
}
