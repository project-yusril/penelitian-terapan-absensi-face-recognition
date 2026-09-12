<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';

import PageHeader from '@/Components/PageHeader.vue';
import DataTable from '@/Components/DataTable.vue';
import Modal from '@/Components/Modal.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import InputError from '@/Components/InputError.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    users: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    roles: { type: Array, default: () => [] },
    prodis: { type: Array, default: () => [] },
    kelasOptions: { type: Array, default: () => [] },
});

// RENCANA 2: kelas mahasiswa diturunkan dari master (id kelas).
const isMahasiswaRole = computed(() => {
    const role = props.roles.find((r) => r.id === form.role_id);
    return role?.name === 'mahasiswa';
});

const selectedKelas = computed(() => props.kelasOptions.find((k) => k.id === form.kelas_id));
const semesterFollows = computed(() => selectedKelas.value?.tingkat ?? '');

const columns = [
    { key: 'select', label: '', width: '40px' },
    { key: 'nama', label: 'Nama', sortable: true },
    { key: 'foto_wajah', label: 'Wajah', width: '70px' },
    { key: 'identitas', label: 'Email / NIM' },
    { key: 'roles', label: 'Role' },
    { key: 'prodi', label: 'Prodi' },
    { key: 'enrollment_status', label: 'Enrollment', align: 'center' },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'aksi', label: '', align: 'right', width: '90px' },
];

// ---- Preview foto wajah terdaftar ----
const previewWajah = ref(null);
const previewWajahNama = ref('');

// ---- Bulk selection ----
const selectedIds = ref([]);
const allRowIds = computed(() => (props.users.data ?? []).map((r) => r.id));
const allChecked = computed({
    get: () => allRowIds.value.length > 0 && allRowIds.value.every((id) => selectedIds.value.includes(id)),
    set: (v) => {
        selectedIds.value = v ? [...allRowIds.value] : [];
    },
});
const isChecked = (id) => selectedIds.value.includes(id);
const toggleRow = (id) => {
    const i = selectedIds.value.indexOf(id);
    if (i === -1) selectedIds.value.push(id);
    else selectedIds.value.splice(i, 1);
};

const bulkBusy = ref(false);
const runBulk = (action) => {
    if (selectedIds.value.length === 0) return;
    const labels = { activate: 'mengaktifkan', deactivate: 'menonaktifkan', delete: 'menghapus' };
    if (!confirm(`Yakin ${labels[action]} ${selectedIds.value.length} pengguna?`)) return;

    bulkBusy.value = true;
    router.post(route('users.bulk-action'), {
        action,
        ids: selectedIds.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { selectedIds.value = []; },
        onFinish: () => { bulkBusy.value = false; },
    });
};

const roleFilter = ref(props.filters.role ?? '');
const statusFilter = ref(props.filters.status ?? '');

