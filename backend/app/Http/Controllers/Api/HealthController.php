<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReadinessService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function readiness(ReadinessService $readiness): JsonResponse
    {
        $checks = $readiness->checks();
        $ready = ! in_array('unavailable', $checks, true);

        return response()->json([
            'status' => $ready ? 'ready' : 'not_ready',
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }
}
