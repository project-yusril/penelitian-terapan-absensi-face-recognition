<?php

namespace App\Http\Controllers\Api\Mahasiswa;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\FaceEmbedding;
use App\Models\ProdiSetting;
use App\Models\ReEnrollmentRequest;
use App\Models\User;
use App\Services\BiometricDuplicateService;
use App\Services\BiometricLockService;
use App\Services\PrivateFileUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnrollmentController extends Controller
{
    private const DUPLICATE_MESSAGE = 'Data biometrik tidak dapat digunakan untuk pendaftaran.';

    /**
     * Submit face enrollment.
     * R-01: foto wajah disimpan ke disk privat (storage/app/face) — bukan disk "public".
     */
    public function store(Request $request, BiometricDuplicateService $duplicates, BiometricLockService $lock): JsonResponse
    {
        $request->validate([
            'embedding' => 'required|array|size:'.BiometricDuplicateService::EMBEDDING_SIZE,
            'embedding.*' => ['required', 'numeric', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_finite((float) $value)) {
                    $fail("The {$attribute} field must be finite.");
                }
            }],
            'foto' => 'required|image|mimes:jpeg,jpg,png|max:500',
            'liveness_passed' => 'required|boolean',
            'enrollment_device' => 'nullable|string|max:255',
        ]);

        $user = $request->user();

        // Sudah ter-enroll & disetujui → tidak boleh daftar ulang lewat sini.
        // Perubahan wajah harus melalui re-enrollment (butuh akses dibuka admin).
        if ($user->enrollment_status === 'approved') {
            return $this->error('Wajah Anda sudah terdaftar. Gunakan re-enrollment (hubungi admin) jika ingin mengubah.', 422);
        }

        // Validasi liveness
        if (! $request->boolean('liveness_passed')) {
            return $this->error('Liveness detection gagal. Pastikan wajah Anda terdeteksi dengan benar.', 422);
        }

        $newEmbedding = array_map('floatval', $request->embedding);

        // R-01: simpan ke disk privat "face" (bukan public)
        $fotoPath = $request->file('foto')->store('enrollment', 'face');

        try {
            $isDuplicate = $lock->run(function () use ($duplicates, $user, $newEmbedding, $request, $fotoPath): bool {
                return DB::transaction(function () use ($duplicates, $user, $newEmbedding, $request, $fotoPath): bool {
                    $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                    abort_if($lockedUser->enrollment_status === 'pending', 409, 'Enrollment sedang menunggu persetujuan');
                    $isDuplicate = $duplicates->isDuplicate($newEmbedding, $lockedUser->id, $lockedUser->prodi_id);
                    $this->auditProbe($request, $isDuplicate ? 'duplicate' : 'clear');
                    if ($isDuplicate) {
                        return true;
                    }

                    $version = (int) FaceEmbedding::where('user_id', $lockedUser->id)->max('version') + 1;
                    $candidate = FaceEmbedding::create([
                        'user_id' => $lockedUser->id, 'embedding' => $newEmbedding, 'version' => $version,
                        'enrollment_device' => $request->enrollment_device,
                        'liveness_passed' => $request->boolean('liveness_passed'), 'status' => 'pending',
                    ]);
                    $lockedUser->update(['enrollment_status' => 'pending', 'foto_enrollment' => $fotoPath]);
                    AuditTrail::create([
                        'user_id' => $lockedUser->id, 'action' => 'enrollment_submitted', 'model_type' => FaceEmbedding::class,
                        'model_id' => $candidate->id, 'old_values' => ['status' => $user->enrollment_status],
                        'new_values' => ['status' => 'pending', 'version' => $version],
                        'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
                    ]);

                    return false;
                });
            });
        } catch (\Throwable $exception) {
            Storage::disk('face')->delete($fotoPath);
            if (! ($exception instanceof HttpException)) {
                $this->auditProbe($request, 'unavailable');
            }
            throw $exception;
        }

        if ($isDuplicate) {
            Storage::disk('face')->delete($fotoPath);

            return $this->duplicateResponse();
        }

        return $this->created([
            'enrollment_status' => 'pending',
            'message' => 'Pendaftaran wajah berhasil. Menunggu persetujuan Kaprodi.',
        ], 'Pendaftaran wajah berhasil');
    }

    /**
     * Cek dini apakah wajah (embedding) sudah terdaftar atas nama user LAIN.
     *
     * Dipanggil dari mobile SAAT tahap liveness lolos — sebelum foto enrollment
     * disubmit — agar user langsung tahu "Anda terdeteksi sebagai mahasiswa X"
     * tanpa harus menyelesaikan seluruh proses enrollment dulu.
     *
     * Tidak menyimpan apa pun ke database; murni perbandingan embedding.
     */
    public function checkDuplicate(Request $request, BiometricDuplicateService $duplicates): JsonResponse
    {
        $request->validate([
            'embedding' => 'required|array|size:'.BiometricDuplicateService::EMBEDDING_SIZE,
            'embedding.*' => ['required', 'numeric', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_finite((float) $value)) {
                    $fail("The {$attribute} field must be finite.");
                }
            }],
        ]);

        $user = $request->user();
        try {
            $isDuplicate = $duplicates->isDuplicate($request->embedding, $user->id, $user->prodi_id);
            $this->auditProbe($request, $isDuplicate ? 'duplicate' : 'clear');
        } catch (\Throwable $exception) {
            $this->auditProbe($request, 'unavailable');
            throw $exception;
        }

        if ($isDuplicate) {
            return $this->duplicateResponse();
        }

        return response()->json(['is_duplicate' => false])
            ->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    /**
     * Cek status enrollment
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        $embedding = FaceEmbedding::where('user_id', $user->id)
            ->latest()
            ->first();

        return $this->success([
            'enrollment_status' => $user->enrollment_status,
            'has_embedding' => $embedding !== null,
            'embedding_status' => $embedding?->status,
            // R-01: jangan expose URL publik. Gunakan signed URL kalau dibutuhkan.
            'foto_enrollment_url' => $user->foto_enrollment
                ? app(PrivateFileUrlService::class)->enrollmentPhoto($user)
                : null,
        ]);
    }

    /**
     * Request re-enrollment
     */
    public function requestReEnrollment(Request $request): JsonResponse
    {
        $request->validate([
            'alasan' => 'required|in:potong_rambut,pakai_jilbab,lepas_jilbab,perubahan_lain',
            'keterangan' => 'nullable|string|max:500',
            'foto' => 'required|image|mimes:jpeg,jpg,png|max:500',
            'embedding' => 'required|array|size:'.BiometricDuplicateService::EMBEDDING_SIZE,
            'embedding.*' => 'required|numeric',
        ]);

        $user = $request->user();

        if ($user->enrollment_status !== 'approved') {
            return $this->error('Re-enrollment hanya bisa dilakukan jika enrollment sebelumnya sudah disetujui', 422);
        }

        // R-01: simpan ke disk privat
        $fotoPath = $request->file('foto')->store('re-enrollment', 'face');

        try {
            DB::transaction(function () use ($user, $request, $fotoPath): void {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_if(ReEnrollmentRequest::where('user_id', $user->id)->where('status', 'pending')->exists(), 409, 'Anda sudah memiliki request re-enrollment yang masih pending');
                ReEnrollmentRequest::create([
                    'user_id' => $user->id,
                    'alasan' => $request->alasan,
                    'keterangan' => $request->keterangan,
                    'foto_baru' => $fotoPath,
                    'new_embedding' => array_map('floatval', $request->embedding),
                    'status' => 'pending',
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('face')->delete($fotoPath);
            throw $exception;
        }

        return $this->created([
            'message' => 'Request re-enrollment berhasil disubmit. Menunggu persetujuan.',
        ], 'Request re-enrollment berhasil');
    }

    /**
     * Get active embedding for local cache on mobile device.
     * H-04: ikut mengirim face_threshold prodi agar mobile menggunakan ambang yang sama
     * dengan backend (single source of truth). Cegah false-accept akibat threshold
     * berbeda antara client & server.
     */
    public function getMyEmbedding(Request $request): JsonResponse
    {
        $user = $request->user();

        $embedding = FaceEmbedding::where('user_id', $user->id)
            ->where('status', 'approved')
            ->orderByDesc('version')
            ->first();

        if (! $embedding) {
            return $this->notFound('Embedding tidak ditemukan');
        }

        $prodiSetting = ProdiSetting::where('prodi_id', $user->prodi_id)->first();
        AuditTrail::create([
            'user_id' => $user->id, 'action' => 'biometric_embedding_accessed', 'model_type' => FaceEmbedding::class,
            'model_id' => $embedding->id, 'old_values' => [], 'new_values' => ['version' => $embedding->version],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return $this->success([
            'embedding' => $embedding->embedding,
            'embedding_size' => count($embedding->embedding),
            'version' => $embedding->version,
            'created_at' => $embedding->created_at,
            'face_threshold' => (float) ($prodiSetting?->face_threshold ?? 1.00),
            'liveness_required' => true,
        ])->withHeaders(['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache']);
    }

    private function duplicateResponse(): JsonResponse
    {
        return response()->json([
            'code' => 'BIOMETRIC_CONFLICT',
            'message' => self::DUPLICATE_MESSAGE,
        ], 409)->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    private function auditProbe(Request $request, string $outcome): void
    {
        AuditTrail::create([
            'user_id' => $request->user()->id,
            'action' => 'biometric_probe',
            'new_values' => [
                'outcome' => $outcome,
                'embedding_size' => count($request->input('embedding', [])),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
