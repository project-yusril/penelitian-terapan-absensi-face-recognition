<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\MataKuliah;
use App\Models\ReEnrollmentRequest;
use App\Models\Semester;
use App\Models\SpRecord;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): Response
    {
        $user = $request->user();

        $semesterAktif = Semester::where('status', 'aktif')->first();

        $totalMahasiswa = $authorization->scopeUsers(User::query(), $user)
            ->whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))
            ->where('status', 'aktif')->count();
        $totalDosen = $authorization->scopeUsers(User::query(), $user)
            ->whereHas('roles', fn ($q) => $q->where('name', 'dosen'))
            ->where('status', 'aktif')->count();
        $totalMataKuliah = $semesterAktif
            ? $authorization->scopeMataKuliahs(MataKuliah::query(), $user)
                ->where('semester_id', $semesterAktif->id)->where('status', 'aktif')->count()
            : 0;
        $enrollmentPending = $authorization->scopeUsers(User::query(), $user)
            ->where('enrollment_status', 'pending')->count();

        $today = $authorization->scopeAttendances(Attendance::query(), $user)
            ->whereDate('tanggal', today())
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as hadir,
                SUM(CASE WHEN status = 'hadir_terlambat' THEN 1 ELSE 0 END) as terlambat,
                SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) as alpha,
                SUM(CASE WHEN status = 'izin' THEN 1 ELSE 0 END) as izin,
                SUM(CASE WHEN status = 'sakit' THEN 1 ELSE 0 END) as sakit
            ")
            ->first();

        // Tren kehadiran 7 hari terakhir (untuk chart sederhana)
        $trend = $authorization->scopeAttendances(Attendance::query(), $user)
            ->where('tanggal', '>=', today()->subDays(6))
            ->selectRaw('DATE(tanggal) as tgl, COUNT(*) as total,
                SUM(CASE WHEN status IN ("hadir","hadir_terlambat") THEN 1 ELSE 0 END) as hadir')
            ->groupBy('tgl')
            ->orderBy('tgl')
            ->get()
            ->keyBy('tgl');

        $days = collect(range(6, 0))->map(function ($i) use ($trend) {
            $date = today()->subDays($i);
            $key = $date->toDateString();
            $row = $trend->get($key);

            return [
                'label' => $date->isoFormat('dd, D MMM'),
                'total' => (int) ($row->total ?? 0),
                'hadir' => (int) ($row->hadir ?? 0),
            ];
        })->values();

        $recent = $authorization->scopeAttendances(
            Attendance::with(['user:id,nama,nim', 'mataKuliah:id,nama']),
            $user,
        )
            ->latest('checkin_time')
            ->limit(8)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'nama' => $a->user?->nama,
                'nim' => $a->user?->nim,
                'mata_kuliah' => $a->mataKuliah?->nama,
                'status' => $a->status,
                'checkin_time' => $a->checkin_time?->format('H:i'),
                'tanggal' => $a->tanggal?->format('d M Y'),
            ]);

        // Widget aksi per-peran (kartu "perlu tindakan").
        $roles = $user->roles->pluck('name');
        $prodiId = $user->prodi_id;
        $scoped = fn ($q) => ($prodiId && ! $roles->contains('super_admin'))
            ? $q->whereHas('user', fn ($u) => $u->where('prodi_id', $prodiId))
            : ($roles->contains('super_admin') ? $q : $q->whereRaw('1 = 0'));

        $actions = [];
        if ($roles->intersect(['kaprodi', 'super_admin'])->isNotEmpty()) {
            $actions['enrollment_pending'] = $authorization->scopeUsers(User::query(), $user)
                ->where('enrollment_status', 'pending')
                ->count();
            $actions['re_enrollment_pending'] = $scoped(ReEnrollmentRequest::where('status', 'pending'))->count();
            $actions['leave_pending'] = $scoped(LeaveRequest::where('status', 'pending'))->count();
        }
        if ($roles->intersect(['dosen', 'super_admin'])->isNotEmpty()) {
            $mkIds = $authorization->scopeMataKuliahs(MataKuliah::query(), $user)->pluck('id');
            $actions['attendance_pending'] = Attendance::whereIn('mata_kuliah_id', $mkIds)
                ->where('status', 'pending')->count();
        }
        if ($roles->intersect(['kaprodi', 'ketua_jurusan', 'admin_prodi', 'admin_jurusan', 'super_admin'])->isNotEmpty()) {
            $actions['sp_draft'] = $scoped(SpRecord::where('status', 'draft'))->count();
            $actions['sp_menunggu_kaprodi'] = $scoped(SpRecord::where('status', 'menunggu_kaprodi'))->count();
            $actions['sp_menunggu_kajur'] = $scoped(SpRecord::where('status', 'menunggu_kajur'))->count();
        }

        // Kartu statistik utama disesuaikan per peran.
        $isDosenOnly = $roles->contains('dosen')
            && $roles->intersect(['super_admin', 'ketua_jurusan', 'admin_jurusan', 'kaprodi', 'admin_prodi'])->isEmpty();

        if ($isDosenOnly) {
            // Dosen: statistik dibatasi pada mata kuliah yang diampu.
            $dosenMkIds = $authorization->scopeMataKuliahs(MataKuliah::query(), $user)->pluck('id');
            $statsCards = [
                ['label' => 'Mata Kuliah Diampu', 'value' => $dosenMkIds->count(), 'icon' => 'book', 'tone' => 'bg-brand-50 text-brand-600'],
                ['label' => 'Total Peserta', 'value' => DB::table('mahasiswa_mata_kuliah')->whereIn('mata_kuliah_id', $dosenMkIds)->distinct('user_id')->count('user_id'), 'icon' => 'users', 'tone' => 'bg-emerald-50 text-emerald-600'],
                ['label' => 'Perlu Approval', 'value' => Attendance::whereIn('mata_kuliah_id', $dosenMkIds)->where('status', 'pending')->count(), 'icon' => 'check', 'tone' => 'bg-amber-50 text-amber-600'],
                ['label' => 'Alpha Hari Ini', 'value' => Attendance::whereIn('mata_kuliah_id', $dosenMkIds)->whereDate('tanggal', today())->where('status', 'alpha')->count(), 'icon' => 'warning', 'tone' => 'bg-rose-50 text-rose-600'],
            ];
        } else {
            // Admin/manajemen: statistik global institusi.
            $statsCards = [
                ['label' => 'Mahasiswa Aktif', 'value' => $totalMahasiswa, 'icon' => 'users', 'tone' => 'bg-brand-50 text-brand-600'],
                ['label' => 'Dosen Aktif', 'value' => $totalDosen, 'icon' => 'user', 'tone' => 'bg-emerald-50 text-emerald-600'],
                ['label' => 'Mata Kuliah', 'value' => $totalMataKuliah, 'icon' => 'book', 'tone' => 'bg-amber-50 text-amber-600'],
                ['label' => 'Enrollment Pending', 'value' => $enrollmentPending, 'icon' => 'clock', 'tone' => 'bg-rose-50 text-rose-600'],
            ];
        }

        return Inertia::render('Dashboard', [
            'roles' => $roles,
            'actions' => $actions,
            'statsCards' => $statsCards,
            'stats' => [
                'mahasiswa' => $totalMahasiswa,
                'dosen' => $totalDosen,
                'mata_kuliah' => $totalMataKuliah,
                'enrollment_pending' => $enrollmentPending,
            ],

            'today' => [
                'total' => (int) ($today->total ?? 0),
                'hadir' => (int) ($today->hadir ?? 0),
                'terlambat' => (int) ($today->terlambat ?? 0),
                'alpha' => (int) ($today->alpha ?? 0),
                'izin' => (int) ($today->izin ?? 0),
                'sakit' => (int) ($today->sakit ?? 0),
            ],
            'trend' => $days,
            'recent' => $recent,
            'semester' => $semesterAktif?->only(['id', 'nama', 'kode', 'tanggal_mulai', 'tanggal_selesai']),
        ]);
    }
}
