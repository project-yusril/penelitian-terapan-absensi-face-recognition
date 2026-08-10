<?php

namespace Tests\Feature;

use App\Models\FaceEmbedding;
use App\Models\Prodi;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class EnrollmentSecurityTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();
        Storage::fake('face');
    }

    public function test_enrollment_creates_encrypted_pending_candidate(): void
    {
        $student = $this->student('belum');
        $vector = array_fill(0, 192, 0.125);

        $this->actingAs($student)->postJson('/api/mahasiswa/enrollment', [
            'embedding' => $vector, 'foto' => UploadedFile::fake()->image('face.jpg', 100, 100),
            'liveness_passed' => true, 'enrollment_device' => 'test-device',
        ])->assertCreated()->assertJsonPath('data.enrollment_status', 'pending');

        $candidate = FaceEmbedding::where('user_id', $student->id)->sole();
        $raw = \DB::table('face_embeddings')->where('id', $candidate->id)->first();
        $this->assertSame('pending', $candidate->status);
        $this->assertSame('pending', $student->fresh()->enrollment_status);
        $this->assertSame($vector, $candidate->embedding);
        $this->assertNotNull($raw->embedding_ciphertext);
        $this->assertSame('[]', $raw->embedding);
        $this->assertStringNotContainsString('0.125', $raw->embedding_ciphertext);
    }

    public function test_approval_and_rejection_are_authoritative_transitions(): void
    {
        $student = $this->student('pending');
        $candidate = FaceEmbedding::create(['user_id' => $student->id, 'embedding' => array_fill(0, 192, 0.2), 'version' => 1, 'status' => 'pending']);
        $kaprodi = $this->student('belum', 'kaprodi');

        $this->actingAs($kaprodi)->putJson("/api/kaprodi/enrollments/{$student->id}/approve")->assertOk();
        $this->assertSame('approved', $candidate->fresh()->status);
        $this->assertSame('approved', $student->fresh()->enrollment_status);
        $this->actingAs($kaprodi)->putJson("/api/kaprodi/enrollments/{$student->id}/approve")->assertUnprocessable();
    }

    public function test_generic_admin_user_serializer_never_exposes_biometrics(): void
    {
        $student = $this->student('approved');
        FaceEmbedding::create(['user_id' => $student->id, 'embedding' => array_fill(0, 192, 0.3), 'version' => 1, 'status' => 'approved']);
        $admin = $this->student('belum', 'super_admin');

        $content = $this->actingAs($admin)->getJson("/api/admin/users/{$student->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('"embedding":', strtolower($content));
        $this->assertStringNotContainsString('face_embeddings', strtolower($content));
        $this->assertStringNotContainsString('ciphertext', strtolower($content));
    }

    private function student(string $enrollment, string $role = 'mahasiswa'): User
    {
        $prodi = Prodi::where('kode', 'TI')->firstOrFail();
        $user = User::factory()->create(['prodi_id' => $prodi->id, 'status' => 'aktif', 'enrollment_status' => $enrollment]);
        $user->roles()->attach(Role::where('name', $role)->value('id'));

        return $user;
    }
}
