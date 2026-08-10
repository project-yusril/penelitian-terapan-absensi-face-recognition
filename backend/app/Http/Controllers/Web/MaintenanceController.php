<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Toggle maintenance mode dari dashboard (super admin only).
 * Saat aktif, route web/api akan return 503 — kecuali yang di-bypass via
 * `--secret`. Status dapat dilihat via /api/healthz (`maintenance: true|false`).
 */
class MaintenanceController extends Controller
{
    public function down(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:200'],
            'retry' => ['nullable', 'integer', 'min:0', 'max:3600'],
        ]);

        Artisan::call('down', array_filter([
            '--render' => 'errors::503',
            '--retry' => $data['retry'] ?? 60,
            '--secret' => 'admin-'.substr(md5((string) now()), 0, 12),
        ]));

        AuditTrail::create([
            'user_id' => $request->user()->id,
            'action' => 'maintenance_down',
            'model_type' => 'System',
            'model_id' => 0,
            'old_values' => null,
            'new_values' => ['message' => $data['message'] ?? null, 'retry' => $data['retry'] ?? 60],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('success', 'Maintenance mode AKTIF. Akses publik diblokir sampai dimatikan.');
    }

    public function up(Request $request): RedirectResponse
    {
        Artisan::call('up');

        AuditTrail::create([
            'user_id' => $request->user()->id,
            'action' => 'maintenance_up',
            'model_type' => 'System',
            'model_id' => 0,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('success', 'Maintenance mode dimatikan. Sistem kembali aktif.');
    }
}
