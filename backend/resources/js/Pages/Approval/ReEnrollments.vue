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
    { key: 'kelas', label: 'Kelas', align: 'center' },
    { key: 'alasan', label: 'Alasan' },
    { key: 'status', label: 'Status' },
    { key: 'created_at', label: 'Diajukan' },
    { key: 'aksi', label: '', align: 'right', width: '160px' },
];

const statusFilter = ref(props.filters.status ?? 'pending');
const applyFilters = () => {
    router.get(route('re-enrollments.index'), { status: statusFilter.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
};

const reject = reactive({ show: false, id: null, alasan: '', error: '', processing: false });
const openReject = (row) => { reject.id = row.id; reject.alasan = ''; reject.error = ''; reject.show = true; };
const approve = (row) => router.put(route('re-enrollments.approve', row.id), {}, { preserveScroll: true });
const doReject = () => {
    reject.processing = true;
    router.put(route('re-enrollments.reject', reject.id), { alasan: reject.alasan }, {
        preserveScroll: true,
        onError: (e) => { reject.error = e.alasan ?? 'Gagal'; },
        onSuccess: () => { reject.show = false; },
        onFinish: () => { reject.processing = false; },
    });
};
</script>

<template>
    <Head title="Approval Re-Enrollment" />
    <PageHeader title="Persetujuan Re-Enrollment" subtitle="Tinjau permintaan pendaftaran ulang wajah" />

    <DataTable :columns="columns" :rows="items" :filters="filters" route-name="re-enrollments.index" search-placeholder="Cari..." :extra-params="{ status: statusFilter || undefined }">
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
        <template #cell:status="{ row }"><StatusBadge :value="row.status" /></template>
        <template #cell:aksi="{ row }">
            <div v-if="row.status === 'pending'" class="flex items-center justify-end gap-2">
                <button class="btn-secondary px-3 py-1.5 text-xs" @click="openReject(row)">Tolak</button>
                <button class="btn-primary px-3 py-1.5 text-xs" @click="approve(row)">Setujui</button>
            </div>
            <span v-else class="text-xs text-slate-400">—</span>
        </template>
    </DataTable>

    <Modal :show="reject.show" max-width="md" title="Tolak Re-Enrollment" @close="reject.show = false">
        <label class="label">Alasan penolakan</label>
        <textarea v-model="reject.alasan" rows="3" class="input"></textarea>
        <InputError :message="reject.error" />
        <template #footer>
            <button class="btn-secondary" @click="reject.show = false">Batal</button>
            <button class="btn-danger" :disabled="reject.processing" @click="doReject">{{ reject.processing ? 'Memproses...' : 'Tolak' }}</button>
        </template>
    </Modal>
</template>
