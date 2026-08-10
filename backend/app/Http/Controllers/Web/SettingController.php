<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Prodi;
use App\Models\ProdiSetting;
use App\Models\SystemSetting;
use App\Services\AuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Konfigurasi per-prodi: toleransi waktu, threshold SP, parameter face
 * recognition, dan geofence. (PRD FR-CONFIG-001/002/003/004)
 */
class SettingController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): Response
    {
        $user = $request->user();

        // Kaprodi/admin prodi hanya lihat prodinya; super admin lihat semua.
        $prodisQuery = $authorization->scopeProdis(Prodi::query(), $user)->orderBy('nama');
        $prodis = $prodisQuery->get(['id', 'kode', 'nama']);

        $requestedId = $request->integer('prodi_id') ?: null;
        $selectedId = (int) ($prodis->firstWhere('id', $requestedId)?->id ?: $prodis->first()?->id);
        $setting = null;

        if ($selectedId) {
            $setting = ProdiSetting::firstOrCreate(['prodi_id' => $selectedId]);
        }

        // Konfigurasi global (system_settings) hanya untuk super admin.
        $systemSettings = null;
        if ($user->hasRole('super_admin')) {
            $systemSettings = SystemSetting::orderBy('group')->orderBy('key')
                ->get()
                ->map(fn (SystemSetting $s) => [
                    'key' => $s->key,
                    'group' => $s->group,
                    'value' => $s->typed_value,
                    'type' => $s->type,
                    'description' => $s->description,
                ])
                ->groupBy('group');
        }

        return Inertia::render('Settings/Index', [
            'prodis' => $prodis,
            'selectedProdiId' => $selectedId,
            'setting' => $setting,
            'systemSettings' => $systemSettings,
            'canManageSystem' => $user->hasRole('super_admin'),
        ]);
    }

    /**
     * Simpan konfigurasi global (system_settings). Super admin only.
     */
    public function updateSystem(Request $request, AuthorizationService $authorization): RedirectResponse
    {
        $authorization->assertCanManageSystemSettings($request->user());

        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string', 'exists:system_settings,key'],
            'settings.*.value' => ['present'],
        ]);

        foreach ($data['settings'] as $row) {
            $existing = SystemSetting::where('key', $row['key'])->first();
            if (! $existing) {
                continue;
            }

            $value = $row['value'];
            if ($existing->type === 'boolean') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            } elseif (is_array($value)) {
                $value = json_encode($value);
            } else {
                $value = (string) $value;
            }

            $existing->update(['value' => $value]);
        }

        return back()->with('success', 'Konfigurasi sistem berhasil disimpan.');
    }

    public function update(Request $request, Prodi $prodi, AuthorizationService $authorization): RedirectResponse
    {
        $authorization->scopeProdis(Prodi::query(), $request->user())->findOrFail($prodi->id);

        $data = $request->validate([
            'toleransi_masuk_menit' => ['required', 'integer', 'min:0', 'max:120'],
            'batas_terlambat_persen' => ['required', 'integer', 'min:0', 'max:100'],
            'toleransi_pulang_menit' => ['required', 'integer', 'min:0', 'max:120'],

            'sp1_jam_mulai' => ['required', 'integer', 'min:0'],
            'sp1_jam_akhir' => ['required', 'integer', 'gte:sp1_jam_mulai'],
            'sp2_jam_mulai' => ['required', 'integer', 'gte:sp1_jam_akhir'],
            'sp2_jam_akhir' => ['required', 'integer', 'gte:sp2_jam_mulai'],
            'sp3_jam_mulai' => ['required', 'integer', 'gte:sp2_jam_akhir'],
            'sp3_jam_akhir' => ['required', 'integer', 'gte:sp3_jam_mulai'],
            'do_jam_mulai' => ['required', 'integer', 'gte:sp3_jam_akhir'],

            'face_threshold' => ['required', 'numeric', 'min:0.1', 'max:3'],
            'liveness_challenge_count' => ['required', 'integer', 'min:1', 'max:5'],
            'liveness_timeout_seconds' => ['required', 'integer', 'min:3', 'max:60'],
            'max_failed_attempts' => ['required', 'integer', 'min:1', 'max:20'],

            'default_radius_meter' => ['required', 'integer', 'min:5', 'max:1000'],
            'gps_accuracy_minimum' => ['required', 'integer', 'min:1', 'max:200'],
            'gps_max_age_seconds' => ['required', 'integer', 'min:1', 'max:300'],
            'allow_offline_attendance' => ['required', 'boolean'],
            'offline_sync_timeout_menit' => ['required', 'integer', 'min:1', 'max:240'],

            'sp_warning_percentage' => ['required', 'integer', 'min:50', 'max:100'],
        ]);

        ProdiSetting::updateOrCreate(['prodi_id' => $prodi->id], $data);

        return back()->with('success', 'Konfigurasi prodi berhasil disimpan.');
    }
}
