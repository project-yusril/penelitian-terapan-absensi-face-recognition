<?php

namespace Tests\Feature;

use App\Models\Prodi;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SeedsEssentialData;
use Tests\TestCase;

/**
 * Regression M-18: pagination di-clamp dan sorting memakai allowlist.
 */
class ListQueryHardeningTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private function admin(): User
    {
        $prodi = Prodi::where('kode', 'TI')->firstOrFail();
        $admin = User::factory()->create([
            'nim' => null, 'prodi_id' => $prodi->id, 'status' => 'aktif',
            'enrollment_status' => 'not_required',
        ]);
        $admin->roles()->attach(Role::where('name', 'super_admin')->value('id'));

        return $admin;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();
    }

    public function test_arbitrary_sort_column_is_ignored_and_does_not_error(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/users?sort_by=password&sort_dir=upside_down')
            ->assertOk();
    }

    public function test_array_and_non_numeric_query_values_do_not_error(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/users?per_page[]=5&sort_by[]=nama&sort_dir[]=asc')
            ->assertOk();

        $this->actingAs($this->admin())
            ->getJson('/api/admin/users?per_page=abc')
            ->assertOk();
    }

    public function test_non_positive_per_page_falls_back_to_default(): void
    {
        $response = $this->actingAs($this->admin())
            ->getJson('/api/admin/users?per_page=0')
            ->assertOk();

        $this->assertSame(15, (int) $response->json('meta.per_page'));
    }

    public function test_excessive_per_page_is_clamped(): void
    {
        $prodi = Prodi::where('kode', 'TI')->firstOrFail();
        User::factory()->count(5)->create([
            'prodi_id' => $prodi->id, 'status' => 'aktif',
        ])->each(fn ($u) => $u->roles()->attach(Role::where('name', 'mahasiswa')->value('id')));

        $response = $this->actingAs($this->admin())
            ->getJson('/api/admin/users?per_page=1000000')
            ->assertOk();

        $perPage = $response->json('meta.per_page') ?? $response->json('per_page');
        $this->assertNotNull($perPage);
        $this->assertLessThanOrEqual(100, (int) $perPage);
    }
}
