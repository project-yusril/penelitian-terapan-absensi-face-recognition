<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exports\MahasiswaExport;
use App\Http\Controllers\Controller;
use App\Models\FaceEmbedding;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\UserSessionService;
use App\Support\SafeErrorMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenSpout\Reader\XLSX\Reader;

class UserController extends Controller
{
    /** Kolom yang boleh dipakai untuk sorting daftar user. */
    private const USER_SORTABLE = ['created_at', 'nama', 'nim', 'nidn', 'email', 'status', 'angkatan', 'kelas'];

    /**
     * List users with filters & pagination
     */
    public function index(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $query = $authorization->scopeUsers(User::with(['roles', 'prodi']), $request->user());

        // Filter by role
        if ($request->filled('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $request->role));
        }

        // Filter by prodi
        if ($request->filled('prodi_id')) {
            $query->where('prodi_id', $request->prodi_id);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by kelas
        if ($request->filled('kelas')) {
            $query->where('kelas', $request->kelas);
        }

        // Filter by angkatan
        if ($request->filled('angkatan')) {
            $query->where('angkatan', $request->angkatan);
        }

        // Search by nama, email, nim, nidn
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('nim', 'like', "%{$search}%")
                    ->orWhere('nidn', 'like', "%{$search}%");
            });
        }

        // Sort (allowlist kolom, arah tervalidasi)
        [$sortBy, $sortDir] = $this->resolveSort($request, self::USER_SORTABLE, 'created_at');
        $query->orderBy($sortBy, $sortDir);

        $users = $query->paginate($this->resolvePerPage($request));

