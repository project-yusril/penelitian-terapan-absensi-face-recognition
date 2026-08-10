<?php

namespace App\Http\Controllers\Api\Mahasiswa;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Services\PrivateFileUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LeaveRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = LeaveRequest::with('mataKuliah')
            ->where('user_id', $user->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $data = $query->orderByDesc('created_at')->paginate($this->resolvePerPage($request));
        $data->getCollection()->each(function (LeaveRequest $item): void {
            $item->file_surat_url = app(PrivateFileUrlService::class)->leaveDocument($item);
        });

        return $this->paginated($data);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'mata_kuliah_id' => 'required|exists:mata_kuliahs,id',
            'jenis' => 'required|in:izin,sakit',
            'tanggal_mulai' => 'required|date|after_or_equal:today',
            'tanggal_selesai' => 'required|date|after_or_equal:tanggal_mulai',
            'keterangan' => 'required|string|max:500',
            'file_surat' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $user = $request->user();

        // Validasi mahasiswa terdaftar di MK
        $enrolled = $user->mataKuliahs()->where('mata_kuliah_id', $request->mata_kuliah_id)->exists();
        if (! $enrolled) {
            return $this->error('Anda tidak terdaftar di mata kuliah ini', 403);
        }

        // Cek duplikat
        $exists = LeaveRequest::where('user_id', $user->id)
            ->where('mata_kuliah_id', $request->mata_kuliah_id)
            ->where('tanggal_mulai', $request->tanggal_mulai)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($exists) {
            return $this->error('Anda sudah mengajukan izin untuk mata kuliah ini pada tanggal tersebut', 422);
        }

        $filePath = null;
        if ($request->hasFile('file_surat')) {
            $filePath = $request->file('file_surat')->store('leave-requests', 'documents');
        }

        try {
            $leave = DB::transaction(fn () => LeaveRequest::create([
                'user_id' => $user->id,
                'mata_kuliah_id' => $request->mata_kuliah_id,
                'jenis' => $request->jenis,
                'tanggal_mulai' => $request->tanggal_mulai,
                'tanggal_selesai' => $request->tanggal_selesai,
                'keterangan' => $request->keterangan,
                'file_surat' => $filePath,
                'status' => 'pending',
            ]));
        } catch (\Throwable $exception) {
            if ($filePath) {
                Storage::disk('documents')->delete($filePath);
            }
            throw $exception;
        }

        $leave->load('mataKuliah');

        return $this->created($leave, 'Pengajuan izin berhasil disubmit');
    }
}
