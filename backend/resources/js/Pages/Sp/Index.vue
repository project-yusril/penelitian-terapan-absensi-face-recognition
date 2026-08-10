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
    candidates: { type: Array, default: () => [] },
    semesters: { type: Array, default: () => [] },
    canSign: { type: Object, default: () => ({ kaprodi: false, kajur: false }) },
});

const columns = [
    { key: 'nama', label: 'Mahasiswa' },
    { key: 'sp_level', label: 'Level', align: 'center' },
    { key: 'nomor_surat', label: 'No. Surat' },
    { key: 'total_alpha_jam', label: 'Alpha (jam)', align: 'center' },
    { key: 'status', label: 'Status' },
    { key: 'aksi', label: '', align: 'right', width: '200px' },
];

const levelFilter = ref(props.filters.level ?? '');
const statusFilter = ref(props.filters.status ?? '');
const applyFilters = () => {
    router.get(route('sp.index'), {
        search: props.filters.search || undefined,
        level: levelFilter.value || undefined,
        status: statusFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const levelLabel = (l) => (l ? l.toUpperCase() : '—');
const spStatus = (s) => ({
    draft: { c: 'bg-slate-100 text-slate-600', t: 'Draft' },
    menunggu_kaprodi: { c: 'bg-amber-50 text-amber-700', t: 'Tunggu TTD Kaprodi' },
    menunggu_kajur: { c: 'bg-sky-50 text-sky-700', t: 'Tunggu TTD Kajur' },
    final: { c: 'bg-emerald-50 text-emerald-700', t: 'Final' },
    dibatalkan: { c: 'bg-rose-50 text-rose-700', t: 'Dibatalkan' },
}[s] ?? { c: 'bg-slate-100 text-slate-600', t: s });

// Generate modal
const genForm = useForm({ user_id: '', level: 'sp1', semester_id: '' });
const showGen = ref(false);
const openGen = () => { showGen.value = true; genForm.reset(); genForm.level = 'sp1'; };
const onPickCandidate = (e) => {
    const c = props.candidates.find((x) => String(x.user_id) === e.target.value);
    if (c) {
        genForm.semester_id = c.semester_id ?? '';
        genForm.level = (c.sp_status || 'sp1').toLowerCase();
    }
};
const submitGen = () => genForm.post(route('sp.generate'), { preserveScroll: true, onSuccess: () => { showGen.value = false; } });

const act = (name, id) => router.post(route(name, id), {}, { preserveScroll: true });
const send = (row) => router.post(route('sp.send', row.id), {}, { preserveScroll: true });
const signKaprodi = (row) => act('sp.sign-kaprodi', row.id);
const signKajur = (row) => act('sp.sign-kajur', row.id);

const cancel = reactive({ show: false, id: null, reason: '', error: '', processing: false });
const openCancel = (row) => { cancel.id = row.id; cancel.reason = ''; cancel.error = ''; cancel.show = true; };
const doCancel = () => {
    cancel.processing = true;
    router.post(route('sp.cancel', cancel.id), { reason: cancel.reason }, {
        preserveScroll: true,
        onError: (e) => { cancel.error = e.reason ?? 'Gagal'; },
        onSuccess: () => { cancel.show = false; },
        onFinish: () => { cancel.processing = false; },
    });
};
</script>

<template>
    <Head title="Surat Peringatan" />
    <PageHeader title="Surat Peringatan (SP)" subtitle="Generate, tanda tangan, & kelola dokumen SP mahasiswa">
        <template #actions>
            <button class="btn-primary" @click="openGen"><Icon name="plus" class="h-4 w-4" /> Generate SP</button>
        </template>
    </PageHeader>

    <DataTable :columns="columns" :rows="items" :filters="filters" route-name="sp.index" search-placeholder="Cari nama atau NIM..." :extra-params="{ level: levelFilter || undefined, status: statusFilter || undefined }">
        <template #filters>
            <select v-model="levelFilter" class="input w-auto py-2" @change="applyFilters">
                <option value="">Semua Level</option>
                <option value="sp1">SP1</option><option value="sp2">SP2</option><option value="sp3">SP3</option><option value="do">DO</option>
            </select>
            <select v-model="statusFilter" class="input w-auto py-2" @change="applyFilters">
                <option value="">Semua Status</option>
                <option value="draft">Draft</option>
                <option value="menunggu_kaprodi">Tunggu Kaprodi</option>
                <option value="menunggu_kajur">Tunggu Kajur</option>
                <option value="final">Final</option>
                <option value="dibatalkan">Dibatalkan</option>
            </select>
        </template>

        <template #cell:nama="{ row }">
            <p class="font-medium text-slate-700">{{ row.nama }}</p>
            <p class="text-xs text-slate-400">{{ row.nim }} · {{ row.prodi }}</p>
        </template>
        <template #cell:sp_level="{ row }">
            <span class="badge bg-rose-50 text-rose-700">{{ levelLabel(row.sp_level) }}</span>
        </template>
        <template #cell:status="{ row }">
            <span :class="['badge', spStatus(row.status).c]">{{ spStatus(row.status).t }}</span>
        </template>
        <template #cell:aksi="{ row }">
            <div class="flex items-center justify-end gap-1">
                <a v-if="row.has_document" :href="route('sp.download', row.id)" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600" title="Unduh PDF"><Icon name="book" class="h-4 w-4" /></a>
                <button v-if="row.status === 'draft'" class="btn-secondary px-2.5 py-1.5 text-xs" @click="send(row)">Kirim</button>
                <button v-if="row.status === 'menunggu_kaprodi' && canSign.kaprodi" class="btn-primary px-2.5 py-1.5 text-xs" @click="signKaprodi(row)">TTD Kaprodi</button>
                <button v-if="row.status === 'menunggu_kajur' && canSign.kajur" class="btn-primary px-2.5 py-1.5 text-xs" @click="signKajur(row)">TTD Kajur</button>
                <button v-if="!['final','dibatalkan'].includes(row.status)" class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Batalkan" @click="openCancel(row)"><Icon name="close" class="h-4 w-4" /></button>
            </div>
        </template>
    </DataTable>

    <!-- Generate modal -->
    <Modal :show="showGen" max-width="lg" title="Generate Surat Peringatan" @close="showGen = false">
        <form id="gen-form" class="space-y-4" @submit.prevent="submitGen">
            <div>
                <label class="label">Mahasiswa (kandidat SP)</label>
                <select v-model="genForm.user_id" class="input" @change="onPickCandidate">
                    <option value="">Pilih mahasiswa</option>
                    <option v-for="c in candidates" :key="c.user_id" :value="c.user_id">
                        {{ c.nama }} ({{ c.nim }}) — {{ c.total_alpha_jam }} jam · {{ (c.sp_status || '').toUpperCase() }}
                    </option>
                </select>
                <InputError :message="genForm.errors.user_id" />
                <p v-if="candidates.length === 0" class="mt-1 text-xs text-slate-400">Belum ada mahasiswa berstatus SP.</p>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="label">Level SP</label>
                    <select v-model="genForm.level" class="input">
                        <option value="sp1">SP1</option><option value="sp2">SP2</option><option value="sp3">SP3</option><option value="do">DO</option>
                    </select>
                    <InputError :message="genForm.errors.level" />
                </div>
                <div>
                    <label class="label">Semester</label>
                    <select v-model="genForm.semester_id" class="input">
                        <option value="">Semester aktif</option>
                        <option v-for="s in semesters" :key="s.id" :value="s.id">{{ s.nama }}</option>
                    </select>
                </div>
            </div>
        </form>
        <template #footer>
            <button class="btn-secondary" @click="showGen = false">Batal</button>
            <button class="btn-primary" form="gen-form" type="submit" :disabled="genForm.processing">{{ genForm.processing ? 'Memproses...' : 'Generate' }}</button>
        </template>
    </Modal>

    <!-- Cancel modal -->
    <Modal :show="cancel.show" max-width="md" title="Batalkan SP" @close="cancel.show = false">
        <label class="label">Alasan pembatalan</label>
        <textarea v-model="cancel.reason" rows="3" class="input"></textarea>
        <InputError :message="cancel.error" />
        <template #footer>
            <button class="btn-secondary" @click="cancel.show = false">Tutup</button>
            <button class="btn-danger" :disabled="cancel.processing" @click="doCancel">{{ cancel.processing ? 'Memproses...' : 'Batalkan SP' }}</button>
        </template>
    </Modal>
</template>
