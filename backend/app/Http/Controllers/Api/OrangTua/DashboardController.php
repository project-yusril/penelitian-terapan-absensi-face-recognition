<?php

namespace App\Http\Controllers\Api\OrangTua;

use App\Http\Controllers\Controller;
use App\Models\AlphaAccumulation;
use App\Models\Attendance;
use App\Models\Semester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $semesterAktif = Semester::where('status', 'aktif')->first();

        $children = $user->children()
            ->select('users.id', 'users.nama', 'users.nim', 'users.kelas', 'users.prodi_id')
            ->with('prodi:id,kode,nama')
            ->get();

        $childrenData = $children->map(function ($child) use ($semesterAktif) {
            // Summary kehadiran
            $stats = Attendance::where('user_id', $child->id)
                ->whereHas('mataKuliah', fn ($q) => $q->where('semester_id', $semesterAktif?->id))
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as hadir,
                    SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) as alpha
                ")
                ->first();

            // Alpha accumulation
            $alpha = AlphaAccumulation::where('user_id', $child->id)
                ->where('semester_id', $semesterAktif?->id)
                ->first();

            return [
                'id' => $child->id,
                'nama' => $child->nama,
                'nim' => $child->nim,
                'kelas' => $child->kelas,
                'prodi' => $child->prodi?->nama,
                'kehadiran' => [
                    'total' => $stats->total ?? 0,
                    'hadir' => $stats->hadir ?? 0,
                    'alpha' => $stats->alpha ?? 0,
                    'persentase' => $stats->total > 0
                        ? round(($stats->hadir / $stats->total) * 100, 1)
                        : 0,
                ],
                'sp_status' => $alpha?->sp_status ?? 'aman',
            ];
        });

        return $this->success($childrenData);
    }
}
