<?php

namespace App\Http\Controllers\Api\Mahasiswa;

use App\Http\Controllers\Controller;
use App\Models\SpRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $spRecords = SpRecord::with('semester')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        return $this->success($spRecords);
    }

    /**
     * Detail SP record mahasiswa
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $spRecord = SpRecord::with([
            'semester',
            'signedKaprodiBy:id,nama',
            'signedKajurBy:id,nama',
        ])
            ->where('user_id', $user->id)
            ->findOrFail($id);

        return $this->success($spRecord);
    }
}
