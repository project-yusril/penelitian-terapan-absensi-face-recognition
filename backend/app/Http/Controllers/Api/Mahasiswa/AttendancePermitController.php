<?php

namespace App\Http\Controllers\Api\Mahasiswa;

use App\Http\Controllers\Controller;
use App\Services\AttendancePermitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendancePermitController extends Controller
{
    public function store(Request $request, AttendancePermitService $permits): JsonResponse
    {
        $data = $request->validate([
            'jadwal_id' => ['required', 'integer', 'exists:jadwals,id'],
            'action' => ['required', 'in:check_in,check_out'],
            'client_uuid' => ['required', 'uuid'],
            'attendance_id' => ['nullable', 'integer', 'exists:attendances,id'],
        ]);
        abort_if($data['action'] === 'check_out' && empty($data['attendance_id']), 422);

        return $this->created($permits->issue($request->user(), $data['jadwal_id'], $data['action'], $data['client_uuid'], $data['attendance_id'] ?? null), 'Permit absensi diterbitkan');
    }
}
