<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): Response
    {
        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();
        $date = $request->string('date')->toString();
        $sort = $request->string('sort', 'checkin_time')->toString();
        $direction = $request->string('direction', 'desc')->toString() === 'asc' ? 'asc' : 'desc';
        $perPage = $this->resolvePerPage($request, 10);

        $allowedSorts = ['checkin_time', 'tanggal', 'status', 'alpha_menit'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'checkin_time';
        }

        $items = $authorization->scopeAttendances(
            Attendance::with(['user:id,nama,nim', 'mataKuliah:id,nama']),
            $request->user(),
        )
            ->when($search, function ($q) use ($search) {
                $q->whereHas('user', function ($u) use ($search) {
                    $u->where('nama', 'like', "%{$search}%")
                        ->orWhere('nim', 'like', "%{$search}%");
                });
            })
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($date, fn ($q) => $q->whereDate('tanggal', $date))
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Attendance $a) => [
                'id' => $a->id,
                'nama' => $a->user?->nama,
                'nim' => $a->user?->nim,
                'mata_kuliah' => $a->mataKuliah?->nama,
                'tanggal' => $a->tanggal?->format('d M Y'),
                'checkin_time' => $a->checkin_time?->format('H:i'),
                'checkout_time' => $a->checkout_time?->format('H:i'),
                'status' => $a->status,
                'alpha_menit' => $a->alpha_menit,
                'durasi_efektif_menit' => $a->durasi_efektif_menit,
                'is_offline_synced' => (bool) $a->is_offline_synced,
                'is_overridden' => (bool) $a->is_overridden,
            ]);

        return Inertia::render('Attendance/Index', [
            'items' => $items,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'date' => $date,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
            ],
            'statusOptions' => ['hadir', 'hadir_terlambat', 'alpha', 'izin', 'sakit', 'pending'],
        ]);
    }
}
