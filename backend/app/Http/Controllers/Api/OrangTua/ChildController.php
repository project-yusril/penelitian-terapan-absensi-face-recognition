<?php

namespace App\Http\Controllers\Api\OrangTua;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\SpRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChildController extends Controller
{
    /**
     * List anak (mahasiswa) yang terhubung
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $children = $user->children()
            ->select('users.id', 'users.nama', 'users.nim', 'users.kelas', 'users.prodi_id', 'users.foto_profil', 'users.status')
            ->with('prodi:id,kode,nama')
            ->get()
            ->map(function ($child) {
                $child->hubungan = $child->pivot->hubungan;

                return $child;
            });

        return $this->success($children);
    }

    /**
     * Riwayat kehadiran anak
     */
    public function attendance(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        // Validasi bahwa ini memang anak dari orang tua ini
        $isChild = $user->children()->where('users.id', $id)->exists();
        if (! $isChild) {
            return $this->forbidden('Anda tidak memiliki akses ke data mahasiswa ini');
        }

        $query = Attendance::with('mataKuliah:id,kode_mk,nama')
            ->where('user_id', $id);

        if ($request->filled('date_from')) {
            $query->whereDate('tanggal', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('tanggal', '<=', $request->date_to);
        }

        $data = $query->orderByDesc('tanggal')->paginate($this->resolvePerPage($request, 20));

        return $this->paginated($data);
    }

    /**
     * SP records anak
     */
    public function spRecords(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $isChild = $user->children()->where('users.id', $id)->exists();
        if (! $isChild) {
            return $this->forbidden('Anda tidak memiliki akses ke data mahasiswa ini');
        }

        $spRecords = SpRecord::with('semester')
            ->where('user_id', $id)
            ->orderByDesc('created_at')
            ->get();

        return $this->success($spRecords);
    }
}
