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
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const columns = [
    { key: 'kode', label: 'Kode', sortable: true },
    { key: 'nama', label: 'Nama', sortable: true },
    { key: 'periode', label: 'Periode' },
    { key: 'semesters_count', label: 'Semester', align: 'center' },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'aksi', label: '', align: 'right', width: '90px' },
];

const showForm = ref(false);
const editingId = ref(null);
const form = useForm({ kode: '', nama: '', tanggal_mulai: '', tanggal_selesai: '', status: 'nonaktif' });

const openCreate = () => { editingId.value = null; form.reset(); form.clearErrors(); form.status = 'nonaktif'; showForm.value = true; };
const openEdit = (row) => {
    editingId.value = row.id; form.clearErrors();
    form.kode = row.kode; form.nama = row.nama;
    form.tanggal_mulai = row.tanggal_mulai; form.tanggal_selesai = row.tanggal_selesai; form.status = row.status;
    showForm.value = true;
};
const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => { showForm.value = false; form.reset(); } };
    editingId.value ? form.put(route('tahun-ajaran.update', editingId.value), opts) : form.post(route('tahun-ajaran.store'), opts);
};

const confirmState = reactive({ show: false, id: null, processing: false });
const askDelete = (row) => { confirmState.id = row.id; confirmState.show = true; };
const doDelete = () => {
    confirmState.processing = true;
    router.delete(route('tahun-ajaran.destroy', confirmState.id), { preserveScroll: true, onFinish: () => { confirmState.processing = false; confirmState.show = false; } });
};
</script>

<template>
    <Head title="Tahun Ajaran" />
    <PageHeader title="Tahun Ajaran" subtitle="Kelola tahun ajaran akademik">
        <template #actions>
            <button class="btn-primary" @click="openCreate"><Icon name="plus" class="h-4 w-4" /> Tambah</button>
        </template>
    </PageHeader>

    <DataTable :columns="columns" :rows="items" :filters="filters" route-name="tahun-ajaran.index" search-placeholder="Cari kode atau nama...">
        <template #cell:kode="{ row }"><span class="font-medium text-slate-700">{{ row.kode }}</span></template>
        <template #cell:periode="{ row }">{{ row.tanggal_mulai }} → {{ row.tanggal_selesai }}</template>
        <template #cell:status="{ row }"><StatusBadge :value="row.status" /></template>
        <template #cell:aksi="{ row }">
            <div class="flex items-center justify-end gap-1">
                <button type="button" :aria-label="`Ubah tahun ajaran ${row.nama}`" class="rounded-lg p-2 text-slate-400 hover:bg-brand-50 hover:text-brand-600" @click="openEdit(row)"><Icon name="edit" class="h-4 w-4" aria-hidden="true" /></button>
                <button type="button" :aria-label="`Hapus tahun ajaran ${row.nama}`" class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" @click="askDelete(row)"><Icon name="trash" class="h-4 w-4" aria-hidden="true" /></button>
            </div>
        </template>
    </DataTable>

    <Modal :show="showForm" max-width="lg" :title="editingId ? 'Edit Tahun Ajaran' : 'Tambah Tahun Ajaran'" @close="showForm = false">
        <form id="ta-form" class="space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="label">Kode</label>
                    <input v-model="form.kode" type="text" class="input" placeholder="2025-2026" />
                    <InputError :message="form.errors.kode" />
                </div>
                <div>
                    <label class="label">Nama</label>
                    <input v-model="form.nama" type="text" class="input" placeholder="2025/2026" />
                    <InputError :message="form.errors.nama" />
                </div>
                <div>
                    <label class="label">Tanggal Mulai</label>
                    <input v-model="form.tanggal_mulai" type="date" class="input" />
                    <InputError :message="form.errors.tanggal_mulai" />
                </div>
                <div>
                    <label class="label">Tanggal Selesai</label>
                    <input v-model="form.tanggal_selesai" type="date" class="input" />
                    <InputError :message="form.errors.tanggal_selesai" />
                </div>
            </div>
            <div>
                <label class="label">Status</label>
                <select v-model="form.status" class="input">
                    <option value="aktif">Aktif</option>
                    <option value="nonaktif">Nonaktif</option>
                </select>
                <p class="mt-1 text-xs text-slate-400">Mengaktifkan akan menonaktifkan tahun ajaran lain.</p>
            </div>
        </form>
        <template #footer>
            <button class="btn-secondary" @click="showForm = false">Batal</button>
            <button class="btn-primary" form="ta-form" type="submit" :disabled="form.processing">{{ form.processing ? 'Menyimpan...' : 'Simpan' }}</button>
        </template>
    </Modal>

    <ConfirmDialog :show="confirmState.show" title="Hapus Tahun Ajaran" message="Tahun ajaran akan dihapus. Lanjutkan?" :processing="confirmState.processing" @confirm="doDelete" @cancel="confirmState.show = false" />
</template>
