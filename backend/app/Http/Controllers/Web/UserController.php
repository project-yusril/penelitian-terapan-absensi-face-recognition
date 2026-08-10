<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Prodi;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Support\SafeErrorMessage;
use App\Services\UserSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): Response
    {
        $search = $request->string('search')->toString();
        $role = $request->string('role')->toString();
        $status = $request->string('status')->toString();
        $sort = $request->string('sort', 'created_at')->toString();
        $direction = $request->string('direction', 'desc')->toString() === 'asc' ? 'asc' : 'desc';
        $perPage = $this->resolvePerPage($request, 10);

        $allowedSorts = ['nama', 'email', 'nim', 'status', 'created_at'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'created_at';
        }

        $query = $authorization->scopeUsers(User::with(['roles:id,name,display_name', 'prodi:id,kode,nama']), $request->user())
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('nama', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('nim', 'like', "%{$search}%")
                        ->orWhere('nidn', 'like', "%{$search}%");
                });
            })
            ->when($role, fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', $role)))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy($sort, $direction);

        $users = $query->paginate($perPage)->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'nama' => $u->nama,
                'email' => $u->email,
                'nim' => $u->nim,
                'nidn' => $u->nidn,
                'nip' => $u->nip,
                'no_hp' => $u->no_hp,
                'kelas' => $u->kelas,
                'angkatan' => $u->angkatan,
                'semester' => $u->semester,
                'status' => $u->status,
                'enrollment_status' => $u->enrollment_status,
                'prodi' => $u->prodi?->nama,
                'prodi_id' => $u->prodi_id,
                'roles' => $u->roles->pluck('display_name'),
                'role_names' => $u->roles->pluck('name'),
                'created_at' => $u->created_at?->format('d M Y'),
            ]);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => [
                'search' => $search,
                'role' => $role,
                'status' => $status,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
            ],
            'roles' => Role::select('id', 'name', 'display_name')->whereIn('name', $authorization->assignableRoleNames($request->user()))->get(),
            'prodis' => Prodi::select('id', 'kode', 'nama')->when($request->user()->hasRole('admin_prodi'), fn ($q) => $q->whereKey($request->user()->prodi_id))->get(),
        ]);
    }

    public function store(Request $request, AuthorizationService $authorization): RedirectResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role_id' => ['required', 'exists:roles,id'],
            'nim' => ['nullable', 'string', 'max:50', 'unique:users,nim'],
            'nidn' => ['nullable', 'string', 'max:50'],
            'nip' => ['nullable', 'string', 'max:50'],
            'no_hp' => ['nullable', 'string', 'max:20'],
            'prodi_id' => ['nullable', 'exists:prodis,id'],
            'kelas' => ['nullable', 'string', 'max:10'],
            'angkatan' => ['nullable', 'integer'],
            'semester' => ['nullable', 'integer'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ]);
        $role = Role::findOrFail($data['role_id']);
        $authorization->assertCanCreateUser($request->user(), [$role->name], $data['prodi_id'] ?? null);

        $user = User::create([
            'nama' => $data['nama'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'nim' => $data['nim'] ?? null,
            'nidn' => $data['nidn'] ?? null,
            'nip' => $data['nip'] ?? null,
            'no_hp' => $data['no_hp'] ?? null,
            'prodi_id' => $data['prodi_id'] ?? null,
            'kelas' => $data['kelas'] ?? null,
            'angkatan' => $data['angkatan'] ?? null,
            'semester' => $data['semester'] ?? null,
            'status' => $data['status'],
            'enrollment_status' => 'belum',
            'must_change_password' => true,
        ]);

        $user->roles()->sync([$data['role_id']]);

        return back()->with('success', 'Pengguna berhasil ditambahkan.');
    }

    public function update(Request $request, User $user, AuthorizationService $authorization): RedirectResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'role_id' => ['required', 'exists:roles,id'],
            'nim' => ['nullable', 'string', 'max:50', Rule::unique('users', 'nim')->ignore($user->id)],
            'nidn' => ['nullable', 'string', 'max:50'],
            'nip' => ['nullable', 'string', 'max:50'],
            'no_hp' => ['nullable', 'string', 'max:20'],
            'prodi_id' => ['nullable', 'exists:prodis,id'],
            'kelas' => ['nullable', 'string', 'max:10'],
            'angkatan' => ['nullable', 'integer'],
            'semester' => ['nullable', 'integer'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ]);
        $role = Role::findOrFail($data['role_id']);
        $authorization->assertCanUpdateUser($request->user(), $user, [$role->name], $data['prodi_id'] ?? null, true);

        $oldStatus = $user->status;
        $user->update([
            'nama' => $data['nama'],
            'email' => $data['email'],
            'nim' => $data['nim'] ?? null,
            'nidn' => $data['nidn'] ?? null,
            'nip' => $data['nip'] ?? null,
            'no_hp' => $data['no_hp'] ?? null,
            'prodi_id' => $data['prodi_id'] ?? null,
            'kelas' => $data['kelas'] ?? null,
            'angkatan' => $data['angkatan'] ?? null,
            'semester' => $data['semester'] ?? null,
            'status' => $data['status'],
        ]);

        if (! empty($data['password'])) {
            $user->update(['password' => Hash::make($data['password'])]);
        }

        $user->roles()->sync([$data['role_id']]);

        return back()->with('success', 'Data pengguna berhasil diperbarui.');
    }

    public function destroy(User $user, AuthorizationService $authorization): RedirectResponse
    {
        $authorization->assertCanManageUser(request()->user(), $user);
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Anda tidak dapat menghapus akun sendiri.');
        }

        $user->delete();

        return back()->with('success', 'Pengguna berhasil dihapus.');
    }

    /**
     * Bulk operations: activate, deactivate, atau soft-delete sekumpulan user.
     * Untuk skenario akhir semester (mass deactivate kelas X) atau akhir
     * tahun ajaran (purge angkatan lama). Akun login sendiri di-skip.
     */
    public function bulkAction(Request $request, AuthorizationService $authorization): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate', 'delete'])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:users,id'],
        ]);
        if ($oldStatus === 'aktif' && $user->status !== 'aktif') {
            app(UserSessionService::class)->revoke($user);
        }
        $targets = User::with('roles')->whereIn('id', $data['ids'])->get();
        foreach ($targets as $target) {
            $authorization->assertCanManageUser($request->user(), $target);
        }

        // Lindungi akun yang sedang login agar tidak self-locked.
        $ids = collect($data['ids'])->reject(fn ($id) => $id === auth()->id())->values();
        if ($ids->isEmpty()) {
            return back()->with('error', 'Tidak ada pengguna yang valid untuk diproses.');
        }

        $count = match ($data['action']) {
            'activate' => User::whereIn('id', $ids)->update(['status' => 'aktif']),
            'deactivate' => $this->deactivateUsers($ids),
            'delete' => User::whereIn('id', $ids)->delete(),
        };

        $label = match ($data['action']) {
            'activate' => 'diaktifkan',
            'deactivate' => 'dinonaktifkan',
            'delete' => 'dihapus',
        };

        return back()->with('success', "{$count} pengguna berhasil {$label}.");
    }

    /**
     * Export daftar mahasiswa ke XLSX (mengikuti filter aktif).
     */
    public function exportMahasiswa(Request $request, AuthorizationService $authorization): StreamedResponse
    {
        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();
        $prodiId = $request->integer('prodi_id');

        $users = $authorization->scopeUsers(User::with('prodi:id,nama'), $request->user())
            ->whereHas('roles', fn ($r) => $r->where('name', 'mahasiswa'))
            ->when($search, fn ($q) => $q->where(fn ($s) => $s
                ->where('nama', 'like', "%{$search}%")
                ->orWhere('nim', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($prodiId, fn ($q) => $q->where('prodi_id', $prodiId))
            ->orderBy('nim')
            ->get();

        $filename = 'mahasiswa_'.now()->format('Ymd_His').'.xlsx';

        return new StreamedResponse(function () use ($users) {
            $writer = new Writer(new Options);
            $writer->openToFile('php://output');

            $header = (new Style)->setFontBold();
            $writer->addRow(Row::fromValues(
                ['NIM', 'Nama', 'Email', 'No HP', 'Prodi', 'Kelas', 'Angkatan', 'Semester', 'Status'],
                $header
            ));

            foreach ($users as $u) {
                $writer->addRow(Row::fromValues([
                    (string) ($u->nim ?? ''),
                    (string) $u->nama,
                    (string) ($u->email ?? ''),
                    (string) ($u->no_hp ?? ''),
                    (string) ($u->prodi?->nama ?? ''),
                    (string) ($u->kelas ?? ''),
                    (string) ($u->angkatan ?? ''),
                    (string) ($u->semester ?? ''),
                    (string) $u->status,
                ]));
            }

            $writer->close();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Template XLSX kosong untuk import mahasiswa.
     */
    public function templateMahasiswa(): StreamedResponse
    {
        return new StreamedResponse(function () {
            $writer = new Writer(new Options);
            $writer->openToFile('php://output');

            $header = (new Style)->setFontBold();
            $writer->addRow(Row::fromValues(
                ['nim', 'nama', 'email', 'no_hp', 'prodi_kode', 'kelas', 'angkatan', 'semester'],
                $header
            ));
            // Baris contoh
            $writer->addRow(Row::fromValues(
                ['2024001999', 'Contoh Mahasiswa', 'contoh@example.com', '08123456789', 'TI', 'A', '2024', '1']
            ));

            $writer->close();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="template_import_mahasiswa.xlsx"',
        ]);
    }

    /**
     * Import mahasiswa dari XLSX.
     * Kolom: nim, nama, email, no_hp, prodi_kode, kelas, angkatan, semester.
     * Baris dengan email/nim yang sudah ada → di-update (idempoten).
     */
    public function importMahasiswa(Request $request, AuthorizationService $authorization): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ]);

        $mahasiswaRole = Role::where('name', 'mahasiswa')->first();
        if (! $mahasiswaRole) {
            return back()->with('error', 'Role mahasiswa tidak ditemukan.');
        }

        $prodiByKode = Prodi::pluck('id', 'kode'); // ['TI' => 2, ...]
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        $reader = new Reader;
        $reader->open($request->file('file')->getRealPath());

        foreach ($reader->getSheetIterator() as $sheet) {
            $rowNum = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $rowNum++;
                if ($rowNum === 1) {
                    continue; // header
                }

                $cells = array_map(
                    fn ($c) => is_null($c) ? '' : trim((string) $c),
                    $row->toArray()
                );

                // Lewati baris kosong.
                if (count(array_filter($cells, fn ($v) => $v !== '')) === 0) {
                    continue;
                }

                [$nim, $nama, $email, $noHp, $prodiKode, $kelas, $angkatan, $semester] = array_pad($cells, 8, '');

                if ($nama === '' || $email === '') {
                    $skipped++;
                    $errors[] = "Baris {$rowNum}: nama/email kosong.";

                    continue;
                }

                $prodiId = $prodiKode !== '' ? ($prodiByKode[$prodiKode] ?? null) : null;
                $authorization->assertCanCreateUser($request->user(), ['mahasiswa'], $prodiId);

                try {
                    DB::transaction(function () use (
                        $nim, $nama, $email, $noHp, $prodiId, $kelas, $angkatan, $semester,
                        $mahasiswaRole, $authorization, $request, &$created, &$updated
                    ) {
                        $existing = User::where('email', $email)
                            ->orWhere(fn ($q) => $nim !== '' ? $q->where('nim', $nim) : $q->whereRaw('1=0'))
                            ->first();

                        $payload = [
                            'nama' => $nama,
                            'email' => $email,
                            'nim' => $nim !== '' ? $nim : null,
                            'no_hp' => $noHp !== '' ? $noHp : null,
                            'prodi_id' => $prodiId,
                            'kelas' => $kelas !== '' ? $kelas : null,
                            'angkatan' => $angkatan !== '' ? (int) $angkatan : null,
                            'semester' => $semester !== '' ? (int) $semester : null,
                            'status' => $existing?->status ?? 'nonaktif',
                        ];

                        if ($existing) {
                            $authorization->assertCanManageUser($request->user(), $existing->loadMissing('roles'));
                            $existing->update($payload);
                            $existing->roles()->syncWithoutDetaching([$mahasiswaRole->id]);
                            $updated++;
                        } else {
                            $user = User::create($payload + [
                                'password' => Hash::make(Str::password(32)),
                                'enrollment_status' => 'belum',
                                'must_change_password' => true,
                                'activation_pending' => true,
                            ]);
                            $user->roles()->sync([$mahasiswaRole->id]);
                            $user->sendPasswordResetNotification(Password::createToken($user));
                            $created++;
                        }
                    });
                } catch (\Throwable $e) {
                    $skipped++;
                    // M-22: pesan exception mentah tidak ditampilkan ke pengguna.
                    $errors[] = "Baris {$rowNum}: ".SafeErrorMessage::forDisplay(
                        $e,
                        'Data tidak dapat diproses.',
                        ['import' => 'web.users', 'row' => $rowNum],
                    );
                }
            }
            break; // hanya sheet pertama
        }

        $reader->close();

        $msg = "Import selesai: {$created} ditambahkan, {$updated} diperbarui, {$skipped} dilewati.";
        if (! empty($errors)) {
            return back()->with('warning', $msg.' '.implode(' ', array_slice($errors, 0, 5)));
        }

        return back()->with('success', $msg);
    }

    private function deactivateUsers($ids): int
    {
        $users = User::whereIn('id', $ids)->get();
        foreach ($users as $user) {
            app(UserSessionService::class)->setStatus($user, 'nonaktif');
        }

        return $users->count();
    }
}
