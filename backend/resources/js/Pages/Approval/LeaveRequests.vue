<script setup>
import { ref, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import DataTable from '@/Components/DataTable.vue';
import Modal from '@/Components/Modal.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const columns = [
    { key: 'nama', label: 'Mahasiswa' },
    { key: 'mata_kuliah', label: 'Mata Kuliah' },
    { key: 'jenis', label: 'Jenis' },
    { key: 'periode', label: 'Tanggal' },
    { key: 'surat', label: 'Surat', align: 'center' },
    { key: 'status', label: 'Status' },
    { key: 'aksi', label: '', align: 'right', width: '160px' },
];

const statusFilter = ref(props.filters.status ?? 'pending');
const applyFilters = () => {
    router.get(route('leave-requests.index'), { status: statusFilter.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
};

const reject = reactive({ show: false, id: null, alasan: '', error: '', processing: false });
const openReject = (row) => { reject.id = row.id; reject.alasan = ''; reject.error = ''; reject.show = true; };
const approve = (row) => router.put(route('leave-requests.approve', row.id), {}, { preserveScroll: true });
const doReject = () => {
    reject.processing = true;
    router.put(route('leave-requests.reject', reject.id), { alasan: reject.alasan }, {
        preserveScroll: true,
        onError: (e) => { reject.error = e.alasan ?? 'Gagal'; },
        onSuccess: () => { reject.show = false; },
        onFinish: () => { reject.processing = false; },
    });
};
</script>

<template>
    <Head title="Approval Izin & Sakit" />
    <PageHeader title="Persetujuan Izin & Sakit" subtitle="Tinjau & putuskan permohonan izin/sakit mahasiswa" />

    <DataTable :columns="columns" :rows="items" :filters="filters" route-name="leave-requests.index" search-placeholder="Cari..." :extra-params="{ status: statusFilter || undefined }">
        <template #filters>
            <select v-model="statusFilter" class="input w-auto py-2" @change="applyFilters">
                <option value="pending">Pending</option>
                <option value="approved">Disetujui</option>
                <option value="rejected">Ditolak</option>
            </select>
        </template>
        <template #cell:nama="{ row }">
            <p class="font-medium text-slate-700">{{ row.nama }}</p>
            <p class="text-xs text-slate-400">{{ row.nim }}</p>
        </template>
        <template #cell:jenis="{ row }"><StatusBadge :value="row.jenis" /></template>
        <template #cell:periode="{ row }">{{ row.tanggal_mulai }}<span v-if="row.tanggal_selesai !== row.tanggal_mulai"> – {{ row.tanggal_selesai }}</span></template>
        <template #cell:surat="{ row }">
            <a v-if="row.file_url" :href="row.file_url" target="_blank" class="text-brand-600 hover:underline text-xs">Lihat</a>
            <span v-else class="text-slate-300">—</span>
        </template>
        <template #cell:status="{ row }"><StatusBadge :value="row.status" /></template>
        <template #cell:aksi="{ row }">
            <div v-if="row.status === 'pending'" class="flex items-center justify-end gap-2">
                <button class="btn-secondary px-3 py-1.5 text-xs" @click="openReject(row)">Tolak</button>
                <button class="btn-primary px-3 py-1.5 text-xs" @click="approve(row)">Setujui</button>
            </div>
            <span v-else class="text-xs text-slate-400">—</span>
        </template>
    </DataTable>

    <Modal :show="reject.show" max-width="md" title="Tolak Izin/Sakit" @close="reject.show = false">
        <label class="label">Alasan penolakan</label>
        <textarea v-model="reject.alasan" rows="3" class="input"></textarea>
        <InputError :message="reject.error" />
        <template #footer>
            <button class="btn-secondary" @click="reject.show = false">Batal</button>
            <button class="btn-danger" :disabled="reject.processing" @click="doReject">{{ reject.processing ? 'Memproses...' : 'Tolak' }}</button>
        </template>
    </Modal>
</template>
