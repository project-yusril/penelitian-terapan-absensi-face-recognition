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
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    hariOptions: { type: Array, default: () => [] },
    mataKuliahs: { type: Array, default: () => [] },
    geofences: { type: Array, default: () => [] },
});

const columns = [
    { key: 'hari', label: 'Hari', sortable: true },
    { key: 'waktu', label: 'Waktu' },
    { key: 'mata_kuliah', label: 'Mata Kuliah' },
    { key: 'ruangan', label: 'Ruangan', sortable: true },
    { key: 'geofence', label: 'Lokasi' },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'aksi', label: '', align: 'right', width: '90px' },
];

const cap = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);

const hariFilter = ref(props.filters.hari ?? '');
const applyFilters = () => {
    router.get(route('jadwal.index'), {
        search: props.filters.search || undefined,
        hari: hariFilter.value || undefined,
        per_page: props.filters.per_page,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const showForm = ref(false);
const editingId = ref(null);
const form = useForm({
    mata_kuliah_id: '', geofence_id: '', hari: 'senin',
    jam_mulai: '', jam_selesai: '', ruangan: '', status: 'aktif',
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.hari = 'senin';
    form.status = 'aktif';
    showForm.value = true;
};

const openEdit = (row) => {
    editingId.value = row.id;
    form.clearErrors();
    form.mata_kuliah_id = row.mata_kuliah_id ?? '';
    form.geofence_id = row.geofence_id ?? '';
    form.hari = row.hari;
    form.jam_mulai = row.jam_mulai;
    form.jam_selesai = row.jam_selesai;
    form.ruangan = row.ruangan ?? '';
    form.status = row.status;
    showForm.value = true;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => { showForm.value = false; form.reset(); } };
    editingId.value ? form.put(route('jadwal.update', editingId.value), opts) : form.post(route('jadwal.store'), opts);
};

const confirmState = reactive({ show: false, id: null, processing: false });
const askDelete = (row) => { confirmState.id = row.id; confirmState.show = true; };
const doDelete = () => {
    confirmState.processing = true;
    router.delete(route('jadwal.destroy', confirmState.id), {
        preserveScroll: true,
        onFinish: () => { confirmState.processing = false; confirmState.show = false; },
    });
};
</script>

<template>
    <Head title="Jadwal" />

    <PageHeader title="Jadwal Perkuliahan" subtitle="Atur jadwal & lokasi geofence per mata kuliah">
        <template #actions>
            <button class="btn-primary" @click="openCreate">
                <Icon name="plus" class="h-4 w-4" /> Tambah Jadwal
            </button>
        </template>
    </PageHeader>

    <DataTable
        :columns="columns"
        :rows="items"
        :filters="filters"
        route-name="jadwal.index"
        search-placeholder="Cari ruangan atau mata kuliah..."
        :extra-params="{ hari: hariFilter || undefined }"
    >
        <template #filters>
            <select v-model="hariFilter" class="input w-auto py-2" @change="applyFilters">
                <option value="">Semua Hari</option>
                <option v-for="h in hariOptions" :key="h" :value="h">{{ cap(h) }}</option>
            </select>
        </template>

        <template #cell:hari="{ row }">
            <span class="font-medium text-slate-700">{{ cap(row.hari) }}</span>
        </template>
        <template #cell:waktu="{ row }">
            <span class="inline-flex items-center gap-1.5 text-slate-600">
                <Icon name="clock" class="h-4 w-4 text-slate-400" />
                {{ row.jam_mulai }}–{{ row.jam_selesai }}
            </span>
        </template>
        <template #cell:mata_kuliah="{ row }">
            <div>
                <p class="text-slate-700">{{ row.mata_kuliah ?? '—' }}</p>
                <p class="text-xs text-slate-400">{{ row.kode_mk }}</p>
            </div>
        </template>
        <template #cell:geofence="{ row }">{{ row.geofence ?? '—' }}</template>
        <template #cell:status="{ row }"><StatusBadge :value="row.status" /></template>
        <template #cell:aksi="{ row }">
            <div class="flex items-center justify-end gap-1">
                <button type="button" :aria-label="`Ubah jadwal ${row.mata_kuliah} hari ${row.hari}`" class="rounded-lg p-2 text-slate-400 hover:bg-brand-50 hover:text-brand-600" @click="openEdit(row)">
                    <Icon name="edit" class="h-4 w-4" aria-hidden="true" />
                </button>
                <button type="button" :aria-label="`Hapus jadwal ${row.mata_kuliah} hari ${row.hari}`" class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" @click="askDelete(row)">
                    <Icon name="trash" class="h-4 w-4" aria-hidden="true" />
                </button>
            </div>
        </template>
    </DataTable>

    <Modal :show="showForm" max-width="xl" :title="editingId ? 'Edit Jadwal' : 'Tambah Jadwal'" @close="showForm = false">
        <form id="jadwal-form" class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="label">Mata Kuliah</label>
                <select v-model="form.mata_kuliah_id" class="input">
                    <option value="">Pilih mata kuliah</option>
                    <option v-for="m in mataKuliahs" :key="m.id" :value="m.id">{{ m.kode_mk }} — {{ m.nama }}</option>
                </select>
                <InputError :message="form.errors.mata_kuliah_id" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="label">Hari</label>
                    <select v-model="form.hari" class="input">
                        <option v-for="h in hariOptions" :key="h" :value="h">{{ cap(h) }}</option>
                    </select>
                    <InputError :message="form.errors.hari" />
                </div>
                <div>
                    <label class="label">Jam Mulai</label>
                    <input v-model="form.jam_mulai" type="time" class="input" />
                    <InputError :message="form.errors.jam_mulai" />
                </div>
                <div>
                    <label class="label">Jam Selesai</label>
                    <input v-model="form.jam_selesai" type="time" class="input" />
                    <InputError :message="form.errors.jam_selesai" />
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="label">Ruangan</label>
                    <input v-model="form.ruangan" type="text" class="input" placeholder="Lab Komputer 1" />
                    <InputError :message="form.errors.ruangan" />
                </div>
                <div>
                    <label class="label">Lokasi Geofence</label>
                    <select v-model="form.geofence_id" class="input">
                        <option value="">— Tidak ada —</option>
                        <option v-for="g in geofences" :key="g.id" :value="g.id">{{ g.nama }}</option>
                    </select>
                    <InputError :message="form.errors.geofence_id" />
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
            <button class="btn-primary" form="jadwal-form" type="submit" :disabled="form.processing">
                {{ form.processing ? 'Menyimpan...' : 'Simpan' }}
            </button>
        </template>
    </Modal>

    <ConfirmDialog
        :show="confirmState.show"
        title="Hapus Jadwal"
        message="Jadwal akan dihapus. Lanjutkan?"
        :processing="confirmState.processing"
        @confirm="doDelete"
        @cancel="confirmState.show = false"
    />
</template>
