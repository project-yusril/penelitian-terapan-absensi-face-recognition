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

const props = defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    prodis: { type: Array, default: () => [] },
});

const columns = [
    { key: 'nama', label: 'Lokasi', sortable: true },
    { key: 'koordinat', label: 'Koordinat' },
    { key: 'radius', label: 'Radius', sortable: true, align: 'center' },
    { key: 'gedung', label: 'Gedung', sortable: true },
    { key: 'prodi', label: 'Prodi' },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'aksi', label: '', align: 'right', width: '120px' },
];

const showForm = ref(false);
const editingId = ref(null);
const form = useForm({ nama: '', latitude: '', longitude: '', radius: 50, gedung: '', lantai: '', prodi_id: '', status: 'aktif' });

const openCreate = () => { editingId.value = null; form.reset(); form.clearErrors(); form.radius = 50; form.status = 'aktif'; showForm.value = true; };
const openEdit = (row) => {
    editingId.value = row.id; form.clearErrors();
    form.nama = row.nama; form.latitude = row.latitude; form.longitude = row.longitude; form.radius = row.radius;
    form.gedung = row.gedung ?? ''; form.lantai = row.lantai ?? ''; form.prodi_id = row.prodi_id ?? ''; form.status = row.status;
    showForm.value = true;
};
const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => { showForm.value = false; form.reset(); } };
    editingId.value ? form.put(route('geofence.update', editingId.value), opts) : form.post(route('geofence.store'), opts);
};

const confirmState = reactive({ show: false, id: null, processing: false });
const askDelete = (row) => { confirmState.id = row.id; confirmState.show = true; };
const doDelete = () => {
    confirmState.processing = true;
    router.delete(route('geofence.destroy', confirmState.id), { preserveScroll: true, onFinish: () => { confirmState.processing = false; confirmState.show = false; } });
};

const mapsUrl = (row) => `https://www.google.com/maps?q=${row.latitude},${row.longitude}`;
</script>

<template>
    <Head title="Geofence" />
    <PageHeader title="Lokasi Geofence" subtitle="Kelola titik & radius area absensi">
        <template #actions>
            <button class="btn-primary" @click="openCreate"><Icon name="plus" class="h-4 w-4" /> Tambah Lokasi</button>
        </template>
    </PageHeader>

    <DataTable :columns="columns" :rows="items" :filters="filters" route-name="geofence.index" search-placeholder="Cari lokasi atau gedung...">
        <template #cell:nama="{ row }"><span class="font-medium text-slate-700">{{ row.nama }}</span></template>
        <template #cell:koordinat="{ row }">
            <span class="font-mono text-xs text-slate-500">{{ Number(row.latitude).toFixed(5) }}, {{ Number(row.longitude).toFixed(5) }}</span>
        </template>
        <template #cell:radius="{ row }"><span class="text-slate-600">{{ row.radius }} m</span></template>
        <template #cell:gedung="{ row }">{{ row.gedung ?? '—' }}{{ row.lantai ? ` (Lt. ${row.lantai})` : '' }}</template>
        <template #cell:prodi="{ row }">{{ row.prodi ?? 'Semua' }}</template>
        <template #cell:status="{ row }"><StatusBadge :value="row.status" /></template>
        <template #cell:aksi="{ row }">
            <div class="flex items-center justify-end gap-1">
                <a :href="mapsUrl(row)" target="_blank" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600" title="Lihat di peta"><Icon name="academic" class="h-4 w-4" /></a>
                <button type="button" :aria-label="`Ubah lokasi ${row.nama}`" class="rounded-lg p-2 text-slate-400 hover:bg-brand-50 hover:text-brand-600" @click="openEdit(row)"><Icon name="edit" class="h-4 w-4" aria-hidden="true" /></button>
                <button type="button" :aria-label="`Hapus lokasi ${row.nama}`" class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" @click="askDelete(row)"><Icon name="trash" class="h-4 w-4" aria-hidden="true" /></button>
            </div>
        </template>
    </DataTable>

    <Modal :show="showForm" max-width="xl" :title="editingId ? 'Edit Lokasi' : 'Tambah Lokasi'" @close="showForm = false">
        <form id="geo-form" class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="label">Nama Lokasi</label>
                <input v-model="form.nama" type="text" class="input" placeholder="Lab Komputer 1" />
                <InputError :message="form.errors.nama" />
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="label">Latitude</label>
                    <input v-model="form.latitude" type="text" class="input" placeholder="-0.05" />
                    <InputError :message="form.errors.latitude" />
                </div>
                <div>
                    <label class="label">Longitude</label>
                    <input v-model="form.longitude" type="text" class="input" placeholder="109.34" />
                    <InputError :message="form.errors.longitude" />
                </div>
                <div>
                    <label class="label">Radius (m)</label>
                    <input v-model.number="form.radius" type="number" min="5" max="1000" class="input" />
                    <InputError :message="form.errors.radius" />
                </div>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="label">Gedung</label>
                    <input v-model="form.gedung" type="text" class="input" />
                </div>
                <div>
                    <label class="label">Lantai</label>
                    <input v-model="form.lantai" type="text" class="input" />
                </div>
                <div>
                    <label class="label">Status</label>
                    <select v-model="form.status" class="input">
                        <option value="aktif">Aktif</option>
                        <option value="nonaktif">Nonaktif</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="label">Program Studi (opsional)</label>
                <select v-model="form.prodi_id" class="input">
                    <option value="">— Semua prodi —</option>
                    <option v-for="p in prodis" :key="p.id" :value="p.id">{{ p.nama }}</option>
                </select>
            </div>
        </form>
        <template #footer>
            <button class="btn-secondary" @click="showForm = false">Batal</button>
            <button class="btn-primary" form="geo-form" type="submit" :disabled="form.processing">{{ form.processing ? 'Menyimpan...' : 'Simpan' }}</button>
        </template>
    </Modal>

    <ConfirmDialog :show="confirmState.show" title="Hapus Lokasi" message="Lokasi geofence akan dihapus. Lanjutkan?" :processing="confirmState.processing" @confirm="doDelete" @cancel="confirmState.show = false" />
</template>
