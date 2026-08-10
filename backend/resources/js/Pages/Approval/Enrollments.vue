<script setup>
import { ref, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import DataTable from '@/Components/DataTable.vue';
import Modal from '@/Components/Modal.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import InputError from '@/Components/InputError.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const columns = [
    { key: 'foto', label: 'Foto', width: '70px' },
    { key: 'nama', label: 'Mahasiswa' },
    { key: 'kelas', label: 'Kelas', align: 'center' },
    { key: 'enrollment_status', label: 'Status' },
    { key: 'updated_at', label: 'Diajukan' },
    { key: 'aksi', label: '', align: 'right', width: '160px' },
];

const statusFilter = ref(props.filters.status ?? 'pending');
const applyFilters = () => {
    router.get(route('enrollments.index'), { status: statusFilter.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
};

const preview = ref(null);

const reject = reactive({ show: false, id: null, alasan: '', error: '', processing: false });
const openReject = (row) => { reject.id = row.id; reject.alasan = ''; reject.error = ''; reject.show = true; };

const approve = (row) => {
    router.put(route('enrollments.approve', row.id), {}, { preserveScroll: true });
};
const doReject = () => {
    reject.processing = true;
    router.put(route('enrollments.reject', reject.id), { alasan: reject.alasan }, {
        preserveScroll: true,
        onError: (e) => { reject.error = e.alasan ?? 'Gagal'; },
        onSuccess: () => { reject.show = false; },
        onFinish: () => { reject.processing = false; },
    });
};
</script>

<template>
    <Head title="Approval Enrollment" />
    <PageHeader title="Persetujuan Enrollment" subtitle="Tinjau & setujui pendaftaran wajah mahasiswa" />

    <DataTable :columns="columns" :rows="items" :filters="filters" route-name="enrollments.index" search-placeholder="Cari nama atau NIM..." :extra-params="{ status: statusFilter || undefined }">
        <template #filters>
            <select v-model="statusFilter" class="input w-auto py-2" @change="applyFilters">
                <option value="pending">Pending</option>
                <option value="approved">Disetujui</option>
                <option value="rejected">Ditolak</option>
            </select>
        </template>

        <template #cell:foto="{ row }">
            <button v-if="row.foto_url" @click="preview = row.foto_url" class="block h-10 w-10 overflow-hidden rounded-lg ring-1 ring-slate-200">
                <img :src="row.foto_url" alt="" class="h-full w-full object-cover" />
            </button>
            <span v-else class="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-300"><Icon name="user" class="h-5 w-5" /></span>
        </template>
        <template #cell:nama="{ row }">
            <p class="font-medium text-slate-700">{{ row.nama }}</p>
            <p class="text-xs text-slate-400">{{ row.nim }}</p>
        </template>
        <template #cell:enrollment_status="{ row }"><StatusBadge :value="row.enrollment_status" /></template>
        <template #cell:aksi="{ row }">
            <div v-if="row.enrollment_status === 'pending'" class="flex items-center justify-end gap-2">
                <button class="btn-secondary px-3 py-1.5 text-xs" @click="openReject(row)">Tolak</button>
                <button class="btn-primary px-3 py-1.5 text-xs" @click="approve(row)">Setujui</button>
            </div>
            <span v-else class="text-xs text-slate-400">—</span>
        </template>
    </DataTable>

    <!-- Foto preview -->
    <Modal :show="!!preview" max-width="md" title="Foto Enrollment" @close="preview = null">
        <img v-if="preview" :src="preview" alt="" class="w-full rounded-xl" />
    </Modal>

    <!-- Reject modal -->
    <Modal :show="reject.show" max-width="md" title="Tolak Enrollment" @close="reject.show = false">
        <label class="label">Alasan penolakan</label>
        <textarea v-model="reject.alasan" rows="3" class="input" placeholder="Jelaskan alasan..."></textarea>
        <InputError :message="reject.error" />
        <template #footer>
            <button class="btn-secondary" @click="reject.show = false">Batal</button>
            <button class="btn-danger" :disabled="reject.processing" @click="doReject">{{ reject.processing ? 'Memproses...' : 'Tolak' }}</button>
        </template>
    </Modal>
</template>
