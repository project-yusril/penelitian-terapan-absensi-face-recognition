<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import DataTable from '@/Components/DataTable.vue';
import Modal from '@/Components/Modal.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    statusOptions: { type: Array, default: () => [] },
});

const columns = [
    { key: 'mahasiswa', label: 'Mahasiswa' },
    { key: 'mata_kuliah', label: 'Mata Kuliah' },
    { key: 'tanggal', label: 'Tanggal', sortable: true },
    { key: 'waktu', label: 'Check-in / out' },
    { key: 'durasi', label: 'Durasi', align: 'center' },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'flags', label: 'Catatan', align: 'center' },
    { key: 'bukti', label: 'Bukti', align: 'center' },
];

const statusFilter = ref(props.filters.status ?? '');
const dateFilter = ref(props.filters.date ?? '');

const applyFilters = () => {
    router.get(route('attendance.index'), {
        search: props.filters.search || undefined,
        status: statusFilter.value || undefined,
        date: dateFilter.value || undefined,
        per_page: props.filters.per_page,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

// Bukti visual: foto attempt berisiko (check-in/checkout) berdampingan dengan
// foto enrollment pemilik akun — untuk pemeriksaan genuine vs impostor.
const buktiRow = ref(null);

const openBukti = (row) => { buktiRow.value = row; };
const closeBukti = () => { buktiRow.value = null; };

const cap = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1).replace('_', ' ') : s);
</script>

<template>
    <Head title="Kehadiran" />

    <PageHeader title="Monitoring Kehadiran" subtitle="Pantau rekam absensi mahasiswa secara real-time" />

    <DataTable
        :columns="columns"
        :rows="items"
        :filters="filters"
        route-name="attendance.index"
        search-placeholder="Cari nama atau NIM mahasiswa..."
        :extra-params="{ status: statusFilter || undefined, date: dateFilter || undefined }"
    >
        <template #filters>
            <input v-model="dateFilter" type="date" class="input w-auto py-2" @change="applyFilters" />
            <select v-model="statusFilter" class="input w-auto py-2" @change="applyFilters">
                <option value="">Semua Status</option>
                <option v-for="s in statusOptions" :key="s" :value="s">{{ cap(s) }}</option>
            </select>
        </template>

        <template #cell:mahasiswa="{ row }">
            <div>
                <p class="font-medium text-slate-700">{{ row.nama ?? '—' }}</p>
                <p class="text-xs text-slate-400">{{ row.nim ?? '' }}</p>
            </div>
        </template>
        <template #cell:mata_kuliah="{ row }">{{ row.mata_kuliah ?? '—' }}</template>
        <template #cell:waktu="{ row }">
            <span class="text-slate-600">{{ row.checkin_time ?? '—' }}</span>
            <span class="text-slate-300"> / </span>
            <span class="text-slate-600">{{ row.checkout_time ?? '—' }}</span>
        </template>
        <template #cell:durasi="{ row }">
            <span class="text-slate-500">{{ row.durasi_efektif_menit ? `${row.durasi_efektif_menit} mnt` : '—' }}</span>
        </template>
        <template #cell:status="{ row }"><StatusBadge :value="row.status" /></template>
        <template #cell:flags="{ row }">
            <div class="flex items-center justify-center gap-1.5">
                <span v-if="row.is_offline_synced" class="badge bg-sky-50 text-sky-600" title="Disinkron dari offline">Offline</span>
                <span v-if="row.is_overridden" class="badge bg-amber-50 text-amber-600" title="Telah dioverride">Override</span>
                <span v-if="!row.is_offline_synced && !row.is_overridden" class="text-slate-300">—</span>
            </div>
        </template>
        <template #cell:bukti="{ row }">
            <button
                v-if="row.checkin_foto_url || row.checkout_foto_url"
                type="button"
                class="badge bg-violet-50 text-violet-600 hover:bg-violet-100"
                title="Lihat foto attempt berisiko vs foto enrollment"
                @click="openBukti(row)"
            >
                <Icon name="eye" class="mr-1 h-3.5 w-3.5" aria-hidden="true" /> Foto
            </button>
            <span v-else class="text-slate-300">—</span>
        </template>
    </DataTable>

    <Modal :show="!!buktiRow" title="Bukti Visual Attempt Berisiko" max-width="3xl" @close="closeBukti">
        <div v-if="buktiRow">
            <p class="mb-4 text-sm text-slate-500">
                {{ buktiRow.nama }} ({{ buktiRow.nim }}) — {{ buktiRow.mata_kuliah }}, {{ buktiRow.tanggal }}.
                Bandingkan foto saat absen dengan foto enrollment pemilik akun.
                Foto hanya tersedia untuk attempt berisiko dan terhapus otomatis setelah 30 hari.
            </p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div v-if="buktiRow.enrollment_foto_url" class="rounded-xl border border-slate-100 p-3">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Foto Enrollment</p>
                    <img :src="buktiRow.enrollment_foto_url" alt="Foto enrollment mahasiswa" class="w-full rounded-lg object-cover" loading="lazy" />
                </div>
                <div v-if="buktiRow.checkin_foto_url" class="rounded-xl border border-slate-100 p-3">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Saat Check-in</p>
                    <img :src="buktiRow.checkin_foto_url" alt="Foto saat check-in" class="w-full rounded-lg object-cover" loading="lazy" />
                </div>
                <div v-if="buktiRow.checkout_foto_url" class="rounded-xl border border-slate-100 p-3">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Saat Check-out</p>
                    <img :src="buktiRow.checkout_foto_url" alt="Foto saat check-out" class="w-full rounded-lg object-cover" loading="lazy" />
                </div>
            </div>
            <p v-if="!buktiRow.enrollment_foto_url" class="mt-3 text-xs text-slate-400">
                Foto enrollment tidak tersedia untuk mahasiswa ini.
            </p>
        </div>
        <template #footer>
            <button type="button" class="btn-secondary" @click="closeBukti">Tutup</button>
        </template>
    </Modal>
</template>
