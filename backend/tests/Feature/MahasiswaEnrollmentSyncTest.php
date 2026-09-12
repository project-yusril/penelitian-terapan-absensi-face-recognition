<?php

namespace Tests\Feature;

use App\Models\Geofence;
use App\Models\Jadwal;
use App\Models\Kelas;
use App\Models\MataKuliah;
use App\Models\Prodi;
use App\Models\Role;
use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\SeedsEssentialData;
use Tests\TestCase;

/**
 * RENCANA 2: KRS mahasiswa diturunkan dari pivot `mahasiswa_kelas`
 * (kelas aktif → jadwal). Memindahkan mahasiswa antar kelas = mengubah
 * pivot; snapshot `users.kelas` disinkronkan oleh UserObserver.
 */
class MahasiswaEnrollmentSyncTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private Semester $semester;

    private Prodi $prodi;

    /** @var array<string, Kelas> */
    private array $kelasByNama = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();

        $tahunAjaran = TahunAjaran::create([
            'kode' => '2025/2026',
            'nama' => 'Tahun Ajaran 2025/2026',
            'tanggal_mulai' => '2025-07-01',
            'tanggal_selesai' => '2026-06-30',
            'status' => 'aktif',
        ]);

        $this->semester = Semester::create([
            'tahun_ajaran_id' => $tahunAjaran->id,
            'nama' => 'Genap',
            'kode' => '2025/2026-2',
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-06-30',
            'status' => 'aktif',
        ]);

        $this->prodi = Prodi::where('kode', 'TI')->firstOrFail();
    }

    private function makeKelas(string $nama): Kelas
    {
        return $this->kelasByNama[$nama] ??= Kelas::create([
            'prodi_id' => $this->prodi->id,
            'semester_id' => $this->semester->id,
            'tingkat' => '4',
            'nama' => $nama,
            'status' => 'aktif',
        ]);
    }

    private function makeMataKuliah(string $namaKelas, string $kodeMk = 'TI-401'): MataKuliah
    {
        return MataKuliah::create([
            'kode_mk' => $kodeMk,
            'nama' => 'Pemrograman Mobile',
            'sks' => 3,
            'semester_id' => $this->semester->id,
            'prodi_id' => $this->prodi->id,
            'status' => 'aktif',
        ]);
    }

    private function makeMahasiswa(string $namaKelas): User
    {
        $user = User::factory()->create([
            'nama' => 'Mahasiswa Uji',
            'email' => 'mahasiswa.uji.'.uniqid().'@test.com',
            'password' => Hash::make('12345678'),
            'nim' => '2024'.rand(100000, 999999),
            'prodi_id' => $this->prodi->id,
            'kelas' => '4'.$namaKelas,
            'semester' => 4,
            'status' => 'aktif',
            'enrollment_status' => 'belum',
        ]);
        $user->roles()->attach(Role::where('name', 'mahasiswa')->first()->id);

        return $user;
    }

    public function test_snapshot_kelas_dan_semester_diselaraskan_saat_kelas_diubah(): void
    {
        $this->makeKelas('B');
        $user = $this->makeMahasiswa('B');
        $user->mahasiswaKelas()->create(['kelas_id' => $this->makeKelas('B')->id, 'semester_id' => $this->semester->id]);

        // Kelas tujuan belum ada di master → pivot aktif dilepas, snapshot tetap.
        $user->update(['kelas' => '4Z']);
        $this->assertNull($user->fresh()->kelasAktif);

        // Buat kelas master lalu set ulang → pivot + semester mengikuti.
        $this->makeKelas('C');
        $user->update(['kelas' => '4C']);
        $this->assertSame($this->makeKelas('C')->id, $user->fresh()->kelasAktif?->kelas_id);
        $this->assertSame(4, (int) $user->fresh()->semester);
    }

    public function test_jadwal_hari_ini_ikut_berubah_setelah_pivot_kelas_diubah(): void
    {
        $hariIni = now()->locale('id')->isoFormat('dddd');

        $mkB = $this->makeMataKuliah('B', 'TI-402');
        $kelasE = $this->makeKelas('E');
        $kelasB = $this->makeKelas('B');

        $geofence = Geofence::create([
            'nama' => 'Lab Komputer 3',
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'radius' => 50,
            'prodi_id' => $this->prodi->id,
            'status' => 'aktif',
        ]);

        // Hanya kelas B yang punya jadwal hari ini.
        Jadwal::create([
            'mata_kuliah_id' => $mkB->id,
            'kelas_id' => $kelasB->id,
            'geofence_id' => $geofence->id,
            'hari' => $hariIni,
            'jam_mulai' => '13:00:00',
            'jam_selesai' => '15:30:00',
            'ruangan' => 'Lab Komputer 3',
            'status' => 'aktif',
        ]);

        $user = $this->makeMahasiswa('E');
        $user->mahasiswaKelas()->create(['kelas_id' => $kelasE->id, 'semester_id' => $this->semester->id]);

        $token = $this->postJson('/api/auth/login', [
            'login' => $user->nim,
            'password' => '12345678',
        ])->json('data.token');

        // Sebelum dipindah: mahasiswa kelas E tidak melihat jadwal apa pun.
        $this->withToken($token)->getJson('/api/mahasiswa/jadwal/today')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $user->mahasiswaKelas()->update(['kelas_id' => $kelasB->id]);

        // Sesudah dipindah: jadwal kelas B muncul.
        $this->withToken($token)->getJson('/api/mahasiswa/jadwal/today')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ruangan', 'Lab Komputer 3');
    }

    public function test_pengguna_non_mahasiswa_tidak_disentuh(): void
    {
        $this->makeKelas('B');
        $dosen = User::factory()->create([
            'nama' => 'Dosen Uji',
            'email' => 'dosen.uji.'.uniqid().'@test.com',
            'password' => Hash::make('12345678'),
            'prodi_id' => $this->prodi->id,
            'kelas' => '4B',
            'status' => 'aktif',
            'enrollment_status' => 'belum',
        ]);
        $dosen->roles()->attach(Role::where('name', 'dosen')->first()->id);

        $dosen->update(['kelas' => '4C']);

        $this->assertSame(0, $dosen->fresh()->mahasiswaKelas()->count());
    }
}
