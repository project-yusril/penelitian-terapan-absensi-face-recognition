<script setup>
import { ref, reactive } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import DataTable from '@/Components/DataTable.vue';
import Modal from '@/Components/Modal.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import InputError from '@/Components/InputError.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    mataKuliahs: { type: Array, default: () => [] },
});

const columns = [
    { key: 'nama', label: 'Mahasiswa' },
    { key: 'mata_kuliah', label: 'Mata Kuliah' },
    { key: 'tanggal', label: 'Tanggal', sortable: true },
    { key: 'checkin_time', label: 'Check-in', align: 'center' },
    { key: 'status', label: 'Status' },
    { key: 'aksi', label: '', align: 'right', width: '220px' },
];

const statusFilter = ref(props.filters.status ?? 'pending');
const mkFilter = ref(props.filters.mata_kuliah_id ?? '');
const applyFilters = () => {
    router.get(route('dosen.attendance.index'), {
        search: props.filters.search || undefined,
        status: statusFilter.value || undefined,
        mata_kuliah_id: mkFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const approve = (row) => router.put(route('dosen.attendance.approve', row.id), {}, { preserveScroll: true });

const reject = reactive({ show: false, id: null, alasan: '', processing: false });
const openReject = (row) => { reject.id = row.id; reject.alasan = ''; reject.show = true; };
const doReject = () => {
    reject.processing = true;
    router.put(route('dosen.attendance.reject', reject.id), { alasan: reject.alasan }, {
        preserveScroll: true,
        onSuccess: () => { reject.show = false; },
        onFinish: () => { reject.processing = false; },
    });
};

const ovr = useForm({ status: 'hadir', alasan: '' });
const ovrId = ref(null);
const showOvr = ref(false);
const openOvr = (row) => { ovrId.value = row.id; ovr.reset(); ovr.status = 'hadir'; showOvr.value = true; };
const doOvr = () => ovr.put(route('dosen.attendance.override', ovrId.value), {
    preserveScroll: true,
    onSuccess: () => { showOvr.value = false; },
});
</script>

<template>
    <Head title="Approval Kehadiran" />
    <PageHeader title="Approval Kehadiran" subtitle="Setujui keterlambatan, tolak, atau override status kehadiran" />

    <DataTable :columns="columns" :rows="items" :filters="filters" route-name="dosen.attendance.index" search-placeholder="Cari nama atau NIM..." :extra-params="{ status: statusFilter || undefined, mata_kuliah_id: mkFilter || undefined }">
        <template #filters>
            <select v-model="mkFilter" class="input w-auto py-2" @change="applyFilters">
                <option value="">Semua MK</option>
                <option v-for="m in mataKuliahs" :key="m.id" :value="m.id">{{ m.kode_mk }} — {{ m.nama }}</option>
            </select>
            <select v-model="statusFilter" class="input w-auto py-2" @change="applyFilters">
                <option value="pending">Pending</option>
                <option value="hadir">Hadir</option>
                <option value="hadir_terlambat">Terlambat</option>
                <option value="alpha">Alpha</option>
                <option value="izin">Izin</option>
                <option value="sakit">Sakit</option>
                <option value="">Semua</option>
            </select>
        </template>

        <template #cell:nama="{ row }">
            <p class="font-medium text-slate-700">{{ row.nama }}</p>
            <p class="text-xs text-slate-400">{{ row.nim }}</p>
        </template>
        <template #cell:mata_kuliah="{ row }">{{ row.mata_kuliah }}<span v-if="row.kelas" class="text-slate-400"> · {{ row.kelas }}</span></template>
        <template #cell:status="{ row }">
            <StatusBadge :value="row.status" />
            <span v-if="row.is_overridden" class="badge ml-1 bg-amber-50 text-amber-600">Override</span>
        </template>
        <template #cell:aksi="{ row }">
            <div class="flex items-center justify-end gap-1.5">
                <template v-if="row.status === 'pending'">
                    <button class="btn-secondary px-2.5 py-1.5 text-xs" @click="openReject(row)">Tolak</button>
                    <button class="btn-primary px-2.5 py-1.5 text-xs" @click="approve(row)">Setujui</button>
                </template>
                <button class="rounded-lg p-2 text-slate-400 hover:bg-brand-50 hover:text-brand-600" title="Override" @click="openOvr(row)"><Icon name="edit" class="h-4 w-4" /></button>
            </div>
        </template>
    </DataTable>

    <!-- Reject -->
    <Modal :show="reject.show" max-width="md" title="Tolak Kehadiran" @close="reject.show = false">
        <label class="label">Alasan (opsional)</label>
        <textarea v-model="reject.alasan" rows="3" class="input"></textarea>
        <template #footer>
            <button class="btn-secondary" @click="reject.show = false">Batal</button>
            <button class="btn-danger" :disabled="reject.processing" @click="doReject">{{ reject.processing ? 'Memproses...' : 'Tolak (Alpha)' }}</button>
        </template>
    </Modal>

    <!-- Override -->
    <Modal :show="showOvr" max-width="md" title="Override Status Kehadiran" @close="showOvr = false">
        <div class="space-y-4">
            <div>
                <label class="label">Status baru</label>
                <select v-model="ovr.status" class="input">
                    <option value="hadir">Hadir</option>
                    <option value="hadir_terlambat">Hadir Terlambat</option>
                    <option value="alpha">Alpha</option>
                    <option value="izin">Izin</option>
                    <option value="sakit">Sakit</option>
                </select>
                <InputError :message="ovr.errors.status" />
            </div>
            <div>
                <label class="label">Alasan override</label>
                <textarea v-model="ovr.alasan" rows="3" class="input" placeholder="Wajib diisi untuk audit trail"></textarea>
                <InputError :message="ovr.errors.alasan" />
            </div>
        </div>
        <template #footer>
            <button class="btn-secondary" @click="showOvr = false">Batal</button>
            <button class="btn-primary" :disabled="ovr.processing" @click="doOvr">{{ ovr.processing ? 'Menyimpan...' : 'Simpan Override' }}</button>
        </template>
    </Modal>
</template>
