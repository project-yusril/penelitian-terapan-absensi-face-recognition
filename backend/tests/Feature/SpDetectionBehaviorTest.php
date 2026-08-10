<?php

namespace Tests\Feature;

use App\Enums\SpLevel;
use App\Models\AlphaAccumulation;
use App\Models\Attendance;
use App\Models\Geofence;
use App\Models\Jadwal;
use App\Models\MataKuliah;
use App\Models\Notification;
use App\Models\Prodi;
use App\Models\Role;
use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\SpDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class SpDetectionBehaviorTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private User $student;

    private Attendance $attendance;

    /** @var array<string, User> */
    private array $recipients;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();

        $ti = Prodi::where('kode', 'TI')->firstOrFail();
        $te = Prodi::where('kode', 'TE')->firstOrFail();
        $this->student = $this->user('mahasiswa', $ti);
        $this->student->update(['nim' => 'M04001']);

        $this->recipients = [
            'kaprodi_ti' => $this->user('kaprodi', $ti),
            'kaprodi_te' => $this->user('kaprodi', $te),
            'admin_prodi_ti' => $this->user('admin_prodi', $ti),
            'admin_prodi_te' => $this->user('admin_prodi', $te),
            'kajur_ti' => $this->user('ketua_jurusan', $ti),
            'kajur_te' => $this->user('ketua_jurusan', $te),
            'admin_jurusan_ti' => $this->user('admin_jurusan', $ti),
            'admin_jurusan_te' => $this->user('admin_jurusan', $te),
            'dosen' => $this->user('dosen', $ti),
            'parent' => $this->user('orang_tua', $ti),
        ];
        $this->student->parents()->attach($this->recipients['parent']->id);

        $year = TahunAjaran::create([
            'kode' => '2026-M04', 'nama' => '2026/2027', 'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $semester = Semester::create([
            'tahun_ajaran_id' => $year->id, 'kode' => '2026-M04-G', 'nama' => 'Ganjil',
            'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $course = MataKuliah::create([
            'kode_mk' => 'M0401', 'nama' => 'M-04', 'sks' => 2, 'semester_id' => $semester->id,
            'prodi_id' => $ti->id, 'dosen_id' => $this->recipients['dosen']->id, 'status' => 'aktif',
        ]);
        $course->mahasiswas()->attach($this->student->id);
        $geofence = Geofence::create([
            'nama' => 'M-04', 'latitude' => -6.2, 'longitude' => 106.8,
            'radius' => 50, 'prodi_id' => $ti->id, 'status' => 'aktif',
        ]);
        $schedule = Jadwal::create([
            'mata_kuliah_id' => $course->id, 'geofence_id' => $geofence->id,
            'hari' => 'Sabtu', 'jam_mulai' => '08:00', 'jam_selesai' => '10:00',
            'durasi_menit' => 120, 'status' => 'aktif',
        ]);
        $this->attendance = Attendance::create([
            'user_id' => $this->student->id, 'jadwal_id' => $schedule->id, 'mata_kuliah_id' => $course->id,
            'tanggal' => '2026-07-18', 'status' => 'alpha', 'alpha_menit' => 0,
        ]);
    }

    public function test_enum_is_lowercase_backed_and_owns_sp_mappings(): void
    {
        $this->assertSame(['aman', 'sp1', 'sp2', 'sp3', 'do'], array_column(SpLevel::cases(), 'value'));
        $this->assertSame('SP1', SpLevel::Sp1->label());
        $this->assertSame(SpLevel::Sp2, SpLevel::Sp1->next());
        $this->assertSame('notified_sp3', SpLevel::Sp3->notificationFlag());
        $this->assertSame('notified_approaching_do', SpLevel::Do->approachingFlag());
        $this->assertSame('approaching_sp2', SpLevel::Sp2->approachingCode());
        $this->assertFalse(SpLevel::Sp2->isUrgent());
        $this->assertTrue(SpLevel::Sp3->isUrgent());
    }

    #[DataProvider('levelTransitions')]
    public function test_each_level_transition_persists_canonical_value_and_exact_recipient_matrix(
        string $level,
        int $alphaMinutes,
        array $recipientKeys,
        bool $urgent,
    ): void {
        $result = $this->evaluateAt($alphaMinutes);

        $this->assertSame($level, $result['sp_status']);
        $this->assertDatabaseHas('alpha_accumulations', [
            'user_id' => $this->student->id, 'sp_status' => $level, "notified_{$level}" => true,
        ]);

        $notifications = Notification::where('type', 'sp_issued')
            ->get()
            ->filter(fn (Notification $notification) => $notification->data['level'] === $level);
        $expectedIds = [$this->student->id, $this->recipients['parent']->id];
        foreach ($recipientKeys as $key) {
            $expectedIds[] = $this->recipients[$key]->id;
        }

        $this->assertEqualsCanonicalizing($expectedIds, $notifications->pluck('user_id')->all());
        $this->assertCount(count($expectedIds), $notifications);
        $this->assertTrue($notifications->every(fn (Notification $notification) => $notification->data['urgent'] === $urgent));
    }

    public static function levelTransitions(): array
    {
        return [
            'sp1' => ['sp1', 16 * 60, ['kaprodi_ti', 'dosen', 'admin_prodi_ti'], false],
            'sp2' => ['sp2', 32 * 60, ['kaprodi_ti', 'kajur_ti', 'kajur_te', 'admin_prodi_ti'], false],
            'sp3' => ['sp3', 38 * 60, ['kaprodi_ti', 'kajur_ti', 'kajur_te', 'admin_prodi_ti', 'admin_jurusan_ti', 'admin_jurusan_te'], true],
            'do' => ['do', 46 * 60, ['kaprodi_ti', 'kajur_ti', 'kajur_te', 'admin_prodi_ti', 'admin_jurusan_ti', 'admin_jurusan_te'], true],
        ];
    }

    #[DataProvider('approachingLevels')]
    public function test_approaching_notifications_use_lowercase_payload_and_exact_recipient_matrix(
        string $level,
        int $alphaMinutes,
        array $recipientKeys,
        bool $urgent,
    ): void {
        $this->evaluateAt($alphaMinutes);

        $code = "approaching_{$level}";
        $notifications = Notification::where('type', 'sp_warning')
            ->get()
            ->filter(fn (Notification $notification) => $notification->data['level'] === $code);
        $expectedIds = [$this->student->id];
        foreach ($recipientKeys as $key) {
            $expectedIds[] = $this->recipients[$key]->id;
        }

        $this->assertEqualsCanonicalizing($expectedIds, $notifications->pluck('user_id')->all());
        $this->assertCount(count($expectedIds), $notifications);
        $this->assertTrue($notifications->every(fn (Notification $notification) => $notification->data['urgent'] === $urgent));
    }

    public static function approachingLevels(): array
    {
        return [
            'sp1' => ['sp1', 768, [], false],
            'sp2' => ['sp2', 1536, ['kaprodi_ti', 'admin_prodi_ti'], false],
            'sp3' => ['sp3', 1825, ['kaprodi_ti', 'admin_prodi_ti'], false],
            'do' => ['do', 2209, ['kaprodi_ti', 'admin_prodi_ti', 'kajur_ti', 'kajur_te'], true],
        ];
    }

    public function test_repeated_evaluation_is_idempotent_for_notifications(): void
    {
        $first = $this->evaluateAt(38 * 60);
        $notificationCount = Notification::count();
        $second = app(SpDetectionService::class)->evaluate($this->student->id);

        $this->assertGreaterThan(0, $first['notifications_sent']);
        $this->assertSame(0, $second['notifications_sent']);
        $this->assertSame($notificationCount, Notification::count());
    }

    public function test_repeated_evaluation_preserves_one_canonical_accumulation_row(): void
    {
        $this->attendance->update(['alpha_menit' => 38 * 60]);

        app(SpDetectionService::class)->evaluate($this->student->id);
        app(SpDetectionService::class)->evaluate($this->student->id);

        $this->assertSame(1, AlphaAccumulation::where('user_id', $this->student->id)->count());
        $this->assertSame(1, Notification::where('user_id', $this->student->id)
            ->where('type', 'sp_issued')->count());
    }

    private function evaluateAt(int $alphaMinutes): array
    {
        $this->attendance->update(['alpha_menit' => $alphaMinutes]);

        return app(SpDetectionService::class)->evaluate($this->student->id);
    }

    private function user(string $role, Prodi $prodi): User
    {
        $user = User::factory()->create(['prodi_id' => $prodi->id, 'status' => 'aktif']);
        $user->roles()->attach(Role::where('name', $role)->value('id'));

        return $user;
    }
}
