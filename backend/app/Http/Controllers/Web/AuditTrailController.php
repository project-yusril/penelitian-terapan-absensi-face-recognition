<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditTrailController extends Controller
{
    public function index(Request $request): Response
    {
        $perPage = $this->resolvePerPage($request, 15);

        $query = $this->buildQuery($request);

        $items = $query
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (AuditTrail $a) => [
                'id' => $a->id,
                'user' => $a->user?->nama ?? 'System',
                'action' => $a->action,
                'model' => $a->model_type ? class_basename($a->model_type)." #{$a->model_id}" : '—',
                'old_values' => $a->old_values,
                'new_values' => $a->new_values,
                'ip_address' => $a->ip_address,
                'created_at' => $a->created_at?->format('d M Y H:i:s'),
            ]);

        return Inertia::render('AuditTrail/Index', [
            'items' => $items,
            'filters' => [
                'search' => $request->search,
                'action' => $request->action,
                'date_from' => $request->date_from,
                'date_to' => $request->date_to,
                'per_page' => $perPage,
            ],
            'actions' => AuditTrail::distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    /**
     * Export audit trail (CSV) sesuai filter aktif. Maks 10.000 baris untuk
     * mencegah memory blow-up; gunakan filter tanggal bila volume besar.
     */
    public function export(Request $request): StreamedResponse
    {
        $filename = 'audit_trail_'.now()->format('Ymd_His').'.csv';

        $query = $this->buildQuery($request)->orderByDesc('created_at')->limit(10000);

        return new StreamedResponse(function () use ($query) {
            $out = fopen('php://output', 'w');
            // BOM agar Excel mengenali UTF-8.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['waktu', 'pengguna', 'aksi', 'objek', 'ip', 'old_values', 'new_values']);

            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $a) {
                    fputcsv($out, [
                        $a->created_at?->format('Y-m-d H:i:s'),
                        $a->user?->nama ?? 'System',
                        $a->action,
                        $a->model_type ? class_basename($a->model_type)." #{$a->model_id}" : '',
                        $a->ip_address ?? '',
                        is_array($a->old_values) ? json_encode($a->old_values) : (string) ($a->old_values ?? ''),
                        is_array($a->new_values) ? json_encode($a->new_values) : (string) ($a->new_values ?? ''),
                    ]);
                }
            });

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function buildQuery(Request $request)
    {
        return AuditTrail::with('user:id,nama')
            ->when($request->filled('search'), fn ($q) => $q->where(function ($s) use ($request) {
                $s->where('action', 'like', "%{$request->search}%")
                    ->orWhere('model_type', 'like', "%{$request->search}%");
            }))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->action))
            ->when($request->filled('date_from'), fn ($q) => $q->where('created_at', '>=', $request->date_from.' 00:00:00'))
            ->when($request->filled('date_to'), fn ($q) => $q->where('created_at', '<=', $request->date_to.' 23:59:59'));
    }
}
