<?php

namespace App\Services;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

class BiometricEncryptionService
{
    private array $encrypters = [];

    public function __construct()
    {
        $raw = config('biometric.key');
        if (! $raw && app()->environment('testing')) {
            $raw = 'base64:'.base64_encode(hash('sha256', 'testing-biometric-key', true));
        }
        if (! $raw) {
            throw new RuntimeException('BIOMETRIC_ENCRYPTION_KEY wajib dikonfigurasi');
        }
        $keys = config('biometric.previous_keys', []);
        $keys[config('biometric.key_id')] = $raw;
        foreach ($keys as $id => $encoded) {
            $key = str_starts_with($encoded, 'base64:') ? base64_decode(substr($encoded, 7), true) : $encoded;
            if (! is_string($key) || strlen($key) !== 32) {
                throw new RuntimeException("Biometric key {$id} harus 32 byte");
            }
            $this->encrypters[$id] = new Encrypter($key, 'AES-256-GCM');
        }
    }

    public function encrypt(array $embedding): string
    {
        $this->assertEmbedding($embedding);

        return $this->encrypters[config('biometric.key_id')]->encryptString(json_encode(array_map('floatval', $embedding), JSON_THROW_ON_ERROR));
    }

    public function decrypt(string $ciphertext, string $keyId): array
    {
        if (! isset($this->encrypters[$keyId])) {
            throw new RuntimeException('Biometric key ID tidak dikenal');
        }
        $value = json_decode($this->encrypters[$keyId]->decryptString($ciphertext), true, flags: JSON_THROW_ON_ERROR);
        $this->assertEmbedding($value);

        return array_map('floatval', $value);
    }

    private function assertEmbedding(mixed $embedding): void
    {
        if (! is_array($embedding) || count($embedding) !== 192) {
            throw new RuntimeException('Embedding biometrik tidak valid');
        }
        foreach ($embedding as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                throw new RuntimeException('Embedding biometrik tidak valid');
            }
        }
    }
}
