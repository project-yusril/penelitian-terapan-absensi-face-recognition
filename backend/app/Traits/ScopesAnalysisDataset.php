<?php

namespace App\Traits;

use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * R-04: aturan canonical untuk mempersempit dataset analisis/penelitian ke satu
 * prodi.
 *
 * Sebelumnya `?prodi_id` hanya dipakai untuk memilih `face_threshold`,
 * sementara dataset genuine/impostor tetap global. Akibatnya FAR/FRR dihitung
 * dari subjek lintas prodi terhadap ambang milik satu prodi — angka yang tidak
 * valid untuk laporan penelitian.
 *
 * Atribusi prodi memakai **prodi subjek** (`users.prodi_id`), bukan prodi mata
 * kuliah, karena ambang yang benar-benar diterapkan runtime juga berasal dari
 * sana: `ProdiSetting::where('prodi_id', $user->prodi_id)` pada
 * `Api\Mahasiswa\AttendanceController` dan `OfflineSyncController`. Dataset dan
 * threshold dengan demikian selalu berasal dari prodi yang sama.
 */
trait ScopesAnalysisDataset
{
    /**
     * Ambil `prodi_id` tervalidasi dari request.
     *
     * Prodi yang tidak dikenal ditolak eksplisit (422) alih-alih menghasilkan
     * dataset kosong secara diam-diam — dataset kosong yang tidak disengaja
     * mudah salah dibaca sebagai "tidak ada kesalahan verifikasi".
     */
    protected function resolveAnalysisProdiId(Request $request): ?int
    {
        $request->validate([
            'prodi_id' => ['nullable', 'integer', 'exists:prodis,id'],
        ]);

        return $request->filled('prodi_id') ? (int) $request->input('prodi_id') : null;
    }

    /**
     * Prodi efektif setelah scope aktor diterapkan.
     *
     * Endpoint `/api/admin/analysis/*` terbuka untuk `admin_jurusan` dan
     * `admin_prodi`, bukan hanya `super_admin`, sedangkan datanya bersifat
     * lintas prodi. Tanpa scope aktor, role tingkat prodi dapat membaca
     * statistik kehadiran, distribusi SP, dan sebaran verifikasi wajah milik
     * prodi lain — persis kebocoran yang ditutup H-21 untuk monitoring,
     * dashboard, report, dan setting.
     *
     * Mengikuti konvensi H-21: filter request hanya boleh mempersempit scope,
     * tidak pernah memperluasnya. Aktor tanpa `prodi_id` fail-closed.
     */
    protected function resolveAnalysisProdiScope(Request $request): ?int
    {
        $requested = $this->resolveAnalysisProdiId($request);
        $actor = $request->user();

        abort_unless($actor !== null, 403);

        if (app(AuthorizationService::class)->isSuperAdmin($actor)) {
            return $requested;
        }

        abort_unless($actor->prodi_id !== null, 403);
        abort_unless($requested === null || $requested === (int) $actor->prodi_id, 403);

        return (int) $actor->prodi_id;
    }

    /**
     * Batasi query yang memiliki kolom `user_id` ke subjek satu prodi.
     *
     * `withTrashed()` disengaja: M-19 menjadikan arsip (soft delete) sebagai
     * jalan resmi menonaktifkan master tanpa menghancurkan riwayat. Mahasiswa
     * yang sudah diarsipkan tetap harus terhitung pada dataset penelitian,
     * sehingga hasil berfilter prodi tidak boleh kehilangan baris yang ikut
     * terhitung saat filter dilepas.
     */
    protected function scopeDatasetToProdi(Builder $query, ?int $prodiId): Builder
    {
        if ($prodiId === null) {
            return $query;
        }

        return $query->whereIn(
            $query->getModel()->getTable().'.user_id',
            User::withTrashed()->where('prodi_id', $prodiId)->select('id')
        );
    }
}
