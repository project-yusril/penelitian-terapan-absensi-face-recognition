<script setup>
import { ref, reactive } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

import PageHeader from '@/Components/PageHeader.vue';
import DataTable from '@/Components/DataTable.vue';
import Modal from '@/Components/Modal.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import InputError from '@/Components/InputError.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    prodis: { type: Array, default: () => [] },
    semesters: { type: Array, default: () => [] },
    dosens: { type: Array, default: () => [] },
});

const columns = [
    { key: 'kode_mk', label: 'Kode', sortable: true },
    { key: 'nama', label: 'Mata Kuliah', sortable: true },
    { key: 'sks', label: 'SKS', sortable: true, align: 'center' },
    { key: 'kelas', label: 'Kelas', align: 'center' },
    { key: 'dosen', label: 'Dosen' },
    { key: 'prodi', label: 'Prodi' },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'aksi', label: '', align: 'right', width: '90px' },
];

const prodiFilter = ref(props.filters.prodi_id ?? '');
const applyFilters = () => {
    router.get(route('mata-kuliah.index'), {
        search: props.filters.search || undefined,
        prodi_id: prodiFilter.value || undefined,
        per_page: props.filters.per_page,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const showForm = ref(false);
const editingId = ref(null);
const form = useForm({
    kode_mk: '', nama: '', sks: 2, semester_id: '', prodi_id: '',
    dosen_id: '', kelas: '', total_pertemuan: 16, status: 'aktif',
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.sks = 2;
    form.total_pertemuan = 16;
    form.status = 'aktif';
    showForm.value = true;
};

const openEdit = (row) => {
    editingId.value = row.id;
    form.clearErrors();
    form.kode_mk = row.kode_mk;
    form.nama = row.nama;
    form.sks = row.sks;
    form.semester_id = row.semester_id ?? '';
    form.prodi_id = row.prodi_id ?? '';
    form.dosen_id = row.dosen_id ?? '';
    form.kelas = row.kelas ?? '';
    form.total_pertemuan = row.total_pertemuan ?? 16;
    form.status = row.status;
    showForm.value = true;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => { showForm.value = false; form.reset(); } };
    editingId.value ? form.put(route('mata-kuliah.update', editingId.value), opts) : form.post(route('mata-kuliah.store'), opts);
};

const confirmState = reactive({ show: false, id: null, processing: false });
const askDelete = (row) => { confirmState.id = row.id; confirmState.show = true; };
const doDelete = () => {
    confirmState.processing = true;
    router.delete(route('mata-kuliah.destroy', confirmState.id), {
        preserveScroll: true,
        onFinish: () => { confirmState.processing = false; confirmState.show = false; },
    });
};
</script>

<template>
    <Head title="Mata Kuliah" />

    <PageHeader title="Mata Kuliah" subtitle="Kelola mata kuliah & pengampu">
        <template #actions>
            <button class="btn-primary" @click="openCreate">
                <Icon name="plus" class="h-4 w-4" /> Tambah Mata Kuliah
            </button>
        </template>
    </PageHeader>

    <DataTable
        :columns="columns"
        :rows="items"
        :filters="filters"
        route-name="mata-kuliah.index"
        search-placeholder="Cari kode atau nama..."
        :extra-params="{ prodi_id: prodiFilter || undefined }"
    >
        <template #filters>
            <select v-model="prodiFilter" class="input w-auto py-2" @change="applyFilters">
                <option value="">Semua Prodi</option>
                <option v-for="p in prodis" :key="p.id" :value="p.id">{{ p.nama }}</option>
            </select>
        </template>

        <template #cell:kode_mk="{ row }">
            <span class="font-medium text-slate-700">{{ row.kode_mk }}</span>
        </template>
        <template #cell:dosen="{ row }">{{ row.dosen ?? '—' }}</template>
        <template #cell:status="{ row }"><StatusBadge :value="row.status" /></template>
        <template #cell:aksi="{ row }">
            <div class="flex items-center justify-end gap-1">
                <Link
                    :href="route('mata-kuliah.peserta', row.id)"
                    class="rounded-lg p-2 text-slate-400 hover:bg-emerald-50 hover:text-emerald-600"
                    :title="`Kelola peserta (${row.mahasiswas_count ?? 0})`"
                    :aria-label="`Kelola peserta ${row.nama}, ${row.mahasiswas_count ?? 0} mahasiswa`"
                >
                    <Icon name="users" class="h-4 w-4" aria-hidden="true" />
                </Link>
                <button type="button" :aria-label="`Ubah mata kuliah ${row.nama}`" class="rounded-lg p-2 text-slate-400 hover:bg-brand-50 hover:text-brand-600" @click="openEdit(row)">
                    <Icon name="edit" class="h-4 w-4" aria-hidden="true" />
                </button>

                <button type="button" :aria-label="`Hapus mata kuliah ${row.nama}`" class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" @click="askDelete(row)">
                    <Icon name="trash" class="h-4 w-4" aria-hidden="true" />
                </button>
            </div>
        </template>
    </DataTable>

    <Modal :show="showForm" max-width="2xl" :title="editingId ? 'Edit Mata Kuliah' : 'Tambah Mata Kuliah'" @close="showForm = false">
        <form id="mk-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <div>
                <label class="label">Kode MK</label>
                <input v-model="form.kode_mk" type="text" class="input" />
                <InputError :message="form.errors.kode_mk" />
            </div>
            <div>
                <label class="label">SKS</label>
                <input v-model.number="form.sks" type="number" min="1" max="6" class="input" />
                <InputError :message="form.errors.sks" />
            </div>

            <div class="sm:col-span-2">
                <label class="label">Nama Mata Kuliah</label>
                <input v-model="form.nama" type="text" class="input" />
                <InputError :message="form.errors.nama" />
            </div>

            <div>
                <label class="label">Program Studi</label>
                <select v-model="form.prodi_id" class="input">
                    <option value="">Pilih prodi</option>
                    <option v-for="p in prodis" :key="p.id" :value="p.id">{{ p.nama }}</option>
                </select>
                <InputError :message="form.errors.prodi_id" />
            </div>
            <div>
                <label class="label">Semester</label>
                <select v-model="form.semester_id" class="input">
                    <option value="">Pilih semester</option>
                    <option v-for="s in semesters" :key="s.id" :value="s.id">{{ s.nama }}</option>
                </select>
                <InputError :message="form.errors.semester_id" />
            </div>

            <div>
                <label class="label">Dosen Pengampu</label>
                <select v-model="form.dosen_id" class="input">
                    <option value="">— Belum ditentukan —</option>
                    <option v-for="d in dosens" :key="d.id" :value="d.id">{{ d.nama }}</option>
                </select>
                <InputError :message="form.errors.dosen_id" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label">Kelas</label>
                    <input v-model="form.kelas" type="text" class="input" />
                </div>
                <div>
                    <label class="label">Total Pertemuan</label>
                    <input v-model.number="form.total_pertemuan" type="number" min="1" max="32" class="input" />
                    <InputError :message="form.errors.total_pertemuan" />
                </div>
            </div>

            <div>
                <label class="label">Status</label>
                <select v-model="form.status" class="input">
                    <option value="aktif">Aktif</option>
                    <option value="nonaktif">Nonaktif</option>
                </select>
            </div>
        </form>
        <template #footer>
            <button class="btn-secondary" @click="showForm = false">Batal</button>
            <button class="btn-primary" form="mk-form" type="submit" :disabled="form.processing">
                {{ form.processing ? 'Menyimpan...' : 'Simpan' }}
            </button>
        </template>
    </Modal>

    <ConfirmDialog
        :show="confirmState.show"
        title="Hapus Mata Kuliah"
        message="Mata kuliah akan dihapus. Lanjutkan?"
        :processing="confirmState.processing"
        @confirm="doDelete"
        @cancel="confirmState.show = false"
    />
</template>
