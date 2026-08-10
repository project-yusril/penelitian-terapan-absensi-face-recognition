<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\ReEnrollmentRequest;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\EnrollmentWorkflowService;
use App\Services\LeaveApprovalService;
use App\Services\PrivateFileUrlService;
use App\Services\ReEnrollmentWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Web (Inertia) controller untuk seluruh alur persetujuan oleh Kaprodi:
 * - Enrollment wajah
 * - Re-enrollment
 * - Izin/Sakit (leave request)
 */
class ApprovalController extends Controller
{
    private function prodiScope(Request $request): ?int
    {
        $user = $request->user();

        // Super admin lihat semua; kaprodi dibatasi prodinya.
        return app(AuthorizationService::class)->requiredApprovalProdi($user);
    }

    // ==================== ENROLLMENT ====================
    public function enrollments(Request $request): Response
    {
        $prodiId = $this->prodiScope($request);
        $status = $request->string('status', 'pending')->toString();
        $perPage = $this->resolvePerPage($request, 10);

        $items = User::whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))
            ->where('enrollment_status', $status ?: 'pending')
            ->when($prodiId, fn ($q) => $q->where('prodi_id', $prodiId))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($s) => $s->where('nama', 'like', "%{$request->search}%")->orWhere('nim', 'like', "%{$request->search}%")))
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'nama' => $u->nama,
                'nim' => $u->nim,
                'email' => $u->email,
                'kelas' => $u->kelas,
                'enrollment_status' => $u->enrollment_status,
                'foto_url' => $u->foto_enrollment
                    ? app(PrivateFileUrlService::class)->enrollmentPhoto($u, true)
                    : null,

                'updated_at' => $u->updated_at?->format('d M Y H:i'),
            ]);

        return Inertia::render('Approval/Enrollments', [
            'items' => $items,
            'filters' => ['search' => $request->search, 'status' => $status, 'per_page' => $perPage],
        ]);
    }

    public function approveEnrollment(Request $request, User $user, EnrollmentWorkflowService $workflow): RedirectResponse
    {
        app(AuthorizationService::class)->assertCanApproveProdiResource($request->user(), $user->prodi_id);
        abort_unless($user->hasRole('mahasiswa'), 403);
        if ($user->enrollment_status !== 'pending') {
            return back()->with('error', 'Mahasiswa tidak dalam status pending enrollment.');
        }
        if (! $workflow->approve($request->user(), $user->id, $request)) {
            return back()->with('error', 'Data biometrik tidak dapat digunakan untuk pendaftaran.');
        }

        return back()->with('success', 'Enrollment disetujui.');
    }

    public function rejectEnrollment(Request $request, User $user, EnrollmentWorkflowService $workflow): RedirectResponse
    {
        app(AuthorizationService::class)->assertCanApproveProdiResource($request->user(), $user->prodi_id);
        abort_unless($user->hasRole('mahasiswa'), 403);
        $request->validate(['alasan' => ['required', 'string', 'max:500']]);
        if ($user->enrollment_status !== 'pending') {
            return back()->with('error', 'Mahasiswa tidak dalam status pending enrollment.');
        }
        $workflow->reject($request->user(), $user->id, $request->alasan, $request);

        return back()->with('success', 'Enrollment ditolak.');
    }

    // ==================== RE-ENROLLMENT ====================
    public function reEnrollments(Request $request): Response
    {
        $prodiId = $this->prodiScope($request);
        $status = $request->string('status', 'pending')->toString();
        $perPage = $this->resolvePerPage($request, 10);

        $items = ReEnrollmentRequest::with('user:id,nama,nim,prodi_id,kelas')
            ->where('status', $status ?: 'pending')
            ->when($prodiId, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('prodi_id', $prodiId)))
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (ReEnrollmentRequest $r) => [
                'id' => $r->id,
                'nama' => $r->user?->nama,
                'nim' => $r->user?->nim,
                'kelas' => $r->user?->kelas,
                'alasan' => $r->alasan ?? $r->reason ?? '—',
                'status' => $r->status,
                'foto_url' => app(PrivateFileUrlService::class)->reEnrollmentPhoto($r, true),
                'created_at' => $r->created_at?->format('d M Y H:i'),
            ]);

        return Inertia::render('Approval/ReEnrollments', [
            'items' => $items,
            'filters' => ['status' => $status, 'per_page' => $perPage],
        ]);
    }

    public function approveReEnrollment(Request $request, ReEnrollmentRequest $reEnrollment, ReEnrollmentWorkflowService $workflow): RedirectResponse
    {
        app(AuthorizationService::class)->assertCanApproveProdiResource($request->user(), $reEnrollment->user?->prodi_id);
        if ($reEnrollment->status !== 'pending') {
            return back()->with('error', 'Permintaan sudah diproses.');
        }
        $workflow->approve($request->user(), $reEnrollment->id, $request);

        return back()->with('success', 'Re-enrollment disetujui dan biometrik baru diaktifkan.');
    }

    public function rejectReEnrollment(Request $request, ReEnrollmentRequest $reEnrollment, ReEnrollmentWorkflowService $workflow): RedirectResponse
    {
        app(AuthorizationService::class)->assertCanApproveProdiResource($request->user(), $reEnrollment->user?->prodi_id);
        $request->validate(['alasan' => ['required', 'string', 'max:500']]);
        if ($reEnrollment->status !== 'pending') {
            return back()->with('error', 'Permintaan sudah diproses.');
        }
        $workflow->reject($request->user(), $reEnrollment->id, $request->alasan, $request);

        return back()->with('success', 'Re-enrollment ditolak.');
    }

    // ==================== LEAVE REQUEST (Izin/Sakit) ====================
    public function leaveRequests(Request $request): Response
    {
        $prodiId = $this->prodiScope($request);
        $status = $request->string('status', 'pending')->toString();
        $perPage = $this->resolvePerPage($request, 10);

        $items = LeaveRequest::with(['user:id,nama,nim,kelas,prodi_id', 'mataKuliah:id,kode_mk,nama'])
            ->where('status', $status ?: 'pending')
            ->when($prodiId, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('prodi_id', $prodiId)))
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (LeaveRequest $l) => [
                'id' => $l->id,
                'nama' => $l->user?->nama,
                'nim' => $l->user?->nim,
                'mata_kuliah' => $l->mataKuliah?->nama,
                'jenis' => $l->jenis,
                'tanggal_mulai' => $l->tanggal_mulai?->format('d M Y'),
                'tanggal_selesai' => $l->tanggal_selesai?->format('d M Y'),
                'keterangan' => $l->keterangan,
                'file_url' => app(PrivateFileUrlService::class)->leaveDocument($l, true),
                'status' => $l->status,
                'created_at' => $l->created_at?->format('d M Y H:i'),
            ]);

        return Inertia::render('Approval/LeaveRequests', [
            'items' => $items,
            'filters' => ['status' => $status, 'per_page' => $perPage],
        ]);
    }

    public function approveLeave(Request $request, LeaveRequest $leaveRequest, LeaveApprovalService $workflow): RedirectResponse
    {
        $leaveRequest->loadMissing(['user', 'mataKuliah']);
        app(AuthorizationService::class)->assertCanApproveProdiResource($request->user(), $leaveRequest->user?->prodi_id);
        abort_unless($leaveRequest->mataKuliah?->prodi_id === $leaveRequest->user?->prodi_id, 403);
        if ($leaveRequest->status !== 'pending') {
            return back()->with('error', 'Permintaan sudah diproses.');
        }
        $workflow->approve($request->user(), $leaveRequest->id, $request);

        return back()->with('success', 'Izin/sakit disetujui.');
    }

    public function rejectLeave(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $leaveRequest->loadMissing(['user', 'mataKuliah']);
        app(AuthorizationService::class)->assertCanApproveProdiResource($request->user(), $leaveRequest->user?->prodi_id);
        abort_unless($leaveRequest->mataKuliah?->prodi_id === $leaveRequest->user?->prodi_id, 403);
        $request->validate(['alasan' => ['required', 'string', 'max:500']]);
        if ($leaveRequest->status !== 'pending') {
            return back()->with('error', 'Permintaan sudah diproses.');
        }
        $leaveRequest->update([
            'status' => 'rejected',
            'rejected_reason' => $request->alasan,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return back()->with('success', 'Izin/sakit ditolak.');
    }
}
