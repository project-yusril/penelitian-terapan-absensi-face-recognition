<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\FaceEmbedding;
use App\Models\Prodi;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class BiometricDuplicateContractTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private const CONFLICT = [
        'code' => 'BIOMETRIC_CONFLICT',
        'message' => 'Data biometrik tidak dapat digunakan untuk pendaftaran.',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();
        Storage::fake('face');
        RateLimiter::clear('');
    }

    public function test_probe_requires_authentication_and_student_role(): void
    {
        $this->postJson('/api/mahasiswa/enrollment/check-duplicate', ['embedding' => $this->vector(0.1)])
            ->assertUnauthorized();

        $operator = $this->user('super_admin');
        $this->actingAs($operator)->postJson('/api/mahasiswa/enrollment/check-duplicate', ['embedding' => $this->vector(0.1)])
            ->assertForbidden();
    }

    public function test_probe_requires_exact_finite_192_vector(): void
    {
        $student = $this->user();

        $this->actingAs($student)->postJson('/api/mahasiswa/enrollment/check-duplicate', ['embedding' => array_fill(0, 191, 0.1)])
            ->assertUnprocessable();
        $vector = $this->vector(0.1);
        $vector[12] = 'INF';
        $this->actingAs($student)->postJson('/api/mahasiswa/enrollment/check-duplicate', ['embedding' => $vector])
            ->assertUnprocessable();
        $vector[12] = 'NAN';
        $this->actingAs($student)->postJson('/api/mahasiswa/enrollment/check-duplicate', ['embedding' => $vector])
            ->assertUnprocessable();
    }

    public function test_probe_returns_minimal_clear_result_and_allowlisted_audit(): void
    {
        $student = $this->user();
        $response = $this->actingAs($student)->withHeaders([
            'User-Agent' => 'contract-agent',
            'X-Forwarded-For' => '198.51.100.10',
        ])->postJson('/api/mahasiswa/enrollment/check-duplicate', ['embedding' => $this->vector(0.1)]);

        $response->assertOk()->assertExactJson(['is_duplicate' => false]);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $audit = AuditTrail::where('action', 'biometric_probe')->sole();
        $this->assertSame($student->id, $audit->user_id);
        $this->assertSame(['outcome' => 'clear', 'embedding_size' => 192], $audit->new_values);
        $this->assertNull($audit->model_type);
        $this->assertNull($audit->model_id);
        $this->assertNull($audit->old_values);
        $this->assertSame('contract-agent', $audit->user_agent);
    }

    public function test_probe_conflict_identifies_owner_by_name_without_other_sensitive_fields(): void
    {
        $owner = $this->user();
        $owner->update(['nama' => 'Sensitive Name', 'nim' => 'SECRET-NIM', 'kelas' => 'SEC']);
        FaceEmbedding::create(['user_id' => $owner->id, 'embedding' => $this->vector(0.2), 'version' => 1, 'status' => 'approved']);
        $student = $this->user();

        $response = $this->actingAs($student)->postJson('/api/mahasiswa/enrollment/check-duplicate', ['embedding' => $this->vector(0.2)]);

        $response->assertConflict()->assertExactJson([
            ...self::CONFLICT,
            'matched_name' => 'Sensitive Name',
            'logout_required' => false,
        ]);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $content = strtolower($response->getContent());
        foreach (['secret-nim', '"sec"', 'distance', 'threshold', 'user_id', 'embedding'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $content);
        }
        $this->assertSame('duplicate', AuditTrail::where('action', 'biometric_probe')->sole()->new_values['outcome']);
    }

    public function test_probe_identifies_the_closest_matching_owner(): void
    {
        $farther = $this->user();
        $farther->update(['nama' => 'Pemilik Lebih Jauh']);
        FaceEmbedding::create(['user_id' => $farther->id, 'embedding' => $this->vector(0.15), 'version' => 1, 'status' => 'approved']);

        $closest = $this->user();
        $closest->update(['nama' => 'Yusril']);
        FaceEmbedding::create(['user_id' => $closest->id, 'embedding' => $this->vector(0.19), 'version' => 1, 'status' => 'approved']);

        $student = $this->user();
        $this->actingAs($student)
            ->postJson('/api/mahasiswa/enrollment/check-duplicate', ['embedding' => $this->vector(0.2)])
            ->assertConflict()
            ->assertJsonPath('matched_name', 'Yusril');
    }

    public function test_probe_does_not_disclose_inactive_or_other_prodi_identity(): void
    {
        $inactive = $this->user();
        $inactive->update(['nama' => 'Akun Nonaktif', 'status' => 'nonaktif']);
        FaceEmbedding::create(['user_id' => $inactive->id, 'embedding' => $this->vector(0.2), 'version' => 1, 'status' => 'approved']);

        $otherProdi = Prodi::where('kode', 'TE')->firstOrFail();
        $outsider = User::factory()->create([
            'nama' => 'Mahasiswa Prodi Lain',
            'prodi_id' => $otherProdi->id,
            'status' => 'aktif',
            'enrollment_status' => 'approved',
        ]);
        FaceEmbedding::create(['user_id' => $outsider->id, 'embedding' => $this->vector(0.2), 'version' => 1, 'status' => 'approved']);

        $this->actingAs($this->user())
            ->postJson('/api/mahasiswa/enrollment/check-duplicate', ['embedding' => $this->vector(0.2)])
            ->assertOk()
            ->assertExactJson(['is_duplicate' => false]);
    }

    public function test_third_conflict_on_the_same_token_requires_logout(): void
    {
        $owner = $this->user();
        FaceEmbedding::create(['user_id' => $owner->id, 'embedding' => $this->vector(0.2), 'version' => 1, 'status' => 'approved']);
        $student = $this->user();
        $token = $student->createToken('mobile-test')->plainTextToken;
        $payload = ['embedding' => $this->vector(0.2)];

        $this->withToken($token)->postJson('/api/mahasiswa/enrollment/check-duplicate', $payload)
            ->assertConflict()->assertJsonPath('logout_required', false);
        $this->withToken($token)->postJson('/api/mahasiswa/enrollment/check-duplicate', $payload)
            ->assertConflict()->assertJsonPath('logout_required', false);
        $this->withToken($token)->postJson('/api/mahasiswa/enrollment/check-duplicate', $payload)
            ->assertConflict()->assertJsonPath('logout_required', true);
        $this->assertNull(PersonalAccessToken::findToken($token));

        $newToken = $student->createToken('mobile-test-new-session')->plainTextToken;
        $this->withToken($newToken)->postJson('/api/mahasiswa/enrollment/check-duplicate', $payload)
            ->assertConflict()->assertJsonPath('logout_required', false);
    }

    public function test_stateful_session_cannot_reset_conflicts_with_fake_bearer_headers(): void
    {
        $owner = $this->user();
        FaceEmbedding::create(['user_id' => $owner->id, 'embedding' => $this->vector(0.2), 'version' => 1, 'status' => 'approved']);
        $payload = ['embedding' => $this->vector(0.2)];

        $this->actingAs($this->user())
            ->withSession(['biometric-test' => true])
            ->withToken('fake-token-one')
            ->postJson('/api/mahasiswa/enrollment/check-duplicate', $payload)
            ->assertConflict()->assertJsonPath('logout_required', false);
        $this->withToken('fake-token-two')
            ->postJson('/api/mahasiswa/enrollment/check-duplicate', $payload)
            ->assertConflict()->assertJsonPath('logout_required', false);
        $this->withToken('fake-token-three')
            ->postJson('/api/mahasiswa/enrollment/check-duplicate', $payload)
            ->assertConflict()->assertJsonPath('logout_required', true);
    }

    public function test_enrollment_conflict_has_probe_parity_and_creates_no_orphan(): void
    {
        $owner = $this->user();
        FaceEmbedding::create(['user_id' => $owner->id, 'embedding' => $this->vector(0.3), 'version' => 1, 'status' => 'approved']);
        $student = $this->user();

        $response = $this->actingAs($student)->postJson('/api/mahasiswa/enrollment', [
            'embedding' => $this->vector(0.3),
            'foto' => UploadedFile::fake()->image('face.jpg', 100, 100),
            'liveness_passed' => true,
        ]);

        $response->assertConflict()->assertExactJson(self::CONFLICT);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertDatabaseMissing('face_embeddings', ['user_id' => $student->id]);
        $this->assertEmpty(Storage::disk('face')->allFiles());
    }

    public function test_enrollment_submit_conflicts_with_another_pending_candidate(): void
    {
        $owner = $this->user();
        FaceEmbedding::create(['user_id' => $owner->id, 'embedding' => $this->vector(0.35), 'version' => 1, 'status' => 'pending']);
        $student = $this->user();

        $this->actingAs($student)->postJson('/api/mahasiswa/enrollment', [
            'embedding' => $this->vector(0.35),
            'foto' => UploadedFile::fake()->image('face.jpg', 100, 100),
            'liveness_passed' => true,
        ])->assertConflict()->assertExactJson(self::CONFLICT);

        $this->assertDatabaseMissing('face_embeddings', ['user_id' => $student->id]);
        $this->assertSame('duplicate', AuditTrail::where('action', 'biometric_probe')->sole()->new_values['outcome']);
        $this->assertEmpty(Storage::disk('face')->allFiles());
    }

    public function test_approval_rechecks_after_stale_clear_result_and_conflicts_when_other_candidate_won(): void
    {
        $student = $this->user();
        $student->update(['enrollment_status' => 'pending']);
        $candidate = FaceEmbedding::create(['user_id' => $student->id, 'embedding' => $this->vector(0.45), 'version' => 1, 'status' => 'pending']);
        $kaprodi = $this->user('kaprodi');

        $this->actingAs($student)->postJson('/api/mahasiswa/enrollment/check-duplicate', ['embedding' => $this->vector(0.45)])
            ->assertOk();

        $winner = $this->user();
        FaceEmbedding::create(['user_id' => $winner->id, 'embedding' => $this->vector(0.45), 'version' => 1, 'status' => 'approved']);

        $response = $this->actingAs($kaprodi)->putJson("/api/kaprodi/enrollments/{$student->id}/approve");

        $response->assertConflict()->assertExactJson(self::CONFLICT);
        $this->assertSame('pending', $candidate->fresh()->status);
        $this->assertSame('pending', $student->fresh()->enrollment_status);
        $audit = AuditTrail::where('action', 'enrollment_approval_conflict')->sole();
        $this->assertSame(['outcome' => 'conflict'], $audit->new_values);
        $this->assertSame(['status' => 'pending'], $audit->old_values);
    }

    public function test_probe_rate_limiter_enforces_user_and_secondary_ip_limits_with_retry_after(): void
    {
        config()->set('biometric.probe_rate_limits.user_per_minute', 2);
        config()->set('biometric.probe_rate_limits.user_per_hour', 10);
        config()->set('biometric.probe_rate_limits.ip_per_minute', 4);
        $first = $this->user();
        $second = $this->user();
        $third = $this->user();
        $fourth = $this->user();
        $fifth = $this->user();
        $sixth = $this->user();
        $payload = ['embedding' => $this->vector(0.7)];
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21']);
        $this->actingAs($first)->postJson('/api/mahasiswa/enrollment/check-duplicate', $payload)->assertOk();
        $this->actingAs($first)->postJson('/api/mahasiswa/enrollment/check-duplicate', $payload)->assertOk();
        $limited = $this->actingAs($first)->postJson('/api/mahasiswa/enrollment/check-duplicate', $payload)->assertTooManyRequests();
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.22']);
        $this->actingAs($second)->postJson('/api/mahasiswa/enrollment/check-duplicate', $payload)->assertOk();
        $this->actingAs($third)->postJson('/api/mahasiswa/enrollment/check-duplicate', $payload)->assertOk();
        $this->actingAs($fourth)->postJson('/api/mahasiswa/enrollment/check-duplicate', $payload)->assertOk();
        $this->actingAs($fifth)->postJson('/api/mahasiswa/enrollment/check-duplicate', $payload)->assertOk();
        $this->actingAs($sixth)->postJson('/api/mahasiswa/enrollment/check-duplicate', $payload)->assertTooManyRequests();
    }

    private function vector(float $value): array
    {
        return array_fill(0, 192, $value);
    }

    private function user(string $role = 'mahasiswa'): User
    {
        $prodi = Prodi::where('kode', 'TI')->firstOrFail();
        $user = User::factory()->create(['prodi_id' => $prodi->id, 'status' => 'aktif', 'enrollment_status' => 'belum']);
        $user->roles()->attach(Role::where('name', $role)->value('id'));

        return $user;
    }
}
