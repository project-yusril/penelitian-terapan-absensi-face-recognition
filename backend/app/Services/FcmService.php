<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging Service
 * Menggunakan FCM HTTP v1 API langsung (tanpa kreait/laravel-firebase)
 *
 * Setup:
 * 1. Buat project di Firebase Console
 * 2. Download service account JSON → simpan di storage/app/firebase-credentials.json
 * 3. Set FIREBASE_PROJECT_ID di .env
 * 4. Set FIREBASE_CREDENTIALS_PATH di .env (default: storage/app/firebase-credentials.json)
 */
class FcmService
{
    protected ?string $projectId;

    protected ?string $credentialsPath;

    protected ?string $accessToken = null;

    public function __construct()
    {
        $this->projectId = config('services.firebase.project_id');
        $this->credentialsPath = config('services.firebase.credentials');
    }

    /**
     * Kirim push notification ke satu device
     */
    public function sendToDevice(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        if (! $this->isConfigured()) {
            Log::debug('FCM: Not configured, skipping push notification', [
                'title' => $title,
                'token' => substr($fcmToken, 0, 20).'...',
            ]);

            return false;
        }

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => array_map('strval', $data), // FCM data harus string semua
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => 'attendance_channel',
                        'sound' => 'default',
                    ],
                ],
            ],
        ];

        return $this->send($payload);
    }

    /**
     * Kirim push notification ke multiple devices
     */
    public function sendToDevices(array $fcmTokens, string $title, string $body, array $data = []): int
    {
        $successCount = 0;

        foreach ($fcmTokens as $token) {
            if ($this->sendToDevice($token, $title, $body, $data)) {
                $successCount++;
            }
        }

        return $successCount;
    }

    /**
     * Kirim ke topic
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $payload = [
            'message' => [
                'topic' => $topic,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => array_map('strval', $data),
            ],
        ];

        return $this->send($payload);
    }

    /**
     * Kirim request ke FCM API
     */
    protected function send(array $payload): bool
    {
        try {
            $accessToken = $this->getAccessToken();

            if (! $accessToken) {
                Log::warning('FCM: Failed to get access token');

                return false;
            }

            $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post($url, $payload);

            if ($response->successful()) {
                Log::debug('FCM: Message sent successfully', [
                    'response' => $response->json(),
                ]);

                return true;
            }

            Log::warning('FCM: Failed to send message', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('FCM: Exception while sending', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get OAuth2 access token dari service account
     */
    protected function getAccessToken(): ?string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        if (! file_exists($this->credentialsPath)) {
            Log::debug('FCM: Credentials file not found at '.$this->credentialsPath);

            return null;
        }

        try {
            $credentials = json_decode(file_get_contents($this->credentialsPath), true);

            // Create JWT
            $now = time();
            $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $payload = base64url_encode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

            $signature = '';
            openssl_sign(
                "{$header}.{$payload}",
                $signature,
                $credentials['private_key'],
                OPENSSL_ALGO_SHA256
            );
            $signature = base64url_encode($signature);

            $jwt = "{$header}.{$payload}.{$signature}";

            // Exchange JWT for access token
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($response->successful()) {
                $this->accessToken = $response->json('access_token');

                return $this->accessToken;
            }

            Log::warning('FCM: Failed to get access token', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('FCM: Exception getting access token', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cek apakah Firebase sudah dikonfigurasi
     */
    public function isConfigured(): bool
    {
        return ! empty($this->projectId) && ! empty($this->credentialsPath) && file_exists($this->credentialsPath);
    }
}
