<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Notification;
use App\Models\Semester;
use App\Models\SpRecord;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SpDocumentService
{
    /**
     * Generate SP record + PDF draft
     */
    public function generate(int $userId, string $level, ?int $semesterId = null, ?int $actorId = null): SpRecord
    {
        $user = User::with('prodi')->findOrFail($userId);

        if (! $semesterId) {
            $semesterAktif = Semester::where('status', 'aktif')->first();
            $semesterId = $semesterAktif?->id;
        }

        $semester = Semester::with('tahunAjaran')->find($semesterId);

        // Generate nomor surat
        $nomorSurat = $this->generateNomorSurat($level, $user->prodi?->kode);

        // Ambil rincian alpha per MK
        $rincianAlpha = $this->getRincianAlpha($userId, $semesterId);

        // Total alpha: SUM alpha_menit dari semua status yang berkontribusi
        $totalAlphaMenit = Attendance::where('user_id', $userId)
            ->whereHas('mataKuliah', fn ($q) => $q->where('semester_id', $semesterId))
            ->whereIn('status', ['hadir_terlambat', 'alpha', 'pending'])
            ->sum('alpha_menit');

        // Create SP record + document dalam transaction
        $spRecord = DB::transaction(function () use ($userId, $semesterId, $level, $nomorSurat, $totalAlphaMenit, $user, $semester, $rincianAlpha) {
            $spRecord = SpRecord::create([
                'user_id' => $userId,
                'semester_id' => $semesterId,
                'sp_level' => strtolower($level),
                'nomor_surat' => $nomorSurat,
                'tanggal_terbit' => today(),
                'total_alpha_jam' => $totalAlphaMenit / 60.0,
                'rincian_alpha' => $rincianAlpha,
                'status' => 'draft',
                'generated_by' => $actorId ?? auth()->id(),
                'generated_at' => now(),
            ]);

            // Generate PDF
            $pdfPath = $this->generatePdf($spRecord, $user, $semester, $rincianAlpha);

            // Simpan path dokumen langsung ke sp_record
            $spRecord->update(['document_path' => $pdfPath]);

            return $spRecord;
        });

        return $spRecord;
    }

    /**
     * Generate nomor surat otomatis
     */
    protected function generateNomorSurat(string $level, ?string $prodiKode): string
    {
        $bulan = Carbon::now()->format('m');
        $tahun = Carbon::now()->format('Y');
        $prodiKode = $prodiKode ?? 'JTE';

        // Count existing SP records this month
        $count = SpRecord::whereMonth('created_at', $bulan)
            ->whereYear('created_at', $tahun)
            ->count() + 1;

        $nomorUrut = str_pad($count, 3, '0', STR_PAD_LEFT);

        return "{$nomorUrut}/{$level}/{$prodiKode}/{$bulan}/{$tahun}";
    }

    /**
     * Ambil rincian alpha per mata kuliah
     * Includes hadir_terlambat, alpha, and pending — sums alpha_menit instead of counting
     */
    protected function getRincianAlpha(int $userId, int $semesterId): array
    {
        $attendances = Attendance::where('user_id', $userId)
            ->whereIn('status', ['hadir_terlambat', 'alpha', 'pending'])
            ->whereHas('mataKuliah', fn ($q) => $q->where('semester_id', $semesterId))
            ->with('mataKuliah:id,kode_mk,nama,sks')
            ->get();

        $rincian = $attendances->groupBy('mata_kuliah_id')->map(function ($items) {
            $mk = $items->first()->mataKuliah;

            return [
                'mata_kuliah_id' => $mk->id,
                'kode_mk' => $mk->kode_mk,
                'nama_mk' => $mk->nama,
                'sks' => $mk->sks,
                'total_alpha_menit' => $items->sum('alpha_menit'),
                'jumlah_record' => $items->count(),
                'tanggal_alpha' => $items->pluck('tanggal')->map(fn ($d) => $d->format('Y-m-d'))->toArray(),
            ];
        })->values()->toArray();

        return $rincian;
    }

    /**
     * Generate PDF document
     */
    protected function generatePdf(SpRecord $spRecord, User $user, ?Semester $semester, array $rincian): string
    {
        $data = [
            'sp_record' => $spRecord,
            'user' => $user,
            'semester' => $semester,
            'rincian' => $rincian,
            'tanggal' => Carbon::now()->locale('id')->isoFormat('D MMMM Y'),
            'institution' => [
                'name' => 'Politeknik Negeri Pontianak',
                'jurusan' => 'Jurusan Teknik Elektro',
                'alamat' => 'Jl. Ahmad Yani, Pontianak, Kalimantan Barat',
            ],
        ];

        $pdf = Pdf::loadView('pdf.surat-sp', $data);
        $pdf->setPaper('A4');

        $filename = "sp/{$spRecord->id}_{$spRecord->level}_{$user->nim}.pdf";
        $fullPath = storage_path("app/public/{$filename}");

        // Ensure directory exists
        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $pdf->save($fullPath);

        return $filename;
    }

    public function notifyKaprodi(SpRecord $spRecord): void
    {
        $user = $spRecord->user;
        $kaprodis = User::whereHas('roles', fn ($q) => $q->where('name', 'kaprodi'))
            ->where('prodi_id', $user->prodi_id)
            ->where('status', 'aktif')
            ->get();
        foreach ($kaprodis as $kaprodi) {
            Notification::firstOrCreate([
                'user_id' => $kaprodi->id, 'type' => 'approval_needed',
                'data->sp_record_id' => $spRecord->id,
            ], [
                'title' => "Dokumen {$spRecord->level} perlu tanda tangan",
                'body' => "Surat Peringatan {$spRecord->level} untuk {$user->nama} ({$user->nim}) menunggu tanda tangan Anda.",
                'data' => ['sp_record_id' => $spRecord->id, 'mahasiswa_id' => $user->id, 'level' => $spRecord->level],
            ]);
        }
    }

    public function notifyKajur(SpRecord $spRecord): void
    {
        $user = $spRecord->user;
        $kajurs = User::whereHas('roles', fn ($q) => $q->where('name', 'ketua_jurusan'))
            ->where('status', 'aktif')->get();
        foreach ($kajurs as $kajur) {
            Notification::firstOrCreate([
                'user_id' => $kajur->id, 'type' => 'approval_needed',
                'data->sp_record_id' => $spRecord->id,
            ], [
                'title' => "Dokumen {$spRecord->level} perlu tanda tangan Kajur",
                'body' => "Surat Peringatan {$spRecord->level} untuk {$user->nama} ({$user->nim}) menunggu tanda tangan Anda.",
                'data' => ['sp_record_id' => $spRecord->id, 'mahasiswa_id' => $user->id, 'level' => $spRecord->level],
            ]);
        }
    }

    /**
     * Regenerate PDF with digital signatures after signing
     * Called after kaprodi or kajur signs the document
     */
    public function regeneratePdfWithSignatures(SpRecord $spRecord): string
    {
        $user = $spRecord->user;
        $user->load('prodi');
        $semester = $spRecord->semester;
        $semester?->load('tahunAjaran');
        $rincian = $spRecord->rincian_alpha ?? [];

        // Load signature images
        $kaprodiSignature = null;
        $kajurSignature = null;

        if ($spRecord->signed_kaprodi_by) {
            $kaprodi = User::find($spRecord->signed_kaprodi_by);
            if ($kaprodi && $kaprodi->tanda_tangan && file_exists(storage_path("app/public/{$kaprodi->tanda_tangan}"))) {
                $kaprodiSignature = storage_path("app/public/{$kaprodi->tanda_tangan}");
            }
        }

        if ($spRecord->signed_kajur_by) {
            $kajur = User::find($spRecord->signed_kajur_by);
            if ($kajur && $kajur->tanda_tangan && file_exists(storage_path("app/public/{$kajur->tanda_tangan}"))) {
                $kajurSignature = storage_path("app/public/{$kajur->tanda_tangan}");
            }
        }

        $data = [
            'sp_record' => $spRecord,
            'user' => $user,
            'semester' => $semester,
            'rincian' => $rincian,
            'tanggal' => Carbon::now()->locale('id')->isoFormat('D MMMM Y'),
            'institution' => [
                'name' => 'Politeknik Negeri Pontianak',
                'jurusan' => 'Jurusan Teknik Elektro',
                'alamat' => 'Jl. Ahmad Yani, Pontianak, Kalimantan Barat',
            ],
            'kaprodi_name' => User::find($spRecord->signed_kaprodi_by)?->nama,
            'kajur_name' => User::find($spRecord->signed_kajur_by)?->nama,
            'kaprodi_signature' => $kaprodiSignature ? base64_encode(file_get_contents($kaprodiSignature)) : null,
            'kajur_signature' => $kajurSignature ? base64_encode(file_get_contents($kajurSignature)) : null,
            'signed_kaprodi_at' => $spRecord->signed_kaprodi_at,
            'signed_kajur_at' => $spRecord->signed_kajur_at,
        ];

        $pdf = Pdf::loadView('pdf.surat-sp', $data);
        $pdf->setPaper('A4');

        $filename = "sp/{$spRecord->id}_{$spRecord->sp_level}_{$user->nim}.pdf";
        $fullPath = storage_path("app/public/{$filename}");

        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $pdf->save($fullPath);

        $spRecord->update(['document_path' => $filename]);

        return $filename;
    }
}
