<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import DataTable from '@/Components/DataTable.vue';
import Modal from '@/Components/Modal.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    actions: { type: Array, default: () => [] },
});

const columns = [
    { key: 'created_at', label: 'Waktu', sortable: false },
    { key: 'user', label: 'Pengguna' },
    { key: 'action', label: 'Aksi' },
    { key: 'model', label: 'Objek' },
    { key: 'ip_address', label: 'IP' },
    { key: 'detail', label: '', align: 'right', width: '80px' },
];

const actionFilter = ref(props.filters.action ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');

const applyFilters = () => {
    router.get(route('audit-trail.index'), {
        search: props.filters.search || undefined,
        action: actionFilter.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const extraParams = computed(() => ({
    action: actionFilter.value || undefined,
    date_from: dateFrom.value || undefined,
    date_to: dateTo.value || undefined,
}));

const exportCsv = () => {
    const params = new URLSearchParams();
    if (props.filters.search) params.set('search', props.filters.search);
    if (actionFilter.value) params.set('action', actionFilter.value);
    if (dateFrom.value) params.set('date_from', dateFrom.value);
    if (dateTo.value) params.set('date_to', dateTo.value);
    const qs = params.toString();
    window.location.href = route('audit-trail.export') + (qs ? `?${qs}` : '');
};

const detail = ref(null);
</script>

<template>
    <Head title="Audit Trail" />
    <PageHeader title="Audit Trail" subtitle="Jejak audit perubahan data penting dalam sistem">
        <template #actions>
            <button class="btn-secondary" @click="exportCsv">
                <Icon name="download" class="h-4 w-4" /> Export CSV
            </button>
        </template>
    </PageHeader>

    <DataTable
        :columns="columns"
        :rows="items"
        :filters="filters"
        route-name="audit-trail.index"
        search-placeholder="Cari aksi atau objek..."
        :extra-params="extraParams"
    >
        <template #filters>
            <select v-model="actionFilter" class="input w-auto py-2" @change="applyFilters">
                <option value="">Semua Aksi</option>
                <option v-for="a in actions" :key="a" :value="a">{{ a }}</option>
            </select>
            <input v-model="dateFrom" type="date" class="input w-auto py-2" :title="'Dari tanggal'" @change="applyFilters" />
            <input v-model="dateTo" type="date" class="input w-auto py-2" :title="'Sampai tanggal'" @change="applyFilters" />
        </template>
        <template #cell:action="{ row }"><span class="badge bg-brand-50 text-brand-700">{{ row.action }}</span></template>
        <template #cell:ip_address="{ row }"><span class="font-mono text-xs text-slate-500">{{ row.ip_address ?? '—' }}</span></template>
        <template #cell:detail="{ row }">
            <button v-if="row.old_values || row.new_values" class="text-xs text-brand-600 hover:underline" @click="detail = row">Lihat</button>
        </template>
    </DataTable>

    <Modal :show="!!detail" max-width="lg" title="Detail Perubahan" @close="detail = null">
        <div v-if="detail" class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <p class="mb-1 font-medium text-slate-500">Sebelum</p>
                <pre class="overflow-x-auto rounded-lg bg-slate-50 p-3 text-xs text-slate-600">{{ JSON.stringify(detail.old_values, null, 2) }}</pre>
            </div>
            <div>
                <p class="mb-1 font-medium text-slate-500">Sesudah</p>
                <pre class="overflow-x-auto rounded-lg bg-slate-50 p-3 text-xs text-slate-600">{{ JSON.stringify(detail.new_values, null, 2) }}</pre>
            </div>
        </div>
    </Modal>
</template>
