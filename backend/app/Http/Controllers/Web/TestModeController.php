<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mode Pengujian (PRD R-05): mengaktifkan pelabelan genuine/impostor pada log
 * verifikasi wajah untuk keperluan evaluasi FAR/FRR penelitian.
 */
class TestModeController extends Controller
{
    private const KEY = 'test_mode_enabled';

    public function index(Request $request): Response
    {
        $enabled = $this->isEnabled();

        $genuineCount = AttendanceLog::whereJsonContains('metadata->label', 'genuine')->count();
        $impostorCount = AttendanceLog::whereJsonContains('metadata->label', 'impostor')->count();

        // Daftar log verifikasi wajah yang BELUM dilabeli — untuk dilabeli admin.
        $unlabeled = AttendanceLog::with('user:id,nama,nim')
            ->where('is_test_mode', true)
            ->whereIn('action', ['checkin_success', 'face_not_match', 'checkout_success'])
            ->whereNotNull('face_distance')
            ->where(function ($q) {
                $q->whereNull('metadata->label')
                    ->orWhere(fn ($q2) => $q2->whereJsonDoesntContain('metadata->label', 'genuine')
                        ->whereJsonDoesntContain('metadata->label', 'impostor'));
            })
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'user_id', 'action', 'face_distance', 'face_threshold', 'inference_time_ms', 'device_model', 'created_at']);

        return Inertia::render('TestMode/Index', [
            'enabled' => $enabled,
            'stats' => [
                'genuine' => $genuineCount,
                'impostor' => $impostorCount,
                'labeled_total' => $genuineCount + $impostorCount,
            ],
            'unlabeled' => $unlabeled,
        ]);
    }

    /**
     * Labeli satu log verifikasi sebagai genuine atau impostor (R-05).
     * Menulis metadata->label tanpa mengganggu metadata lain.
     */
    public function labelLog(Request $request, AttendanceLog $log): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'in:genuine,impostor'],
        ]);

        $metadata = $log->metadata ?? [];
        $metadata['label'] = $data['label'];
        $log->metadata = $metadata;
        $log->is_test_mode = true;
        $log->test_type = $data['label']; // sinkron dengan kolom enum terindeks
        $log->save();

        return back()->with('success', "Log #{$log->id} dilabeli sebagai {$data['label']}.");
    }

    public function toggle(Request $request): RedirectResponse
    {
        $request->validate(['enabled' => ['required', 'boolean']]);

        SystemSetting::updateOrCreate(
            ['key' => self::KEY],
            [
                'group' => 'testing',
                'value' => $request->boolean('enabled') ? '1' : '0',
                'type' => 'boolean',
                'description' => 'Mode pengujian FAR/FRR (pelabelan log verifikasi wajah)',
            ]
        );

        return back()->with('success', $request->boolean('enabled')
            ? 'Mode pengujian diaktifkan.'
            : 'Mode pengujian dimatikan.');
    }

    private function isEnabled(): bool
    {
        $setting = SystemSetting::where('key', self::KEY)->first();

        return $setting ? (bool) (int) $setting->value : false;
    }
}