        return $this->paginated($users);
    }

    /**
     * Show user detail
     */
    public function show(Request $request, $id, AuthorizationService $authorization): JsonResponse
    {
        $user = User::with(['roles', 'prodi', 'alphaAccumulations'])
            ->findOrFail($id);
        $authorization->assertCanManageUser($request->user(), $user);

        $data = $user->toArray();
        $data['biometric_enrollment'] = FaceEmbedding::where('user_id', $user->id)
            ->latest()->first(['id', 'version', 'status', 'approved_by', 'approved_at', 'created_at']);

        return $this->success($data);
    }

    /**
     * Create new user
     */
    public function store(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $request->validate([
            'nama' => 'required|string|max:150',
            'email' => 'required|email|max:100|unique:users,email',
            'password' => 'nullable|string|min:12',
            'nim' => 'nullable|string|max:20|unique:users,nim',
            'nidn' => 'nullable|string|max:20|unique:users,nidn',
            'nip' => 'nullable|string|max:30',
            'no_hp' => 'nullable|string|max:20',
            'tempat_lahir' => 'nullable|string|max:100',
            'tanggal_lahir' => 'nullable|date',
            'jenis_kelamin' => 'nullable|in:L,P',
            'alamat' => 'nullable|string',
            'prodi_id' => 'nullable|exists:prodis,id',
            'kelas' => 'nullable|string|max:10',
            'angkatan' => 'nullable|integer|min:2000|max:2099',
            'semester' => 'nullable|integer|min:1|max:14',
            'jabatan_fungsional' => 'nullable|string|max:50',
            'pendidikan_terakhir' => 'nullable|string|max:50',
            'bidang_keahlian' => 'nullable|string|max:255',
            'status' => 'nullable|in:aktif,nonaktif,do',
            'roles' => 'required|array|min:1',
            'roles.*' => 'exists:roles,name',
        ]);
        $authorization->assertCanCreateUser($request->user(), $request->roles, $request->integer('prodi_id') ?: null);

        $user = DB::transaction(function () use ($request) {
            $userData = $request->except(['roles', 'password']);
            $userData['password'] = Hash::make($request->password ?? Str::password(32));
            $userData['must_change_password'] = true;
            if (! $request->filled('password')) {
                $userData['status'] = 'nonaktif';
                $userData['activation_pending'] = true;
            }

            // Set enrollment status based on role
            $roles = $request->roles;
            if (in_array('mahasiswa', $roles)) {
                $userData['enrollment_status'] = 'belum';
            } else {
                $userData['enrollment_status'] = 'not_required';
            }

            $user = User::create($userData);

            // Assign roles
            $roleIds = Role::whereIn('name', $roles)->pluck('id');
            $user->roles()->sync($roleIds);

            return $user;
        });

        $user->load('roles', 'prodi');
        if ($user->activation_pending) {
            $user->sendPasswordResetNotification(Password::createToken($user));
        }

        return $this->created($user, 'User berhasil dibuat');
    }

    /**
     * Update user
     */
    public function update(Request $request, int $id, AuthorizationService $authorization): JsonResponse
    {
        $user = User::findOrFail($id);

        $request->validate([
            'nama' => 'sometimes|string|max:150',
            'email' => ['sometimes', 'email', 'max:100', Rule::unique('users')->ignore($user->id)],
            'nim' => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('users')->ignore($user->id)],
            'nidn' => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('users')->ignore($user->id)],
            'nip' => 'sometimes|nullable|string|max:30',
            'no_hp' => 'sometimes|nullable|string|max:20',
            'tempat_lahir' => 'sometimes|nullable|string|max:100',
            'tanggal_lahir' => 'sometimes|nullable|date',
            'jenis_kelamin' => 'sometimes|nullable|in:L,P',
            'alamat' => 'sometimes|nullable|string',
            'prodi_id' => 'sometimes|nullable|exists:prodis,id',
            'kelas' => 'sometimes|nullable|string|max:10',
            'angkatan' => 'sometimes|nullable|integer|min:2000|max:2099',
            'semester' => 'sometimes|nullable|integer|min:1|max:14',
            'jabatan_fungsional' => 'sometimes|nullable|string|max:50',
            'pendidikan_terakhir' => 'sometimes|nullable|string|max:50',
            'bidang_keahlian' => 'sometimes|nullable|string|max:255',
            'status' => 'sometimes|in:aktif,nonaktif,do',
            'roles' => 'sometimes|array|min:1',
            'roles.*' => 'exists:roles,name',
        ]);
        $authorization->assertCanUpdateUser(
            $request->user(), $user, $request->has('roles') ? $request->roles : null,
            $request->has('prodi_id') ? $request->input('prodi_id') : null, $request->has('prodi_id')
        );

        DB::transaction(function () use ($request, $user) {
            $oldStatus = $user->status;
            $user->update($request->except(['roles', 'password']));
            if ($oldStatus === 'aktif' && $user->status !== 'aktif') {
                app(UserSessionService::class)->revoke($user);
            }

            if ($request->filled('roles')) {
                $roleIds = Role::whereIn('name', $request->roles)->pluck('id');
                $user->roles()->sync($roleIds);
            }
        });

        $user->load('roles', 'prodi');

        return $this->success($user, 'User berhasil diperbarui');
    }

    /**
     * Soft delete user
     */
    public function destroy(Request $request, int $id, AuthorizationService $authorization): JsonResponse
    {
        $user = User::findOrFail($id);
        $authorization->assertCanManageUser($request->user(), $user);

        // Prevent deleting self
        if ($user->id === $request->user()->id) {
            return $this->error('Tidak dapat menghapus akun sendiri', 422);
        }

        $user->delete();

        return $this->success(message: 'User berhasil dihapus');
    }

    /**
     * Reset user password
     */
    public function resetPassword(Request $request, int $id, AuthorizationService $authorization): JsonResponse
    {
        $request->validate([
            'new_password' => 'required|string|min:12',
        ]);

        $user = User::findOrFail($id);
        $authorization->assertCanManageUser($request->user(), $user);
        $newPassword = $request->new_password;

        $user->update([
            'password' => Hash::make($newPassword),
            'must_change_password' => true,
        ]);

        // Revoke all tokens
        $user->tokens()->delete();

        return $this->success(message: 'Password berhasil direset');
    }

    /**
     * Import users from Excel/CSV
     */
    public function import(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:5120',
            'role' => 'required|in:mahasiswa,dosen',
            'prodi_id' => 'required|exists:prodis,id',
        ]);
        $authorization->assertCanCreateUser($request->user(), [$request->role], $request->integer('prodi_id'));

        $file = $request->file('file');
        $filePath = $file->getRealPath();
        $role = $request->role;
        $prodiId = $request->prodi_id;

        try {
            $reader = new Reader;
            $reader->open($filePath);

            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                    if ($rowIndex === 1) {
                        continue;
                    } // Skip header
                    $cells = $row->getCells();
                    $rows[] = array_map(fn ($cell) => $cell->getValue(), $cells);
                }
                break; // Only first sheet
            }
            $reader->close();

            if (empty($rows)) {
                return $this->error('File kosong atau format tidak sesuai', 422);
            }

            $imported = 0;
            $skipped = 0;
            $errors = [];

            foreach ($rows as $index => $row) {
                $lineNum = $index + 2; // +2 because we skipped header and 0-indexed

                try {
                    if ($role === 'mahasiswa') {
                        // Expected: NIM, Nama, Email, No HP, Kelas, Angkatan, Semester
                        $nim = trim($row[0] ?? '');
                        $nama = trim($row[1] ?? '');
                        $email = trim($row[2] ?? '');

                        if (empty($nim) || empty($nama) || empty($email)) {
                            $skipped++;

                            continue;
                        }

                        // Check duplicate
                        if (User::where('nim', $nim)->orWhere('email', $email)->exists()) {
                            $skipped++;

                            continue;
                        }

                        $user = User::create([
                            'nama' => $nama,
                            'email' => $email,
                            'nim' => $nim,
                            'no_hp' => trim($row[3] ?? ''),
                            'prodi_id' => $prodiId,
                            'kelas' => trim($row[4] ?? ''),
                            'angkatan' => (int) ($row[5] ?? date('Y')),
                            'semester' => (int) ($row[6] ?? 1),
                            'password' => Hash::make(Str::password(32)),
                            'must_change_password' => true,
                            'enrollment_status' => 'belum',
                            'status' => 'nonaktif',
                            'activation_pending' => true,
                        ]);

                        $mahasiswaRole = Role::where('name', 'mahasiswa')->first();
                        if ($mahasiswaRole) {
                            $user->roles()->attach($mahasiswaRole->id);
                        }
                        $user->sendPasswordResetNotification(Password::createToken($user));

                    } elseif ($role === 'dosen') {
                        // Expected: NIDN, Nama, Email, No HP, Jabatan, Pendidikan, Bidang
                        $nidn = trim($row[0] ?? '');
                        $nama = trim($row[1] ?? '');
                        $email = trim($row[2] ?? '');

                        if (empty($nidn) || empty($nama) || empty($email)) {
                            $skipped++;

                            continue;
                        }

                        if (User::where('nidn', $nidn)->orWhere('email', $email)->exists()) {
                            $skipped++;

                            continue;
                        }

                        $user = User::create([
                            'nama' => $nama,
                            'email' => $email,
                            'nidn' => $nidn,
                            'no_hp' => trim($row[3] ?? ''),
                            'prodi_id' => $prodiId,
                            'jabatan_fungsional' => trim($row[4] ?? ''),
                            'pendidikan_terakhir' => trim($row[5] ?? ''),
                            'bidang_keahlian' => trim($row[6] ?? ''),
                            'password' => Hash::make(Str::password(32)),
                            'must_change_password' => true,
                            'enrollment_status' => 'not_required',
                            'status' => 'nonaktif',
                            'activation_pending' => true,
                        ]);

                        $dosenRole = Role::where('name', 'dosen')->first();
                        if ($dosenRole) {
                            $user->roles()->attach($dosenRole->id);
                        }
                        $user->sendPasswordResetNotification(Password::createToken($user));
                    }

                    $imported++;
                } catch (\Exception $e) {
                    // M-22: jangan bocorkan detail exception/SQL ke response.
                    $errors[] = "Baris {$lineNum}: ".SafeErrorMessage::forDisplay(
                        $e,
                        'Data tidak dapat diproses.',
                        ['import' => 'api.users', 'line' => $lineNum],
                    );
                }
            }

            return $this->success([
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
            ], "Import selesai. {$imported} data berhasil diimport, {$skipped} data dilewati.");

        } catch (\Exception $e) {
            return $this->error(SafeErrorMessage::forDisplay(
                $e,
                'Gagal membaca file import.',
                ['import' => 'api.users'],
            ), 422);
        }
    }

    /**
     * Export mahasiswa to Excel
     */
    public function export(Request $request, AuthorizationService $authorization)
    {
        $request->validate([
            'prodi_id' => 'nullable|exists:prodis,id',
            'kelas' => 'nullable|string',
            'angkatan' => 'nullable|integer',
        ]);
        if (! $authorization->isSuperAdmin($request->user())) {
            abort_unless($request->user()->prodi_id && $request->integer('prodi_id') === $request->user()->prodi_id, 403);
        }

        $export = new MahasiswaExport(
            prodiId: $request->prodi_id,
            kelas: $request->kelas,
            angkatan: $request->angkatan ? (int) $request->angkatan : null,
        );

        $filePath = $export->generate();

        return response()->download($filePath)->deleteFileAfterSend(true);
    }

    /**
     * Toggle user status (aktif/nonaktif)
     */
    public function toggleStatus(Request $request, int $id, AuthorizationService $authorization): JsonResponse
    {
        $user = User::findOrFail($id);
        $authorization->assertCanManageUser($request->user(), $user);

        // Prevent toggling self
        if ($user->id === $request->user()->id) {
            return $this->error('Tidak dapat mengubah status akun sendiri', 422);
        }

        $newStatus = $user->status === 'aktif' ? 'nonaktif' : 'aktif';
        app(UserSessionService::class)->setStatus($user, $newStatus);

        return $this->success($user->fresh(), 'Status user berhasil diubah menjadi '.strtoupper($newStatus));
    }
}
