<?php

namespace App\Console\Commands;

use App\Models\AlphaAccumulation;
use App\Models\Attendance;
use App\Models\Jadwal;
use App\Models\MataKuliah;
use App\Models\ProdiSetting;
use App\Models\Semester;
use App\Models\User;
use App\Services\AlphaAccumulationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seeder demo SP yang idempotent.
 *
 * Membuat data dummy SP untuk kelas TI-401.A/C/D TANPA menyentuh kelas
 * TI-402.B (data valid). Setiap mahasiswa target mendapat attendance alpha
 * dummy di masa lalu sehingga akumulasi alpha melewati threshold SP1/SP2/SP3
 * dan muncul sebagai kandidat di halaman /sp.
 *
 * Aman dijalankan ulang: tidak membuat duplikat (updateOrCreate + skip bila
 * akumulasi sudah mencapai level target).
 */
class SeedSpDemo extends Command
{
    protected $signature = 'attendance:seed-sp-demo';

    protected $description = 'Seed dummy SP1/SP2/SP3 untuk kelas TI-401.A/C/D tanpa menyentuh TI-402.B';

    public function handle(): int
    {
        $semester = Semester::where('status', 'aktif')->first();
        if (! $semester) {
            $this->error('Tidak ada semester aktif.');

            return Command::FAILURE;
        }

        $thresholds = $this->thresholds();

        // Kelas -> level SP yang ditargetkan -> daftar mahasiswa kandidat.
        // Satu mahasiswa per kelas (deterministik, lihat pickMahasiswa).
        $targets = [
            'A' => ['level' => 'sp1', 'min_menit' => $thresholds['sp1'] * 60],
            'C' => ['level' => 'sp2', 'min_menit' => $thresholds['sp2'] * 60],
            'D' => ['level' => 'sp3', 'min_menit' => $thresholds['sp3'] * 60],
        ];

        $summary = [];
        $handled = 0;

        foreach ($targets as $kelas => $target) {
            $mk = MataKuliah::where('kode_mk', 'TI-401')
                ->where('kelas', $kelas)
                ->where('semester_id', $semester->id)
                ->first();

            if (! $mk) {
                $this->warn("MK TI-401 kelas {$kelas} tidak ditemukan, dilewati.");

                continue;
            }

            $jadwal = $this->ensureJadwal($mk);
            if (! $jadwal) {
                $this->error("Gagal membuat jadwal untuk TI-401-{$kelas}.");

                return Command::FAILURE;
            }

            // Idempotent per kelas: bila kelas ini SUDAH punya kandidat SP,
            // jangan tambah mahasiswa baru (cegah duplikat saat run ulang).
            if ($this->kelasSudahTerSeed($mk)) {
                $this->warn("Kelas TI-401-{$kelas} sudah punya kandidat SP, dilewati.");

                continue;
            }

            $mahasiswa = $this->pickMahasiswa($mk);
            if (! $mahasiswa) {
                $this->warn("Tidak ada mahasiswa kelas {$kelas} di MK TI-401, dilewati.");

                continue;
            }

            $totalMenit = $this->ensureAlpha($mahasiswa, $mk, $jadwal, $target['min_menit'], $semester);
            $accumulation = $this->recalculate($mahasiswa->id, $semester->id);
            $spStatus = $accumulation?->sp_status ?? '?';

            $summary[] = sprintf(
                '%s (kelas %s) -> %s: %s menit (%s jam), sp_status=%s',
                $mahasiswa->nama,
                $kelas,
                strtoupper($target['level']),
                $totalMenit,
                round($totalMenit / 60, 2),
                $spStatus
            );
            $handled++;
        }

        $this->info("Seed SP demo selesai. {$handled} mahasiswa diproses:");
        foreach ($summary as $line) {
            $this->line("  - {$line}");
        }

        return Command::SUCCESS;
    }

    /**
     * Threshold SP (jam) dari prodi TI.
     */
    protected function thresholds(): array
    {
        $defaults = ['sp1' => 16, 'sp2' => 32, 'sp3' => 38, 'do' => 46];
        $setting = ProdiSetting::where('prodi_id', 2)->first();

        return $setting ? [
            'sp1' => (int) ($setting->sp1_jam_mulai ?? $defaults['sp1']),
            'sp2' => (int) ($setting->sp2_jam_mulai ?? $defaults['sp2']),
            'sp3' => (int) ($setting->sp3_jam_mulai ?? $defaults['sp3']),
            'do' => (int) ($setting->do_jam_mulai ?? $defaults['do']),
        ] : $defaults;
    }

