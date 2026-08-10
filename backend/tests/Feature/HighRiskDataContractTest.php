<?php

namespace Tests\Feature;

use App\Models\FaceEmbedding;
use App\Models\LeaveRequest;
use App\Models\MataKuliah;
use App\Models\Prodi;
use App\Models\ReEnrollmentRequest;
use App\Models\Role;
use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class HighRiskDataContractTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();
        Storage::fake('face');
        Storage::fake('documents');
    }

    public function test_enrollment_photo_requires_authentication_and_object_authorization(): void
    {
        [$student] = $this->userWithRole('mahasiswa', 'TI', ['foto_enrollment' => 'enrollment/student.jpg']);
        [$other] = $this->userWithRole('mahasiswa', 'TE');
        [$kaprodi] = $this->userWithRole('kaprodi', 'TI');
        Storage::disk('face')->put('enrollment/student.jpg', 'private-face');
        $url = URL::temporarySignedRoute('private.enrollment-photos.show', now()->addMinute(), ['user' => $student->id]);

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($other)->getJson($url)->assertForbidden();
        $response = $this->actingAs($kaprodi)->getJson($url)->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('audit_trails', [
            'user_id' => $kaprodi->id, 'action' => 'enrollment_photo_accessed',
            'model_type' => User::class, 'model_id' => $student->id,
        ]);
    }

    public function test_leave_document_is_private_authorized_and_not_serialized_as_a_path(): void
    {
        [$student, $prodi] = $this->userWithRole('mahasiswa', 'TI');
        [$kaprodi] = $this->userWithRole('kaprodi', 'TI');
        $semester = $this->semester();
        $course = MataKuliah::create(['kode_mk' => 'PRI101', 'nama' => 'Privasi', 'sks' => 2, 'semester_id' => $semester->id, 'prodi_id' => $prodi->id, 'status' => 'aktif']);
        $leave = LeaveRequest::create([
            'user_id' => $student->id, 'mata_kuliah_id' => $course->id, 'jenis' => 'sakit',
            'tanggal_mulai' => now()->addDay()->toDateString(), 'tanggal_selesai' => now()->addDay()->toDateString(),
            'keterangan' => 'Dokumen medis', 'file_surat' => 'leave-requests/medical.pdf', 'status' => 'pending',
        ]);
        Storage::disk('documents')->put($leave->getRawOriginal('file_surat'), 'medical');

        $response = $this->actingAs($kaprodi)->getJson('/api/kaprodi/leave-requests')->assertOk();
        $this->assertStringNotContainsString('leave-requests/medical.pdf', $response->getContent());
        $url = $response->json('data.0.file_surat_url');
        $download = $this->actingAs($kaprodi)->getJson($url)->assertOk();
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
    }

    public function test_re_enrollment_approval_activates_encrypted_next_version_and_cleans_old_photo(): void
    {
        [$student] = $this->userWithRole('mahasiswa', 'TI', ['enrollment_status' => 'approved', 'foto_enrollment' => 'enrollment/old.jpg']);
        [$kaprodi] = $this->userWithRole('kaprodi', 'TI');
        FaceEmbedding::create(['user_id' => $student->id, 'embedding' => array_fill(0, 192, 0.1), 'version' => 3, 'status' => 'approved']);
        Storage::disk('face')->put('enrollment/old.jpg', 'old');
        Storage::disk('face')->put('re-enrollment/new.jpg', 'new');
        $vector = array_fill(0, 192, 0.7);
        $reEnrollment = ReEnrollmentRequest::create([
            'user_id' => $student->id, 'alasan' => 'perubahan_lain', 'keterangan' => 'berubah',
            'foto_baru' => 're-enrollment/new.jpg', 'new_embedding' => $vector, 'status' => 'pending',
        ]);

        $rawRequest = \DB::table('re_enrollment_requests')->where('id', $reEnrollment->id)->first();
        $this->assertSame('[]', $rawRequest->new_embedding);
        $this->assertNotNull($rawRequest->new_embedding_ciphertext);
        $this->actingAs($kaprodi)->putJson("/api/kaprodi/re-enrollments/{$reEnrollment->id}/approve")->assertOk();

        $active = FaceEmbedding::where('user_id', $student->id)->where('status', 'approved')->sole();
        $this->assertSame(4, $active->version);
        $this->assertSame($vector, $active->embedding);
        $this->assertSame('re-enrollment/new.jpg', $student->fresh()->getRawOriginal('foto_enrollment'));
        Storage::disk('face')->assertMissing('enrollment/old.jpg');
        Storage::disk('face')->assertExists('re-enrollment/new.jpg');
    }

    public function test_re_enrollment_and_leave_rejections_use_canonical_reason_and_cleanup_photo(): void
    {
        [$student, $prodi] = $this->userWithRole('mahasiswa', 'TI', ['enrollment_status' => 'approved']);
        [$kaprodi] = $this->userWithRole('kaprodi', 'TI');
        Storage::disk('face')->put('re-enrollment/rejected.jpg', 'reject');
        $request = ReEnrollmentRequest::create([
            'user_id' => $student->id, 'alasan' => 'potong_rambut', 'foto_baru' => 're-enrollment/rejected.jpg',
            'new_embedding' => array_fill(0, 192, 0.5), 'status' => 'pending',
        ]);
        $this->actingAs($kaprodi)->putJson("/api/kaprodi/re-enrollments/{$request->id}/reject", ['alasan' => 'Foto buram'])->assertOk();
        $this->assertDatabaseHas('re_enrollment_requests', ['id' => $request->id, 'rejected_reason' => 'Foto buram']);
        Storage::disk('face')->assertMissing('re-enrollment/rejected.jpg');

        $course = MataKuliah::create(['kode_mk' => 'REJ101', 'nama' => 'Kontrak', 'sks' => 2, 'semester_id' => $this->semester()->id, 'prodi_id' => $prodi->id, 'status' => 'aktif']);
        $leave = LeaveRequest::create(['user_id' => $student->id, 'mata_kuliah_id' => $course->id, 'jenis' => 'izin', 'tanggal_mulai' => now()->addDay(), 'tanggal_selesai' => now()->addDay(), 'status' => 'pending']);
        $this->actingAs($kaprodi)->putJson("/api/kaprodi/leave-requests/{$leave->id}/reject", ['alasan' => 'Tidak lengkap'])->assertOk();
        $this->assertDatabaseHas('leave_requests', ['id' => $leave->id, 'rejected_reason' => 'Tidak lengkap']);
    }

    public function test_admin_can_create_every_user_category_with_valid_enrollment_status(): void
    {
        [$admin] = $this->userWithRole('super_admin', 'TI');
        foreach (Role::query()->pluck('name')->all() as $index => $role) {
            $response = $this->actingAs($admin)->postJson('/api/admin/users', [
                'nama' => "Role {$role}", 'email' => "role-{$index}@test.com", 'roles' => [$role],
            ])->assertCreated();
            $expected = $role === 'mahasiswa' ? 'belum' : 'not_required';
            $response->assertJsonPath('data.enrollment_status', $expected);
            $this->assertDatabaseHas('users', ['email' => "role-{$index}@test.com", 'enrollment_status' => $expected]);
        }
    }

    public function test_semester_api_uses_schema_fields_and_enforces_code(): void
    {
        [$admin] = $this->userWithRole('super_admin', 'TI');
        $year = TahunAjaran::create(['kode' => '2027', 'nama' => '2027/2028', 'tanggal_mulai' => '2027-08-01', 'tanggal_selesai' => '2028-07-31', 'status' => 'aktif']);
        $this->actingAs($admin)->postJson('/api/admin/semester', [
            'tahun_ajaran_id' => $year->id, 'nama' => 'Ganjil',
            'tanggal_mulai' => '2027-08-01', 'tanggal_selesai' => '2028-01-31',
        ])->assertUnprocessable()->assertJsonValidationErrors('kode');

        $response = $this->actingAs($admin)->postJson('/api/admin/semester', [
            'tahun_ajaran_id' => $year->id, 'nama' => 'Ganjil', 'kode' => '2027-G',
            'tanggal_mulai' => '2027-08-01', 'tanggal_selesai' => '2028-01-31', 'status' => 'aktif',
        ])->assertCreated()->assertJsonPath('data.nama', 'Ganjil')->assertJsonPath('data.kode', '2027-G');
        $this->actingAs($admin)->putJson('/api/admin/semester/'.$response->json('data.id'), [
            'nama' => 'Genap', 'kode' => '2027-E', 'tanggal_mulai' => '2028-02-01', 'tanggal_selesai' => '2028-07-31',
        ])->assertOk()->assertJsonPath('data.nama', 'Genap')->assertJsonPath('data.kode', '2027-E');
    }

    public function test_older_re_enrollment_cannot_replace_a_newer_request(): void
    {
        [$student] = $this->userWithRole('mahasiswa', 'TI', ['enrollment_status' => 'approved']);
        [$kaprodi] = $this->userWithRole('kaprodi', 'TI');
        FaceEmbedding::create(['user_id' => $student->id, 'embedding' => array_fill(0, 192, 0.1), 'version' => 1, 'status' => 'approved']);
        Storage::disk('face')->put('re-enrollment/old-request.jpg', 'old');
        Storage::disk('face')->put('re-enrollment/new-request.jpg', 'new');
        $old = ReEnrollmentRequest::create(['user_id' => $student->id, 'alasan' => 'perubahan_lain', 'foto_baru' => 're-enrollment/old-request.jpg', 'new_embedding' => array_fill(0, 192, 0.2), 'status' => 'pending']);
        ReEnrollmentRequest::create(['user_id' => $student->id, 'alasan' => 'perubahan_lain', 'foto_baru' => 're-enrollment/new-request.jpg', 'new_embedding' => array_fill(0, 192, 0.3), 'status' => 'pending']);

        $this->actingAs($kaprodi)->putJson("/api/kaprodi/re-enrollments/{$old->id}/approve")->assertStatus(409);
        $this->assertSame(1, FaceEmbedding::where('user_id', $student->id)->where('status', 'approved')->sole()->version);
    }

    public function test_semester_partial_update_cannot_invert_date_range(): void
    {
        [$admin] = $this->userWithRole('super_admin', 'TI');
        $semester = $this->semester();

        $this->actingAs($admin)->putJson("/api/admin/semester/{$semester->id}", [
            'tanggal_mulai' => '2027-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('tanggal_mulai');
    }

    private function userWithRole(string $role, string $prodiCode, array $attributes = []): array
    {
        $prodi = Prodi::where('kode', $prodiCode)->firstOrFail();
        $user = User::factory()->create($attributes + ['prodi_id' => $prodi->id, 'status' => 'aktif', 'enrollment_status' => 'belum']);
        $user->roles()->attach(Role::where('name', $role)->value('id'));

        return [$user, $prodi];
    }

    private function semester(): Semester
    {
        $year = TahunAjaran::firstOrCreate(['kode' => '2026-T'], ['nama' => '2026/2027', 'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif']);

        return Semester::firstOrCreate(['kode' => '2026-T-G'], ['tahun_ajaran_id' => $year->id, 'nama' => 'Ganjil', 'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif']);
    }
}
