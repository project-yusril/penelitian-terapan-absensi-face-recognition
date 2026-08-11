<?php

namespace Tests\Feature;

use App\Models\Geofence;
use App\Models\Jadwal;
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
 * KRS harus mengikuti kelas mahasiswa.
 *
 * Jadwal yang dilihat mahasiswa diambil dari pivot `mahasiswa_mata_kuliah`,
 * bukan dari kolom `users.kelas`. Tanpa sinkronisasi, memindahkan mahasiswa
 * antar kelas membuat jadwalnya salah atau hilang sama sekali.
 */
class MahasiswaEnrollmentSyncTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private Semester $semester;

    private Prodi $prodi;

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

    private function makeMataKuliah(string $kelas, string $kodeMk = 'TI-401'): MataKuliah
    {
        return MataKuliah::create([
            'kode_mk' => $kodeMk,
            'nama' => 'Pemrograman Mobile',
            'sks' => 3,
            'semester_id' => $this->semester->id,
            'prodi_id' => $this->prodi->id,
            'kelas' => $kelas,
            'status' => 'aktif',
        ]);
    }

    private function makeMahasiswa(string $kelas): User
    {
        $user = User::factory()->create([
            'nama' => 'Mahasiswa Uji',
            'email' => 'mahasiswa.uji@test.com',
            'password' => Hash::make('12345678'),
            'nim' => '2024009001',
            'prodi_id' => $this->prodi->id,
            'kelas' => $kelas,
            'status' => 'aktif',
            'enrollment_status' => 'belum',
        ]);
        $user->roles()->attach(Role::where('name', 'mahasiswa')->first()->id);

        return $user;
    }

    public function test_krs_pindah_ke_section_kelas_baru_saat_kelas_diubah(): void
    {
        $kelasE = $this->makeMataKuliah('E');
        $kelasB = $this->makeMataKuliah('B');

        $user = $this->makeMahasiswa('E');
        $user->mataKuliahs()->attach($kelasE->id);

        $user->update(['kelas' => 'B']);

        $this->assertSame(
            [$kelasB->id],
            $user->fresh()->mataKuliahs()->pluck('mata_kuliahs.id')->all(),
            'KRS harus menunjuk section kelas B setelah kelas mahasiswa diubah'
        );
    }

    public function test_padanan_ditemukan_walau_kode_mk_antar_section_berbeda(): void
    {
        // Kasus nyata: section kelas B sempat diberi kode berbeda (TI-402)
        // sementara section lain tetap TI-401. Pencocokan berbasis kode saja
        // akan gagal menemukan padanannya.
        $kelasE = $this->makeMataKuliah('E', 'TI-401');
        $kelasB = $this->makeMataKuliah('B', 'TI-402');

        $user = $this->makeMahasiswa('E');
        $user->mataKuliahs()->attach($kelasE->id);

        $user->update(['kelas' => 'B']);

        $this->assertSame(
            [$kelasB->id],
            $user->fresh()->mataKuliahs()->pluck('mata_kuliahs.id')->all(),
            'Pencocokan harus jatuh ke nama mata kuliah saat kode berbeda'
        );
    }

    public function test_jadwal_hari_ini_ikut_berubah_setelah_kelas_diubah(): void
    {
        $hariIni = now()->locale('id')->isoFormat('dddd');

        $kelasE = $this->makeMataKuliah('E');
        $kelasB = $this->makeMataKuliah('B', 'TI-402');

        $geofence = Geofence::create([
            'nama' => 'Lab Komputer 3',
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'radius' => 50,
            'prodi_id' => $this->prodi->id,
            'status' => 'aktif',
        ]);

        // Hanya section kelas B yang punya jadwal hari ini.
        Jadwal::create([
            'mata_kuliah_id' => $kelasB->id,
            'geofence_id' => $geofence->id,
            'hari' => $hariIni,
            'jam_mulai' => '13:00:00',
            'jam_selesai' => '15:30:00',
            'ruangan' => 'Lab Komputer 3',
            'status' => 'aktif',
        ]);

        $user = $this->makeMahasiswa('E');
        $user->mataKuliahs()->attach($kelasE->id);

        $token = $this->postJson('/api/auth/login', [
            'login' => '2024009001',
            'password' => '12345678',
        ])->json('data.token');

        // Sebelum dipindah: mahasiswa kelas E tidak melihat jadwal apa pun.
        $this->withToken($token)->getJson('/api/mahasiswa/jadwal/today')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $user->update(['kelas' => 'B']);

        // Sesudah dipindah: jadwal section kelas B muncul.
        $this->withToken($token)->getJson('/api/mahasiswa/jadwal/today')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ruangan', 'Lab Komputer 3');
    }

    public function test_krs_dibiarkan_saat_kelas_tidak_berubah(): void
    {
        $kelasB = $this->makeMataKuliah('B');
        $user = $this->makeMahasiswa('B');
        $user->mataKuliahs()->attach($kelasB->id);

        $user->update(['nama' => 'Nama Baru']);

        $this->assertSame(
            [$kelasB->id],
            $user->fresh()->mataKuliahs()->pluck('mata_kuliahs.id')->all()
        );
    }

    public function test_krs_tidak_dihapus_saat_section_pengganti_tidak_ada(): void
    {
        // Kelas tujuan belum punya section; melepas KRS begitu saja akan
        // menghilangkan mata kuliah dari kartu studi mahasiswa tanpa jejak.
        $kelasE = $this->makeMataKuliah('E');

        $user = $this->makeMahasiswa('E');
        $user->mataKuliahs()->attach($kelasE->id);

        $user->update(['kelas' => 'Z']);

        $this->assertSame(
            [$kelasE->id],
            $user->fresh()->mataKuliahs()->pluck('mata_kuliahs.id')->all(),
            'KRS lama harus dipertahankan bila tidak ada padanan'
        );
    }

    public function test_pengguna_non_mahasiswa_tidak_disentuh(): void
    {
        $kelasE = $this->makeMataKuliah('E');
        $this->makeMataKuliah('B');

        $dosen = User::factory()->create([
            'nama' => 'Dosen Uji',
            'email' => 'dosen.uji@test.com',
            'password' => Hash::make('12345678'),
            'prodi_id' => $this->prodi->id,
            'kelas' => 'E',
            'status' => 'aktif',
            'enrollment_status' => 'belum',
        ]);
        $dosen->roles()->attach(Role::where('name', 'dosen')->first()->id);
        $dosen->mataKuliahs()->attach($kelasE->id);

        $dosen->update(['kelas' => 'B']);

        $this->assertSame(
            [$kelasE->id],
            $dosen->fresh()->mataKuliahs()->pluck('mata_kuliahs.id')->all()
        );
    }
}
