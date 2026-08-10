<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Prodi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProdiController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $sort = $request->string('sort', 'kode')->toString();
        $direction = $request->string('direction', 'asc')->toString() === 'desc' ? 'desc' : 'asc';
        $perPage = $this->resolvePerPage($request, 10);

        $allowedSorts = ['kode', 'nama', 'jenjang', 'status'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'kode';
        }

        $prodis = Prodi::withCount(['users', 'mataKuliahs'])
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('nama', 'like', "%{$search}%")
                        ->orWhere('kode', 'like', "%{$search}%")
                        ->orWhere('jurusan', 'like', "%{$search}%");
                });
            })
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Prodi $p) => [
                'id' => $p->id,
                'kode' => $p->kode,
                'nama' => $p->nama,
                'jenjang' => $p->jenjang,
                'jurusan' => $p->jurusan,
                'status' => $p->status,
                'users_count' => $p->users_count,
                'mata_kuliahs_count' => $p->mata_kuliahs_count,
            ]);

        return Inertia::render('Prodi/Index', [
            'prodis' => $prodis,
            'filters' => [
                'search' => $search,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        Prodi::create($data);

        return back()->with('success', 'Program studi berhasil ditambahkan.');
    }

    public function update(Request $request, Prodi $prodi): RedirectResponse
    {
        $data = $this->validateData($request, $prodi->id);
        $prodi->update($data);

        return back()->with('success', 'Program studi berhasil diperbarui.');
    }

    public function destroy(Prodi $prodi): RedirectResponse
    {
        if ($prodi->users()->exists()) {
            return back()->with('error', 'Prodi tidak dapat dihapus karena masih memiliki pengguna.');
        }

        $prodi->delete();

        return back()->with('success', 'Program studi berhasil dihapus.');
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'kode' => ['required', 'string', 'max:20', Rule::unique('prodis', 'kode')->ignore($ignoreId)],
            'nama' => ['required', 'string', 'max:255'],
            'jenjang' => ['required', 'string', 'max:20'],
            'jurusan' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }
}