    /**
     * Pastikan MK punya jadwal aktif (jadwal_id NOT NULL di attendances).
     */
    protected function ensureJadwal(MataKuliah $mk): ?Jadwal
    {
        $existing = Jadwal::where('mata_kuliah_id', $mk->id)->where('status', 'aktif')->first();
        if ($existing) {
            return $existing;
        }

        $geofenceId = DB::table('geofences')->value('id');

        return Jadwal::create([
            'mata_kuliah_id' => $mk->id,
            'geofence_id' => $geofenceId,
            'hari' => 'Sabtu',
            'jam_mulai' => '08:00',
            'jam_selesai' => '10:30',
            'ruangan' => 'Lab Demo SP',
            'durasi_menit' => 150,
            'status' => 'aktif',
        ]);
    }

    /**
     * Cek apakah kelas MK ini sudah punya minimal satu kandidat SP
     * (alpha_accumulation sp_status != aman) pada semester aktif.
     */
    protected function kelasSudahTerSeed(MataKuliah $mk): bool
    {
        $mahasiswaIds = $mk->mahasiswas()->pluck('users.id');

        return AlphaAccumulation::whereIn('user_id', $mahasiswaIds)
            ->where('sp_status', '!=', 'aman')
            ->exists();
    }

    /**
     * Pilih 1 mahasiswa dari kelas yang terdaftar di MK.
     * Deterministik (urut id terkecil) agar command idempotent — sekali satu
     * kelas ter-seed, run berikutnya memilih mahasiswa yang sama dan tidak
     * menambah duplikat.
     */
    protected function pickMahasiswa(MataKuliah $mk): ?User
    {
        $ids = $mk->mahasiswas()
            ->whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))
            ->pluck('users.id');

        if ($ids->isEmpty()) {
            return null;
        }

        // Pilih mahasiswa pertama (id terkecil) yang BELUM mencapai target SP
        // kelasnya, agar tidak membuat duplikat saat command dijalankan ulang.
        foreach ($ids->sort() as $id) {
            $alreadySeeded = AlphaAccumulation::where('user_id', $id)
                ->where('sp_status', '!=', 'aman')
                ->exists();
            if (! $alreadySeeded) {
                return User::find($id);
            }
        }

        // Semua mahasiswa di kelas sudah ter-seed — jangan tambah duplikat.
        return null;
    }

    /**
     * Pastikan total alpha_menit mahasiswa >= target. Hanya menambah bila belum
     * mencapai target (idempotent). Semua record dummy memakai tanggal Sabtu
     * di masa lalu agar tidak mengganggu data hari ini.
     */
    protected function ensureAlpha(User $mahasiswa, MataKuliah $mk, Jadwal $jadwal, int $minMenit, Semester $semester): int
    {
        $current = Attendance::where('user_id', $mahasiswa->id)
            ->whereHas('mataKuliah', fn ($q) => $q->where('semester_id', $semester->id))
            ->sum('alpha_menit');

        $need = $minMenit - $current;
        if ($need <= 0) {
            return $current;
        }

        $durasi = (int) $jadwal->durasi_menit;
        $pertemuan = 1;
        $tanggal = Carbon::now()->startOfWeek()->subWeek();

        while ($need > 0) {
            Attendance::updateOrCreate(
                [
                    'user_id' => $mahasiswa->id,
                    'jadwal_id' => $jadwal->id,
                    'tanggal' => $tanggal->toDateString(),
                ],
                [
                    'mata_kuliah_id' => $mk->id,
                    'pertemuan_ke' => $pertemuan,
                    'status' => 'alpha',
                    'alpha_menit' => min($durasi, $need),
                ]
            );
            $need -= $durasi;
            $pertemuan++;
            $tanggal->subWeek();
        }

        return Attendance::where('user_id', $mahasiswa->id)
            ->whereHas('mataKuliah', fn ($q) => $q->where('semester_id', $semester->id))
            ->sum('alpha_menit');
    }

    /**
     * Recalculate akumulasi alpha (memakai service yang sama dengan runtime).
     */
    protected function recalculate(int $userId, int $semesterId): ?AlphaAccumulation
    {
        return app(AlphaAccumulationService::class)->recalculate($userId, $semesterId);
    }
}
