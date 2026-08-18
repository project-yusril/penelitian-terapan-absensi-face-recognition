<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Geofence;
use App\Models\Jadwal;
use App\Models\LeaveRequest;
use App\Models\MataKuliah;
use App\Models\Prodi;
use App\Models\ProdiSetting;
use App\Models\Role;
use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\SeedsEssentialData;
use Tests\TestCase;

/**
 * Shortcut izin/sakit sehari untuk banyak MK. Model data tetap per-MK; yang diuji
 * di sini adalah fan-out `store` beserta batas-batasnya.
 */
class LeaveRequestMultiCourseTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private const SENIN = '2026-07-13';

    private User $student;

    private User $kaprodi;

    private Semester $semester;

    private Prodi $prodi;

    private Geofence $geofence;

    /** MK dengan jadwal Senin. */
    private MataKuliah $seninPagi;

    /** MK lain dengan jadwal Senin. */
    private MataKuliah $seninSiang;

    /** MK enrolled tetapi hanya punya jadwal Rabu. */
    private MataKuliah $rabu;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::SENIN.' 07:00:00');
        $this->seedEssentialData();
        $this->prodi = Prodi::where('kode', 'TI')->firstOrFail();
        $this->student = $this->user('mahasiswa');
        $this->kaprodi = $this->user('kaprodi');
        $year = TahunAjaran::create([
            'kode' => '2026-LV', 'nama' => '2026/2027', 'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $this->semester = Semester::create([
            'tahun_ajaran_id' => $year->id, 'kode' => '2026-LV-G', 'nama' => 'Ganjil',
            'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $this->geofence = Geofence::create([
            'nama' => 'Lab', 'latitude' => -6.2, 'longitude' => 106.8, 'radius' => 50,
            'prodi_id' => $this->prodi->id, 'status' => 'aktif',
        ]);
        ProdiSetting::create(['prodi_id' => $this->prodi->id, 'toleransi_masuk_menit' => 15]);

        $this->seninPagi = $this->course('LV101', 'Algoritma');
        $this->seninSiang = $this->course('LV102', 'Basis Data');
        $this->rabu = $this->course('LV103', 'Statistika');
        $this->schedule($this->seninPagi, 'Senin', '08:00', '10:00');
        $this->schedule($this->seninSiang, 'Senin', '13:00', '15:00');
        $this->schedule($this->rabu, 'Rabu', '08:00', '10:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_multi_course_submission_creates_one_pending_leave_per_scheduled_course(): void
    {
        $response = $this->actingAs($this->student)
            ->postJson('/api/mahasiswa/leave-requests', $this->payload(['all_mata_kuliah' => true]))
            ->assertCreated()
            ->assertJsonPath('data.created_count', 2);

        $created = collect($response->json('data.leave_requests'));
        $this->assertEqualsCanonicalizing(
            [$this->seninPagi->id, $this->seninSiang->id],
            $created->pluck('mata_kuliah_id')->all(),
        );
        $this->assertSame(['pending', 'pending'], $created->pluck('status')->all());
        foreach ([$this->seninPagi, $this->seninSiang] as $course) {
            $this->assertDatabaseHas('leave_requests', [
                'user_id' => $this->student->id, 'mata_kuliah_id' => $course->id,
                'jenis' => 'sakit', 'tanggal_mulai' => self::SENIN, 'status' => 'pending',
            ]);
        }
        $this->assertSame(2, LeaveRequest::where('user_id', $this->student->id)->count());
    }

    public function test_course_without_schedule_in_range_is_skipped(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/mahasiswa/leave-requests', $this->payload(['all_mata_kuliah' => true]))
            ->assertCreated()
            ->assertJsonPath('data.created_count', 2)
            ->assertJsonPath('data.skipped', [[
                'mata_kuliah_id' => $this->rabu->id,
                'nama' => $this->rabu->nama,
                'alasan' => 'tanpa_jadwal',
                'pesan' => 'Tidak ada jadwal aktif pada rentang tanggal',
            ]]);

        $this->assertDatabaseMissing('leave_requests', [
            'user_id' => $this->student->id, 'mata_kuliah_id' => $this->rabu->id,
        ]);
    }

    public function test_all_courses_ignores_historical_or_inactive_enrollments(): void
    {
        $oldYear = TahunAjaran::create([
            'kode' => '2025-LV', 'nama' => '2025/2026', 'tanggal_mulai' => '2025-01-01',
            'tanggal_selesai' => '2025-12-31', 'status' => 'nonaktif',
        ]);
        $oldSemester = Semester::create([
            'tahun_ajaran_id' => $oldYear->id, 'kode' => '2025-LV-G', 'nama' => 'Ganjil',
            'tanggal_mulai' => '2025-01-01', 'tanggal_selesai' => '2025-12-31', 'status' => 'nonaktif',
        ]);
        $historical = MataKuliah::create([
            'kode_mk' => 'LV090', 'nama' => 'Mata Kuliah Lama', 'sks' => 2,
            'semester_id' => $oldSemester->id, 'prodi_id' => $this->prodi->id,
            'dosen_id' => $this->kaprodi->id, 'status' => 'aktif',
        ]);
        $historical->mahasiswas()->attach($this->student->id);
        $this->schedule($historical, 'Senin', '16:00', '18:00');
        $inactive = $this->course('LV104', 'Mata Kuliah Nonaktif');
        $inactive->update(['status' => 'nonaktif']);
        $this->schedule($inactive, 'Senin', '16:00', '18:00');

        $response = $this->actingAs($this->student)
            ->postJson('/api/mahasiswa/leave-requests', $this->payload(['all_mata_kuliah' => true]))
            ->assertCreated()
            ->assertJsonPath('data.created_count', 2);

        $createdIds = collect($response->json('data.leave_requests'))->pluck('mata_kuliah_id');
        $this->assertEqualsCanonicalizing([$this->seninPagi->id, $this->seninSiang->id], $createdIds->all());
        $this->assertDatabaseMissing('leave_requests', ['mata_kuliah_id' => $historical->id]);
        $this->assertDatabaseMissing('leave_requests', ['mata_kuliah_id' => $inactive->id]);
    }

    public function test_explicit_multi_course_rejects_active_semester_outside_requested_period(): void
    {
        $this->semester->update([
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-06-30',
        ]);

        $this->actingAs($this->student)
            ->postJson('/api/mahasiswa/leave-requests', $this->payload([
                'mata_kuliah_ids' => [$this->seninPagi->id],
            ]))
            ->assertUnprocessable();

        $this->assertDatabaseMissing('leave_requests', [
            'user_id' => $this->student->id,
            'mata_kuliah_id' => $this->seninPagi->id,
        ]);
    }

    public function test_duplicate_course_is_skipped_while_the_rest_is_still_created(): void
    {
        $existing = LeaveRequest::create([
            'user_id' => $this->student->id, 'mata_kuliah_id' => $this->seninPagi->id, 'jenis' => 'izin',
            'tanggal_mulai' => self::SENIN, 'tanggal_selesai' => self::SENIN, 'status' => 'pending',
        ]);

        $this->actingAs($this->student)
            ->postJson('/api/mahasiswa/leave-requests', $this->payload(['all_mata_kuliah' => true]))
            ->assertCreated()
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.leave_requests.0.mata_kuliah_id', $this->seninSiang->id)
            ->assertJsonFragment(['mata_kuliah_id' => $this->seninPagi->id, 'alasan' => 'duplikat']);

        // Satu baris lama + satu baris baru: tidak ada duplikat dan tidak ada partial commit.
        $this->assertSame(2, LeaveRequest::where('user_id', $this->student->id)->count());
        $this->assertSame(1, LeaveRequest::where('user_id', $this->student->id)
            ->where('mata_kuliah_id', $this->seninPagi->id)->count());
        $this->assertSame('izin', $existing->fresh()->jenis);
    }

    public function test_all_courses_skipped_creates_nothing_and_returns_422(): void
    {
        foreach ([$this->seninPagi, $this->seninSiang] as $course) {
            LeaveRequest::create([
                'user_id' => $this->student->id, 'mata_kuliah_id' => $course->id, 'jenis' => 'izin',
                'tanggal_mulai' => self::SENIN, 'tanggal_selesai' => self::SENIN, 'status' => 'approved',
            ]);
        }

        $this->actingAs($this->student)
            ->postJson('/api/mahasiswa/leave-requests', $this->payload(['all_mata_kuliah' => true]))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonCount(3, 'errors.skipped');

        $this->assertSame(2, LeaveRequest::where('user_id', $this->student->id)->count());
    }

    public function test_overlapping_multi_day_leave_for_same_course_is_treated_as_duplicate(): void
    {
        // Izin lama 3 hari (Senin–Rabu) untuk seninPagi.
        LeaveRequest::create([
            'user_id' => $this->student->id, 'mata_kuliah_id' => $this->seninPagi->id, 'jenis' => 'izin',
            'tanggal_mulai' => self::SENIN, 'tanggal_selesai' => '2026-07-15', 'status' => 'approved',
        ]);

        // Pengajuan baru mulai Selasa (start berbeda, tetap beririsan) harus ditolak sebagai duplikat.
        $this->actingAs($this->student)
            ->postJson('/api/mahasiswa/leave-requests', $this->payload([
                'mata_kuliah_id' => $this->seninPagi->id,
                'tanggal_mulai' => '2026-07-14',
                'tanggal_selesai' => '2026-07-14',
            ]))
            ->assertStatus(422);

        $this->assertSame(1, LeaveRequest::where('user_id', $this->student->id)
            ->where('mata_kuliah_id', $this->seninPagi->id)->count());
    }

    public function test_approving_one_multi_leave_only_touches_its_own_course(): void
    {
        $alphaLain = Attendance::create([
            'user_id' => $this->student->id,
            'jadwal_id' => Jadwal::where('mata_kuliah_id', $this->seninSiang->id)->value('id'),
            'mata_kuliah_id' => $this->seninSiang->id, 'tanggal' => self::SENIN,
            'status' => 'alpha', 'alpha_menit' => 120,
        ]);
        $this->actingAs($this->student)
            ->postJson('/api/mahasiswa/leave-requests', $this->payload(['all_mata_kuliah' => true]))
            ->assertCreated();
        $leave = LeaveRequest::where('user_id', $this->student->id)
            ->where('mata_kuliah_id', $this->seninPagi->id)->firstOrFail();

        $this->actingAs($this->kaprodi)
            ->putJson("/api/kaprodi/leave-requests/{$leave->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('attendances', [
            'user_id' => $this->student->id, 'mata_kuliah_id' => $this->seninPagi->id,
            'tanggal' => self::SENIN, 'status' => 'sakit', 'alpha_menit' => 0,
        ]);
        $this->assertSame('alpha', $alphaLain->fresh()->status);
        $this->assertSame(120, $alphaLain->fresh()->alpha_menit);
        $this->assertSame('pending', LeaveRequest::where('mata_kuliah_id', $this->seninSiang->id)->value('status'));
    }

    public function test_mark_absent_skips_alpha_for_courses_with_approved_multi_leave(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/mahasiswa/leave-requests', $this->payload(['all_mata_kuliah' => true]))
            ->assertCreated();
        foreach (LeaveRequest::where('user_id', $this->student->id)->pluck('id') as $id) {
            $this->actingAs($this->kaprodi)->putJson("/api/kaprodi/leave-requests/{$id}/approve")->assertOk();
        }

        Carbon::setTestNow(self::SENIN.' 23:00:00');
        $this->artisan('attendance:mark-absent')->assertSuccessful();

        $this->assertSame(0, Attendance::where('user_id', $this->student->id)->where('status', 'alpha')->count());
        $this->assertSame(2, Attendance::where('user_id', $this->student->id)->where('status', 'sakit')
            ->where('alpha_menit', 0)->count());
    }

    public function test_explicit_course_ids_are_honoured_and_must_be_enrolled(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/mahasiswa/leave-requests', $this->payload([
                'mata_kuliah_ids' => [$this->seninPagi->id],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.leave_requests.0.mata_kuliah_id', $this->seninPagi->id);

        $asing = $this->course('LV999', 'MK Tidak Diambil', enroll: false);
        $this->actingAs($this->student)
            ->postJson('/api/mahasiswa/leave-requests', $this->payload([
                'mata_kuliah_ids' => [$this->seninSiang->id, $asing->id],
            ]))
            ->assertForbidden();

        $this->assertSame(1, LeaveRequest::where('user_id', $this->student->id)->count());
    }

    public function test_single_course_submission_keeps_the_legacy_contract(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/mahasiswa/leave-requests', $this->payload([
                'mata_kuliah_id' => $this->rabu->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.mata_kuliah_id', $this->rabu->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.mata_kuliah.id', $this->rabu->id);

        // Jalur lama tidak menyaring jadwal dan tetap menolak duplikat dengan 422.
        $this->actingAs($this->student)
            ->postJson('/api/mahasiswa/leave-requests', $this->payload([
                'mata_kuliah_id' => $this->rabu->id,
            ]))
            ->assertStatus(422);

        $this->assertSame(1, LeaveRequest::where('user_id', $this->student->id)->count());
    }

    public function test_missing_course_selection_is_a_validation_error(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/mahasiswa/leave-requests', $this->payload([]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('mata_kuliah_id');
    }

    public function test_uploaded_letter_is_stored_once_and_shared_by_every_created_row(): void
    {
        Storage::fake('documents');

        $this->actingAs($this->student)
            ->post('/api/mahasiswa/leave-requests', $this->payload([
                'all_mata_kuliah' => '1',
                'file_surat' => UploadedFile::fake()->create('surat.pdf', 64, 'application/pdf'),
            ]))
            ->assertCreated();

        $paths = LeaveRequest::where('user_id', $this->student->id)->pluck('file_surat');
        $this->assertCount(2, $paths);
        $this->assertCount(1, $paths->unique());
        Storage::disk('documents')->assertExists($paths->first());
        $this->assertCount(1, Storage::disk('documents')->files('leave-requests'));
    }

    public function test_failed_transaction_rolls_back_every_row_and_removes_the_uploaded_letter(): void
    {
        Storage::fake('documents');
        // Gagalkan insert kedua supaya baris pertama pun harus ikut ter-rollback.
        $inserts = 0;
        DB::listen(function ($query) use (&$inserts): void {
            if (! str_contains(strtolower($query->sql), 'insert into') || ! str_contains($query->sql, 'leave_requests')) {
                return;
            }
            if (++$inserts === 2) {
                throw new \RuntimeException('insert kedua sengaja digagalkan');
            }
        });

        $this->actingAs($this->student)
            ->post('/api/mahasiswa/leave-requests', $this->payload([
                'all_mata_kuliah' => '1',
                'file_surat' => UploadedFile::fake()->create('surat.pdf', 64, 'application/pdf'),
            ]))
            ->assertStatus(500);

        $this->assertSame(2, $inserts);
        $this->assertSame(0, LeaveRequest::where('user_id', $this->student->id)->count());
        $this->assertCount(0, Storage::disk('documents')->files('leave-requests'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides): array
    {
        return array_merge([
            'jenis' => 'sakit',
            'tanggal_mulai' => self::SENIN,
            'tanggal_selesai' => self::SENIN,
            'keterangan' => 'Demam, ada surat dokter',
        ], $overrides);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['prodi_id' => Prodi::where('kode', 'TI')->value('id'), 'status' => 'aktif']);
        $user->roles()->attach(Role::where('name', $role)->value('id'));

        return $user;
    }

    private function course(string $kode, string $nama, bool $enroll = true): MataKuliah
    {
        $course = MataKuliah::create([
            'kode_mk' => $kode, 'nama' => $nama, 'sks' => 2, 'semester_id' => $this->semester->id,
            'prodi_id' => $this->prodi->id, 'dosen_id' => $this->kaprodi->id, 'status' => 'aktif',
        ]);
        if ($enroll) {
            $course->mahasiswas()->attach($this->student->id);
        }

        return $course;
    }

    private function schedule(MataKuliah $course, string $hari, string $mulai, string $selesai): Jadwal
    {
        return Jadwal::create([
            'mata_kuliah_id' => $course->id, 'geofence_id' => $this->geofence->id,
            'hari' => $hari, 'jam_mulai' => $mulai, 'jam_selesai' => $selesai,
            'durasi_menit' => 120, 'status' => 'aktif',
        ]);
    }
}
