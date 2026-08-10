<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $authorization->assertCanManageSystemSettings($request->user());

        $query = SystemSetting::query();

        if ($request->filled('group')) {
            $query->where('group', $request->group);
        }

        $settings = $query->orderBy('group')->orderBy('key')->get();

        // Group by category
        $grouped = $settings->groupBy('group')->map(function ($items) {
            return $items->mapWithKeys(function ($item) {
                return [$item->key => [
                    'value' => $item->typed_value,
                    'type' => $item->type,
                    'description' => $item->description,
                ]];
            });
        });

        return $this->success($grouped);
    }

    public function update(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $authorization->assertCanManageSystemSettings($request->user());

        $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'required',
        ]);

        foreach ($request->settings as $setting) {
            $existing = SystemSetting::where('key', $setting['key'])->first();

            if ($existing) {
                $value = is_array($setting['value']) ? json_encode($setting['value']) : (string) $setting['value'];
                $existing->update(['value' => $value]);
            }
        }

        return $this->success(message: 'Settings berhasil diperbarui');
    }
}
