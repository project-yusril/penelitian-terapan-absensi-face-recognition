<script setup>
import { ref, reactive } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import DataTable from '@/Components/DataTable.vue';
import Modal from '@/Components/Modal.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import InputError from '@/Components/InputError.vue';
import Icon from '@/Components/Icon.vue';

defineProps({
    prodis: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const columns = [
    { key: 'kode', label: 'Kode', sortable: true },
    { key: 'nama', label: 'Nama Prodi', sortable: true },
    { key: 'jenjang', label: 'Jenjang', sortable: true },
    { key: 'jurusan', label: 'Jurusan' },
    { key: 'counts', label: 'Pengguna / MK', align: 'center' },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'aksi', label: '', align: 'right', width: '90px' },
];

const showForm = ref(false);
const editingId = ref(null);
const form = useForm({ kode: '', nama: '', jenjang: 'D3', jurusan: '', status: 'aktif' });

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.jenjang = 'D3';
    form.status = 'aktif';
    showForm.value = true;
};

const openEdit = (row) => {
    editingId.value = row.id;
    form.clearErrors();
    form.kode = row.kode;
    form.nama = row.nama;
    form.jenjang = row.jenjang;
    form.jurusan = row.jurusan;
    form.status = row.status;
    showForm.value = true;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => { showForm.value = false; form.reset(); } };
    editingId.value ? form.put(route('prodi.update', editingId.value), opts) : form.post(route('prodi.store'), opts);
};

const confirmState = reactive({ show: false, id: null, processing: false });
const askDelete = (row) => { confirmState.id = row.id; confirmState.show = true; };
const doDelete = () => {
    confirmState.processing = true;
    router.delete(route('prodi.destroy', confirmState.id), {
        preserveScroll: true,
        onFinish: () => { confirmState.processing = false; confirmState.show = false; },
    });
};
</script>

<template>
    <Head title="Program Studi" />

    <PageHeader title="Program Studi" subtitle="Kelola data program studi">
        <template #actions>
            <button class="btn-primary" @click="openCreate">
                <Icon name="plus" class="h-4 w-4" /> Tambah Prodi
            </button>
        </template>
    </PageHeader>

    <DataTable :columns="columns" :rows="prodis" :filters="filters" route-name="prodi.index" search-placeholder="Cari kode atau nama prodi...">
        <template #cell:kode="{ row }">
            <span class="font-medium text-slate-700">{{ row.kode }}</span>
        </template>
        <template #cell:counts="{ row }">
            <span class="text-slate-500">{{ row.users_count }} / {{ row.mata_kuliahs_count }}</span>
        </template>
        <template #cell:status="{ row }">
            <StatusBadge :value="row.status" />
        </template>
        <template #cell:aksi="{ row }">
            <div class="flex items-center justify-end gap-1">
                <button type="button" :aria-label="`Ubah program studi ${row.nama}`" class="rounded-lg p-2 text-slate-400 hover:bg-brand-50 hover:text-brand-600" @click="openEdit(row)">
                    <Icon name="edit" class="h-4 w-4" aria-hidden="true" />
                </button>
                <button type="button" :aria-label="`Hapus program studi ${row.nama}`" class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" @click="askDelete(row)">
                    <Icon name="trash" class="h-4 w-4" aria-hidden="true" />
                </button>
            </div>
        </template>
    </DataTable>

    <Modal :show="showForm" max-width="lg" :title="editingId ? 'Edit Prodi' : 'Tambah Prodi'" @close="showForm = false">
        <form id="prodi-form" class="space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="label">Kode</label>
                    <input v-model="form.kode" type="text" class="input" placeholder="TI" />
                    <InputError :message="form.errors.kode" />
                </div>
                <div>
                    <label class="label">Jenjang</label>
                    <select v-model="form.jenjang" class="input">
                        <option value="D3">D3</option>
                        <option value="D4">D4</option>
                        <option value="S1">S1</option>
                    </select>
                    <InputError :message="form.errors.jenjang" />
                </div>
            </div>
            <div>
                <label class="label">Nama Program Studi</label>
                <input v-model="form.nama" type="text" class="input" placeholder="Teknik Informatika" />
                <InputError :message="form.errors.nama" />
            </div>
            <div>
                <label class="label">Jurusan</label>
                <input v-model="form.jurusan" type="text" class="input" placeholder="Teknik Elektro" />
                <InputError :message="form.errors.jurusan" />
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
            <button class="btn-primary" form="prodi-form" type="submit" :disabled="form.processing">
                {{ form.processing ? 'Menyimpan...' : 'Simpan' }}
            </button>
        </template>
    </Modal>

    <ConfirmDialog
        :show="confirmState.show"
        title="Hapus Prodi"
        message="Program studi akan dihapus permanen. Lanjutkan?"
        :processing="confirmState.processing"
        @confirm="doDelete"
        @cancel="confirmState.show = false"
    />
</template>