const applyFilters = () => {
    router.get(route('users.index'), {
        search: props.filters.search || undefined,
        role: roleFilter.value || undefined,
        status: statusFilter.value || undefined,
        per_page: props.filters.per_page,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

// ---- Create / Edit modal ----
const showForm = ref(false);
const editingId = ref(null);

const form = useForm({
    nama: '', email: '', password: '', role_id: '',
    nim: '', nidn: '', nip: '', no_hp: '',
    prodi_id: '', kelas_id: '', angkatan: '', semester: '',
    status: 'aktif',
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.status = 'aktif';
    showForm.value = true;
};

const openEdit = (row) => {
    editingId.value = row.id;
    form.clearErrors();
    form.nama = row.nama ?? '';
    form.email = row.email ?? '';
    form.password = '';
    form.role_id = props.roles.find((r) => row.role_names?.includes(r.name))?.id ?? '';
    form.nim = row.nim ?? '';
    form.nidn = row.nidn ?? '';
    form.nip = row.nip ?? '';
    form.no_hp = row.no_hp ?? '';
    form.prodi_id = row.prodi_id ?? '';
    form.kelas_id = row.kelas_id ?? '';
    form.angkatan = row.angkatan ?? '';
    form.semester = row.semester ?? '';
    form.status = row.status ?? 'aktif';
    showForm.value = true;
};

const submit = () => {
    const opts = {
        preserveScroll: true,
        onSuccess: () => { showForm.value = false; form.reset(); },
    };
    if (editingId.value) {
        form.put(route('users.update', editingId.value), opts);
    } else {
        form.post(route('users.store'), opts);
    }
};

// ---- Delete ----
const confirmState = reactive({ show: false, id: null, processing: false });

const askDelete = (row) => {
    confirmState.id = row.id;
    confirmState.show = true;
};

const doDelete = () => {
    confirmState.processing = true;
    router.delete(route('users.destroy', confirmState.id), {
        preserveScroll: true,
        onFinish: () => {
            confirmState.processing = false;
            confirmState.show = false;
            confirmState.id = null;
        },
    });
};

// ---- Import mahasiswa (Excel) ----
const showImport = ref(false);
const importForm = useForm({ file: null });
const onPickFile = (e) => { importForm.file = e.target.files[0] ?? null; };
const submitImport = () => {
    importForm.post(route('users.mahasiswa.import'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => { showImport.value = false; importForm.reset(); },
    });
};

const exportMahasiswa = () => {
    const params = new URLSearchParams();
    if (props.filters.search) params.set('search', props.filters.search);
    if (statusFilter.value) params.set('status', statusFilter.value);
    const qs = params.toString();
    window.location.href = route('users.mahasiswa.export') + (qs ? `?${qs}` : '');
};
</script>


<template>
    <Head title="Pengguna" />

    <PageHeader title="Manajemen Pengguna" subtitle="Kelola akun mahasiswa, dosen, dan staf">
        <template #actions>
            <a :href="route('users.mahasiswa.template')" class="btn-secondary">
                <Icon name="download" class="h-4 w-4" /> Template
            </a>
            <button class="btn-secondary" @click="showImport = true">
                <Icon name="upload" class="h-4 w-4" /> Import
            </button>
            <button class="btn-secondary" @click="exportMahasiswa">
                <Icon name="download" class="h-4 w-4" /> Export
            </button>
            <button class="btn-primary" @click="openCreate">
                <Icon name="plus" class="h-4 w-4" /> Tambah Pengguna
            </button>
        </template>
    </PageHeader>


    <!-- Bulk toolbar — muncul saat ada baris dipilih -->
    <div v-if="selectedIds.length > 0" class="card mb-3 flex items-center justify-between p-3">
        <p class="text-sm text-slate-600">
            <strong>{{ selectedIds.length }}</strong> pengguna dipilih
        </p>
        <div class="flex gap-2">
            <button class="btn-secondary" :disabled="bulkBusy" @click="runBulk('activate')">Aktifkan</button>
            <button class="btn-secondary" :disabled="bulkBusy" @click="runBulk('deactivate')">Nonaktifkan</button>
            <button class="rounded-lg bg-rose-600 px-3 py-2 text-sm font-medium text-white hover:bg-rose-700 disabled:opacity-50" :disabled="bulkBusy" @click="runBulk('delete')">Hapus</button>
            <button class="btn-secondary" @click="selectedIds = []">Batal</button>
        </div>
    </div>

    <DataTable
        :columns="columns"
        :rows="users"
        :filters="filters"
        route-name="users.index"
        search-placeholder="Cari nama, email, NIM..."
        :extra-params="{ role: roleFilter || undefined, status: statusFilter || undefined }"
    >
        <template #header:select>
            <input type="checkbox" :checked="allChecked" class="rounded border-slate-300" @change="(e) => allChecked = e.target.checked" />
        </template>
        <template #cell:select="{ row }">
            <input type="checkbox" :checked="isChecked(row.id)" class="rounded border-slate-300" @change="toggleRow(row.id)" />
        </template>

        <template #filters>
            <select v-model="roleFilter" class="input w-auto py-2" @change="applyFilters">
                <option value="">Semua Role</option>
                <option v-for="r in roles" :key="r.id" :value="r.name">{{ r.display_name }}</option>
            </select>
            <select v-model="statusFilter" class="input w-auto py-2" @change="applyFilters">
                <option value="">Semua Status</option>
                <option value="aktif">Aktif</option>
                <option value="nonaktif">Nonaktif</option>
            </select>
        </template>

        <template #cell:nama="{ row }">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-100 text-xs font-semibold text-brand-700">
                    {{ (row.nama ?? '?').split(' ').map(w => w[0]).slice(0,2).join('').toUpperCase() }}
                </span>
                <span class="font-medium text-slate-700">{{ row.nama }}</span>
            </div>
        </template>

        <template #cell:foto_wajah="{ row }">
            <button
                v-if="row.foto_wajah_url"
                type="button"
                :aria-label="`Lihat foto wajah ${row.nama}`"
                class="block h-10 w-10 overflow-hidden rounded-lg ring-1 ring-slate-200 transition hover:ring-2 hover:ring-brand-400"
                @click="previewWajah = row.foto_wajah_url; previewWajahNama = row.nama"
            >
                <img :src="row.foto_wajah_url" alt="" class="h-full w-full object-cover" />
            </button>
            <span v-else class="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-300" title="Belum ada wajah terdaftar">
                <Icon name="user" class="h-5 w-5" />
            </span>
        </template>

        <template #cell:identitas="{ row }">
            <div>
                <p class="text-slate-600">{{ row.email }}</p>
                <p class="text-xs text-slate-400">{{ row.nim ?? row.nidn ?? '—' }}</p>
            </div>
        </template>

        <template #cell:roles="{ row }">
            <span v-for="r in row.roles" :key="r" class="badge mr-1 bg-brand-50 text-brand-700">{{ r }}</span>
        </template>

        <template #cell:prodi="{ row }">
            {{ row.prodi ?? '—' }}
        </template>

        <template #cell:enrollment_status="{ row }">
            <StatusBadge :value="row.enrollment_status" />
        </template>

        <template #cell:status="{ row }">
            <StatusBadge :value="row.status" />
        </template>

        <template #cell:aksi="{ row }">
            <div class="flex items-center justify-end gap-1">
                <button type="button" :aria-label="`Ubah pengguna ${row.nama}`" class="rounded-lg p-2 text-slate-400 hover:bg-brand-50 hover:text-brand-600" @click="openEdit(row)">
                    <Icon name="edit" class="h-4 w-4" aria-hidden="true" />
                </button>
                <button type="button" :aria-label="`Hapus pengguna ${row.nama}`" class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" @click="askDelete(row)">
                    <Icon name="trash" class="h-4 w-4" aria-hidden="true" />
                </button>
            </div>
        </template>
    </DataTable>

    <!-- Form modal -->
    <Modal :show="showForm" max-width="2xl" :title="editingId ? 'Edit Pengguna' : 'Tambah Pengguna'" @close="showForm = false">
        <form id="user-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <div class="sm:col-span-2">
                <label class="label">Nama Lengkap</label>
                <input v-model="form.nama" type="text" class="input" />
                <InputError :message="form.errors.nama" />
            </div>

            <div>
                <label class="label">Email</label>
                <input v-model="form.email" type="email" class="input" />
                <InputError :message="form.errors.email" />
            </div>
            <div>
                <label class="label">Role</label>
                <select v-model="form.role_id" class="input">
                    <option value="">Pilih role</option>
                    <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.display_name }}</option>
                </select>
                <InputError :message="form.errors.role_id" />
            </div>

            <div>
                <label class="label">{{ editingId ? 'Password (kosongkan jika tetap)' : 'Password' }}</label>
                <input v-model="form.password" type="password" class="input" placeholder="••••••••" />
                <InputError :message="form.errors.password" />
            </div>
            <div>
                <label class="label">Status</label>
                <select v-model="form.status" class="input">
                    <option value="aktif">Aktif</option>
                    <option value="nonaktif">Nonaktif</option>
                </select>
                <InputError :message="form.errors.status" />
            </div>

            <div>
                <label class="label">NIM</label>
                <input v-model="form.nim" type="text" class="input" />
                <InputError :message="form.errors.nim" />
            </div>
            <div>
                <label class="label">NIDN / NIP</label>
                <input v-model="form.nidn" type="text" class="input" placeholder="NIDN" />
                <InputError :message="form.errors.nidn" />
            </div>

            <div>
                <label class="label">Program Studi</label>
                <select v-model="form.prodi_id" class="input">
                    <option value="">— Tidak ada —</option>
                    <option v-for="p in prodis" :key="p.id" :value="p.id">{{ p.nama }}</option>
                </select>
                <InputError :message="form.errors.prodi_id" />
            </div>
            <div>
                <label class="label">No. HP</label>
                <input v-model="form.no_hp" type="text" class="input" />
                <InputError :message="form.errors.no_hp" />
            </div>

            <div>
                <label class="label">Kelas</label>
                <select
                    v-model="form.kelas_id"
                    class="input"
                    :disabled="!isMahasiswaRole"
                    @change="form.semester = semesterFollows || form.semester"
                >
                    <option value="">— Tidak ada —</option>
                    <option v-for="k in kelasOptions" :key="k.id" :value="k.id">{{ k.label }}</option>
                </select>
                <p v-if="!isMahasiswaRole" class="mt-1 text-xs text-slate-400">
                    Kelas hanya berlaku untuk role Mahasiswa.
                </p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label">Angkatan</label>
                    <input v-model="form.angkatan" type="number" class="input" />
                </div>
                <div>
                    <label class="label">Semester</label>
                    <input
                        v-model="form.semester"
                        type="number"
                        class="input"
                        :placeholder="semesterFollows ? `Mengikuti kelas: ${semesterFollows}` : ''"
                    />
                </div>
            </div>
            <p v-if="selectedKelas" class="text-xs text-emerald-600">
                Semester mengikuti kelas {{ selectedKelas.label }}: {{ semesterFollows }}
            </p>
        </form>

        <template #footer>
            <button class="btn-secondary" @click="showForm = false">Batal</button>
            <button class="btn-primary" form="user-form" type="submit" :disabled="form.processing">
                {{ form.processing ? 'Menyimpan...' : 'Simpan' }}
            </button>
        </template>
    </Modal>

    <ConfirmDialog
        :show="confirmState.show"
        title="Hapus Pengguna"
        message="Pengguna yang dihapus tidak dapat dikembalikan. Lanjutkan?"
        :processing="confirmState.processing"
        @confirm="doDelete"
        @cancel="confirmState.show = false"
    />

    <!-- Preview foto wajah terdaftar -->
    <Modal :show="!!previewWajah" max-width="md" :title="`Foto Wajah — ${previewWajahNama}`" @close="previewWajah = null">
        <img v-if="previewWajah" :src="previewWajah" alt="" class="w-full rounded-xl" />
        <p class="mt-2 text-xs text-slate-400">URL foto privat &amp; kedaluwarsa otomatis (10 menit) — muat ulang halaman untuk tautan baru.</p>
    </Modal>

    <!-- Import mahasiswa modal -->
    <Modal :show="showImport" title="Import Mahasiswa (Excel)" max-width="lg" @close="showImport = false">
        <div class="space-y-3 text-sm">
            <p class="text-slate-500">
                Unggah file <strong>.xlsx</strong> dengan kolom:
                <code class="rounded bg-slate-100 px-1">nim, nama, email, no_hp, prodi_kode, kelas, angkatan, semester</code>.
                Baris dengan email/NIM yang sudah ada akan diperbarui. Password default
                <strong>12345678</strong>.
            </p>
            <a :href="route('users.mahasiswa.template')" class="inline-flex items-center gap-1.5 text-brand-600 hover:underline">
                <Icon name="download" class="h-4 w-4" /> Unduh template
            </a>
            <div>
                <input type="file" accept=".xlsx" class="input" @change="onPickFile" />
                <InputError :message="importForm.errors.file" />
            </div>
        </div>
        <template #footer>
            <button class="btn-secondary" @click="showImport = false">Batal</button>
            <button class="btn-primary" :disabled="!importForm.file || importForm.processing" @click="submitImport">
                {{ importForm.processing ? 'Mengimpor...' : 'Import' }}
            </button>
        </template>
    </Modal>
</template>


