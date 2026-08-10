<?php

namespace Tests\Feature;

use App\Models\Prodi;
use App\Models\Role;
use App\Models\Semester;
use App\Models\SpRecord;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class SpWorkflowSecurityTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();
    }

    public function test_only_correct_role_and_prodi_can_advance_sp_and_transition_is_audited(): void
    {
        [$admin, $ti] = $this->actor('admin_prodi', 'TI');
        [$kaprodi] = $this->actor('kaprodi', 'TI');
        [$wrongKaprodi] = $this->actor('kaprodi', 'TE');
        [$kajur] = $this->actor('ketua_jurusan');
        [$student] = $this->actor('mahasiswa', 'TI');
        $sp = $this->sp($student, $admin, 'draft');

        $this->actingAs($wrongKaprodi)->post("/sp/{$sp->id}/sign-kaprodi")->assertForbidden();
        $this->actingAs($kajur)->post("/sp/{$sp->id}/sign-kajur")->assertStatus(409);

        $this->actingAs($admin)->post("/sp/{$sp->id}/send")->assertRedirect();
        $this->assertDatabaseHas('sp_records', ['id' => $sp->id, 'status' => 'menunggu_kaprodi']);
        $this->assertDatabaseHas('audit_trails', ['model_id' => $sp->id, 'action' => 'sp_sent_to_kaprodi']);

        $this->actingAs($kaprodi)->post("/sp/{$sp->id}/sign-kaprodi")->assertRedirect();
        $this->assertDatabaseHas('sp_records', [
            'id' => $sp->id, 'status' => 'menunggu_kajur', 'signed_kaprodi_by' => $kaprodi->id,
        ]);
        $this->assertDatabaseHas('audit_trails', ['model_id' => $sp->id, 'action' => 'sp_signed_by_kaprodi']);

        $this->actingAs($kajur)->post("/sp/{$sp->id}/sign-kajur")->assertRedirect();
        $this->assertDatabaseHas('sp_records', [
            'id' => $sp->id, 'status' => 'final', 'signed_kajur_by' => $kajur->id,
        ]);
        $this->assertDatabaseHas('audit_trails', ['model_id' => $sp->id, 'action' => 'sp_finalized_by_kajur']);
    }

    public function test_invalid_or_repeated_transition_returns_conflict_without_audit(): void
    {
        [$admin] = $this->actor('admin_prodi', 'TI');
        [$kaprodi] = $this->actor('kaprodi', 'TI');
        [$student] = $this->actor('mahasiswa', 'TI');
        $sp = $this->sp($student, $admin, 'draft');

        $this->actingAs($kaprodi)->putJson("/api/kaprodi/sp-records/{$sp->id}/sign")
            ->assertStatus(409);
        $this->assertDatabaseCount('audit_trails', 0);

        $this->actingAs($admin)->postJson("/api/admin/sp-records/{$sp->id}/send-to-kaprodi")
            ->assertOk();
        $this->actingAs($admin)->postJson("/api/admin/sp-records/{$sp->id}/send-to-kaprodi")
            ->assertStatus(409);
        $this->assertDatabaseCount('audit_trails', 1);
    }

    public function test_admin_prodi_cannot_send_cross_prodi_sp(): void
    {
        [$adminTi] = $this->actor('admin_prodi', 'TI');
        [$studentTe] = $this->actor('mahasiswa', 'TE');
        $sp = $this->sp($studentTe, $adminTi, 'draft');

        $this->actingAs($adminTi)->postJson("/api/admin/sp-records/{$sp->id}/send-to-kaprodi")
            ->assertForbidden();
        $this->assertDatabaseHas('sp_records', ['id' => $sp->id, 'status' => 'draft']);
        $this->assertDatabaseCount('audit_trails', 0);
    }

    public function test_admin_jurusan_cannot_execute_sp_transitions(): void
    {
        [$adminJurusan] = $this->actor('admin_jurusan', 'TI');
        [$student] = $this->actor('mahasiswa', 'TI');
        $sp = $this->sp($student, $adminJurusan, 'draft');

        $this->actingAs($adminJurusan)
            ->postJson("/api/admin/sp-records/{$sp->id}/send-to-kaprodi")
            ->assertForbidden();
        $this->actingAs($adminJurusan)
            ->postJson("/api/admin/sp-records/{$sp->id}/cancel", ['reason' => 'Tidak sah'])
            ->assertForbidden();
        $this->assertDatabaseHas('sp_records', ['id' => $sp->id, 'status' => 'draft']);
        $this->assertDatabaseCount('audit_trails', 0);
    }

    private function actor(string $role, ?string $prodiCode = null): array
    {
        $prodi = $prodiCode ? Prodi::where('kode', $prodiCode)->firstOrFail() : null;
        $user = User::factory()->create([
            'prodi_id' => $prodi?->id, 'status' => 'aktif', 'enrollment_status' => 'belum',
        ]);
        $user->roles()->attach(Role::where('name', $role)->value('id'));

        return [$user, $prodi];
    }

    private function sp(User $student, User $generator, string $status): SpRecord
    {
        $tahun = TahunAjaran::firstOrCreate(['kode' => '2026/2027'], [
            'nama' => '2026/2027', 'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => '2027-06-30', 'status' => 'aktif',
        ]);
        $semester = Semester::firstOrCreate(['kode' => '2026/2027-1'], [
            'tahun_ajaran_id' => $tahun->id, 'nama' => 'Ganjil',
            'tanggal_mulai' => '2026-07-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);

        return SpRecord::create([
            'user_id' => $student->id, 'semester_id' => $semester->id, 'sp_level' => 'sp1',
            'total_alpha_jam' => 16, 'status' => $status,
            'generated_by' => $generator->id, 'generated_at' => now(),
        ]);
    }
}
