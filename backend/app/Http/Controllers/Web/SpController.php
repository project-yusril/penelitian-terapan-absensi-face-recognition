<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AlphaAccumulation;
use App\Models\Semester;
use App\Models\SpRecord;
use App\Services\AuthorizationService;
use App\Services\SpDocumentService;
use App\Services\SpWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Surat Peringatan (SP) — alur admin generate → kirim ke Kaprodi → TTD Kaprodi
 * → TTD Ketua Jurusan → final. (PRD FR-SP-003/004/005)
 */
class SpController extends Controller
{
    public function __construct(private SpDocumentService $spDocumentService) {}

    private function prodiScope(Request $request): ?int
    {
        $user = $request->user();
        if ($user->hasAnyRole(['super_admin', 'ketua_jurusan', 'admin_jurusan'])) {
            return null;
        }
        abort_unless($user->prodi_id, 403);

        return $user->prodi_id;
    }

    public function index(Request $request): Response
    {
        $prodiId = $this->prodiScope($request);
        $level = $request->string('level')->toString();
        $status = $request->string('status')->toString();
        $perPage = $this->resolvePerPage($request, 10);

        $items = SpRecord::with(['user:id,nama,nim,kelas,prodi_id', 'user.prodi:id,nama', 'semester:id,nama'])
            ->when($prodiId, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('prodi_id', $prodiId)))
            ->when($level, fn ($q) => $q->where('sp_level', $level))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($request->filled('search'), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('nama', 'like', "%{$request->search}%")->orWhere('nim', 'like', "%{$request->search}%")))
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (SpRecord $s) => [
                'id' => $s->id,
                'nama' => $s->user?->nama,
                'nim' => $s->user?->nim,
                'prodi' => $s->user?->prodi?->nama,
                'sp_level' => $s->sp_level,
                'nomor_surat' => $s->nomor_surat,
                'total_alpha_jam' => (float) $s->total_alpha_jam,
                'status' => $s->status,
                'tanggal_terbit' => $s->tanggal_terbit?->format('d M Y'),
                'has_document' => (bool) $s->document_path,
            ]);

        // Kandidat mahasiswa yang sudah masuk kategori SP tapi belum di-generate.
        $candidates = AlphaAccumulation::with('user:id,nama,nim,prodi_id')
            ->where('sp_status', '!=', 'aman')
            ->when($prodiId, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('prodi_id', $prodiId)))
            ->orderByDesc('total_alpha_menit')
            ->limit(50)
            ->get()
            ->map(fn (AlphaAccumulation $a) => [
                'user_id' => $a->user_id,
                'nama' => $a->user?->nama,
                'nim' => $a->user?->nim,
                'semester_id' => $a->semester_id,
                'sp_status' => $a->sp_status,
                'total_alpha_jam' => round(($a->total_alpha_menit ?? 0) / 60, 2),
            ]);

        return Inertia::render('Sp/Index', [
            'items' => $items,
            'filters' => [
                'search' => $request->search,
                'level' => $level,
                'status' => $status,
                'per_page' => $perPage,
            ],
            'candidates' => $candidates,
            'semesters' => Semester::select('id', 'nama')->orderByDesc('id')->get(),
            'canSign' => [
                'kaprodi' => $request->user()->hasAnyRole(['kaprodi', 'super_admin']),
                'kajur' => $request->user()->hasAnyRole(['ketua_jurusan', 'super_admin']),
            ],
        ]);
    }

    public function generate(Request $request, SpWorkflowService $workflow): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'level' => ['required', 'in:sp1,sp2,sp3,do'],
            'semester_id' => ['nullable', 'exists:semesters,id'],
        ]);
        $workflow->generate($request->user(), $data['user_id'], $data['level'], $data['semester_id'] ?? null, $request);

        return back()->with('success', 'Dokumen SP berhasil dibuat (draft).');
    }

    public function sendToKaprodi(Request $request, SpRecord $sp, SpWorkflowService $workflow): RedirectResponse
    {
        $workflow->sendToKaprodi($request->user(), $sp->id, $request);

        return back()->with('success', 'SP dikirim ke Kaprodi untuk ditandatangani.');
    }

    public function signKaprodi(Request $request, SpRecord $sp, SpWorkflowService $workflow): RedirectResponse
    {
        $workflow->signKaprodi($request->user(), $sp->id, $request);

        return back()->with('success', 'SP ditandatangani Kaprodi & diteruskan ke Ketua Jurusan.');
    }

    public function signKajur(Request $request, SpRecord $sp, SpWorkflowService $workflow): RedirectResponse
    {
        $workflow->signKajur($request->user(), $sp->id, $request);

        return back()->with('success', 'SP telah final & ditandatangani Ketua Jurusan.');
    }

    public function cancel(Request $request, SpRecord $sp, SpWorkflowService $workflow): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $workflow->cancel($request->user(), $sp->id, $request->reason, $request);

        return back()->with('success', 'SP dibatalkan.');
    }

    public function download(Request $request, SpRecord $sp): BinaryFileResponse|RedirectResponse
    {
        if (! $request->user()->hasAnyRole(['super_admin', 'ketua_jurusan', 'admin_jurusan'])) {
            app(AuthorizationService::class)->assertCanAccessProdi(
                $request->user(), $sp->user?->prodi_id, ['admin_prodi', 'kaprodi']
            );
        }
        if (! $sp->document_path) {
            return back()->with('error', 'Dokumen belum dibuat.');
        }
        $fullPath = storage_path("app/public/{$sp->document_path}");
        if (! file_exists($fullPath)) {
            return back()->with('error', 'File dokumen tidak ditemukan.');
        }

        return response()->download($fullPath, basename($sp->document_path));
    }
}
