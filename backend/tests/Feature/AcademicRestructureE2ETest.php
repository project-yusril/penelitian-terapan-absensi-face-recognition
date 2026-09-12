<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Geofence;
use App\Models\Jadwal;
use App\Models\Kelas;
use App\Models\MahasiswaKelas;
use App\Models\MataKuliah;
use App\Models\Prodi;
use App\Models\ProdiSetting;
use App\Models\Role;
use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\SeedsEssentialData;
use Tests\TestCase;

/**
 * PHASE 6 — End-to-end skenario inti RENCANA 2:
 * 6.2  rantai create tahun ajaran → semester → kelas → mahasiswa → matkul → jadwal
 * 6.3  mutasi kelas mahasiswa (Calvin 4B → 5E) tercatat di pivot & jadwal baru benar
 * 6.4  absensi check-in/out per jadwal tetap jalan & laporan per kelas benar
 */
class AcademicRestructureE2ETest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private Prodi $prodi;

    private TahunAjaran $tahunAjaran;

    private Semester $semester;

    private Geofence $geofence;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-20 09:00:00');
        $this->seedEssentialData();
        $this->prodi = Prodi::where('kode', 'TI')->firstOrFail();
    }

    public function test_e2e_full_chain_create_master_until_jadwal(): void
    {
        // 6.2: tahun ajaran → semester → kelas → mahasiswa → matkul → jadwal (dosen mengajar).
        $this->tahunAjaran = TahunAjaran::create([
            'kode' => '2026/2027', 'nama' => '2026/2027',
            'tanggal_mulai' => '2026-07-01', 'tanggal_selesai' => '2027-06-30', 'status' => 'aktif',
        ]);
        $this->assertDatabaseHas('tahun_ajarans', ['kode' => '2026/2027', 'status' => 'aktif']);

        $this->semester = Semester::create([
            'tahun_ajaran_id' => $this->tahunAjaran->id, 'nama' => 'Ganjil', 'kode' => '2026/2027-1',
            'tanggal_mulai' => '2026-07-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $this->assertDatabaseHas('semesters', ['kode' => '2026/2027-1', 'status' => 'aktif']);

        // Kelas 5E (target mutasi Calvin).
        $kelas5E = Kelas::create([
            'prodi_id' => $this->prodi->id, 'semester_id' => $this->semester->id,
            'tingkat' => '5', 'nama' => 'E', 'status' => 'aktif',
        ]);
        $this->assertSame('5E', $kelas5E->tingkat.$kelas5E->nama);

        // MK master + jadwal kelas 5E dengan dosen pengampu.
        $dosen = $this->user('dosen');
        $mk = MataKuliah::create([
            'kode_mk' => 'TI-501', 'nama' => 'Proyek Akhir', 'sks' => 4,
            'semester_id' => $this->semester->id, 'prodi_id' => $this->prodi->id, 'status' => 'aktif',
        ]);
        $this->geofence = Geofence::create([
            'nama' => 'Lab 5E', 'latitude' => -0.0263, 'longitude' => 109.3425,
            'radius' => 100, 'prodi_id' => $this->prodi->id, 'status' => 'aktif',
        ]);
        $jadwal = Jadwal::create([
            'mata_kuliah_id' => $mk->id, 'kelas_id' => $kelas5E->id, 'dosen_id' => $dosen->id,
            'geofence_id' => $this->geofence->id, 'hari' => 'Senin',
            'jam_mulai' => '08:00', 'jam_selesai' => '10:00', 'durasi_menit' => 120, 'status' => 'aktif',
        ]);

        $this->assertDatabaseHas('jadwals', [
            'mata_kuliah_id' => $mk->id, 'kelas_id' => $kelas5E->id, 'dosen_id' => $dosen->id,
        ]);
        $this->assertSame($kelas5E->id, $jadwal->kelas_id);
        $this->assertSame($dosen->id, $jadwal->dosen_id);
        $this->assertSame(120, $jadwal->durasi_menit);
    }

    public function test_mutasi_kelas_calvin_4b_ke_5e_tercatat_dan_jadwal_baru_benar(): void
    {
        // 6.3: setup semester lama (aktif) & kelas 4B, lalu semester baru & 5E.
        $oldTahun = TahunAjaran::create([
            'kode' => '2025/2026', 'nama' => '2025/2026',
            'tanggal_mulai' => '2025-07-01', 'tanggal_selesai' => '2026-06-30', 'status' => 'nonaktif',
        ]);
        $oldSemester = Semester::create([
            'tahun_ajaran_id' => $oldTahun->id, 'nama' => 'Genap', 'kode' => '2025/2026-2',
            'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-06-30', 'status' => 'nonaktif',
        ]);
        $kelas4B = Kelas::create([
            'prodi_id' => $this->prodi->id, 'semester_id' => $oldSemester->id,
            'tingkat' => '4', 'nama' => 'B', 'status' => 'aktif',
        ]);

        // Semester baru aktif + kelas 5E.
        $newTahun = TahunAjaran::create([
            'kode' => '2026/2027', 'nama' => '2026/2027',
            'tanggal_mulai' => '2026-07-01', 'tanggal_selesai' => '2027-06-30', 'status' => 'aktif',
        ]);
        $newSemester = Semester::create([
            'tahun_ajaran_id' => $newTahun->id, 'nama' => 'Ganjil', 'kode' => '2026/2027-1',
            'tanggal_mulai' => '2026-07-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $kelas5E = Kelas::create([
            'prodi_id' => $this->prodi->id, 'semester_id' => $newSemester->id,
            'tingkat' => '5', 'nama' => 'E', 'status' => 'aktif',
        ]);

        $dosen = $this->user('dosen');
        $mk = MataKuliah::create([
            'kode_mk' => 'TI-501', 'nama' => 'Proyek Akhir', 'sks' => 4,
            'semester_id' => $newSemester->id, 'prodi_id' => $this->prodi->id, 'status' => 'aktif',
        ]);
        $this->geofence = Geofence::create([
            'nama' => 'Lab 5E', 'latitude' => -0.0263, 'longitude' => 109.3425,
            'radius' => 100, 'prodi_id' => $this->prodi->id, 'status' => 'aktif',
        ]);
        $jadwal5E = Jadwal::create([
            'mata_kuliah_id' => $mk->id, 'kelas_id' => $kelas5E->id, 'dosen_id' => $dosen->id,
            'geofence_id' => $this->geofence->id, 'hari' => 'Senin',
            'jam_mulai' => '08:00', 'jam_selesai' => '10:00', 'durasi_menit' => 120, 'status' => 'aktif',
        ]);

        // Calvin: mahasiswa 4B di semester lama, kemudian dipindah ke 5E.
        $calvin = $this->user('mahasiswa', ['nim' => '2024001028', 'kelas' => '4B', 'semester' => 4]);
        MahasiswaKelas::create(['user_id' => $calvin->id, 'kelas_id' => $kelas4B->id, 'semester_id' => $oldSemester->id]);

        // Mutasi: ubah snapshot kelas → observer menulis pivot semester aktif (5E) & snapshot semester 5.
        $calvin->update(['kelas' => '5E']);

        $this->assertDatabaseHas('mahasiswa_kelas', [
            'user_id' => $calvin->id, 'kelas_id' => $kelas5E->id, 'semester_id' => $newSemester->id,
        ]);
        // Riwayat semester lama tetap ada.
        $this->assertDatabaseHas('mahasiswa_kelas', [
            'user_id' => $calvin->id, 'kelas_id' => $kelas4B->id, 'semester_id' => $oldSemester->id,
        ]);
        $this->assertSame(5, (int) $calvin->fresh()->semester);

        // API jadwal mahasiswa (KRS) sekarang melihat jadwal kelas 5E, bukan 4B.
        $token = $this->postJson('/api/auth/login', [
            'login' => $calvin->nim, 'password' => '12345678',
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/mahasiswa/jadwal')
            ->assertOk()
            ->assertJsonPath('data.Senin.0.mata_kuliah.kode_mk', 'TI-501')
            ->assertJsonPath('data.Senin.0.kelas.tingkat', '5')
            ->assertJsonPath('data.Senin.0.kelas.nama', 'E');
    }

    public function test_absensi_checkin_checkout_dan_laporan_per_kelas(): void
    {
        // 6.4: setup lengkap (semester aktif, kelas 4A, jadwal hari ini, mahasiswa approved).
        Carbon::setTestNow('2026-08-20 08:05:00'); // dalam toleransi masuk 15 menit (jadwal 08:00)
        $tahun = TahunAjaran::create([
            'kode' => '2026-TA', 'nama' => '2026/2027', 'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $this->semester = Semester::create([
            'tahun_ajaran_id' => $tahun->id, 'nama' => 'Ganjil', 'kode' => '2026-TA-1',
            'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $kelas = Kelas::create([
            'prodi_id' => $this->prodi->id, 'semester_id' => $this->semester->id,
            'tingkat' => '4', 'nama' => 'A', 'status' => 'aktif',
        ]);
        $this->geofence = Geofence::create([
            'nama' => 'Lab A', 'latitude' => -0.0263, 'longitude' => 109.3425,
            'radius' => 100, 'prodi_id' => $this->prodi->id, 'status' => 'aktif',
        ]);
        ProdiSetting::create(['prodi_id' => $this->prodi->id, 'toleransi_masuk_menit' => 15]);

        $mhs = $this->user('mahasiswa', ['nim' => '2024001001', 'enrollment_status' => 'approved']);
        // Middleware enrollment.approved mensyaratkan embedding wajah approved.
        \App\Models\FaceEmbedding::create([
            'user_id' => $mhs->id,
            'embedding' => array_fill(0, 192, 0.01),
            'version' => 1,
            'status' => 'approved',
        ]);
        $dosen = $this->user('dosen');
        $mk = MataKuliah::create([
            'kode_mk' => 'TI-401', 'nama' => 'Pemrograman Mobile', 'sks' => 3,
            'semester_id' => $this->semester->id, 'prodi_id' => $this->prodi->id, 'status' => 'aktif',
        ]);
        MahasiswaKelas::create(['user_id' => $mhs->id, 'kelas_id' => $kelas->id, 'semester_id' => $this->semester->id]);

        // Jadwal hari ini (Selasa, 2026-08-20? — gunakan hari nyata "today").
        $hariIni = now()->locale('id')->isoFormat('dddd');
        $jadwal = Jadwal::create([
            'mata_kuliah_id' => $mk->id, 'kelas_id' => $kelas->id, 'dosen_id' => $dosen->id,
            'geofence_id' => $this->geofence->id, 'hari' => $hariIni,
            'jam_mulai' => '08:00', 'jam_selesai' => '10:00', 'durasi_menit' => 120, 'status' => 'aktif',
        ]);

        $token = $this->postJson('/api/auth/login', [
            'login' => $mhs->nim, 'password' => '12345678',
        ])->json('data.token');

        // Check-in: perlu permit + evidence biometric.
        $uuid = fake()->uuid();
        $permitResp = $this->withToken($token)->postJson('/api/mahasiswa/attendance/permits', [
            'jadwal_id' => $jadwal->id, 'action' => 'check_in', 'client_uuid' => $uuid,
        ]);
        if ($permitResp->status() !== 201) {
            throw new \Exception('PERMIT_FAIL '.$permitResp->status().' '.$permitResp->getContent());
        }
        $permit = $permitResp->json('data');

        $checkin = $this->withToken($token)->postJson('/api/mahasiswa/attendance/check-in', [
            'jadwal_id' => $jadwal->id, 'client_uuid' => $uuid,
            'permit_token' => $permit['permit_token'], 'liveness_challenge' => $permit['liveness_challenge'],
            'latitude' => -0.0263, 'longitude' => 109.3425, 'face_distance' => 0.1,
            'mock_location_detected' => false, 'liveness_passed' => true,
            'gps_accuracy' => 5, 'location_age_ms' => 0,
        ]);
        if ($checkin->status() !== 201) {
            throw new \Exception('CHECKIN_FAIL '.$checkin->status().' '.$checkin->getContent());
        }
        $checkin->assertCreated();

        $attendanceId = $checkin->json('data.attendance.id');
        $this->assertDatabaseHas('attendances', [
            'user_id' => $mhs->id, 'jadwal_id' => $jadwal->id, 'status' => 'hadir',
        ]);

        // Check-out.
        $uuidOut = fake()->uuid();
        $permitOut = $this->withToken($token)->postJson('/api/mahasiswa/attendance/permits', [
            'jadwal_id' => $jadwal->id, 'attendance_id' => $attendanceId,
            'action' => 'check_out', 'client_uuid' => $uuidOut,
        ])->assertCreated()->json('data');

        $this->withToken($token)->postJson('/api/mahasiswa/attendance/check-out', [
            'attendance_id' => $attendanceId, 'jadwal_id' => $jadwal->id, 'client_uuid' => $uuidOut,
            'permit_token' => $permitOut['permit_token'], 'liveness_challenge' => $permitOut['liveness_challenge'],
            'latitude' => -0.0263, 'longitude' => 109.3425, 'face_distance' => 0.1,
            'mock_location_detected' => false, 'liveness_passed' => true,
            'gps_accuracy' => 5, 'location_age_ms' => 0,
        ])->assertOk();

        $this->assertNotNull(Attendance::find($attendanceId)->checkout_time);

        // Laporan per kelas (admin) — mahasiswa 4A muncul dengan 1 kehadiran.
        $admin = $this->user('super_admin');
        $reportResp = $this->actingAs($admin)->getJson('/api/admin/reports/by-kelas?kelas=4A');
        if ($reportResp->status() !== 200 || count($reportResp->json('data.data') ?? []) !== 1) {
            throw new \Exception('REPORT_FAIL '.$reportResp->status().' '.$reportResp->getContent());
        }
        $reportResp->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.mahasiswa.nim', '2024001001')
            ->assertJsonPath('data.data.0.hadir', 1);
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'prodi_id' => $this->prodi->id, 'status' => 'aktif',
            'enrollment_status' => 'belum',
            'password' => \Illuminate\Support\Facades\Hash::make('12345678'),
        ], $attributes));
        $user->roles()->attach(Role::where('name', $role)->value('id'));

        return $user;
    }
}
