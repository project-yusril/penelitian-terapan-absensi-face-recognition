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
    prodis: { type: Array, default: () => [] },
    semesters: { type: Array, default: () => [] },
    tingkatOptions: { type: Array, default: () => [] },
    hurufOptions: { type: Array, default: () => [] },
});

const columns = [
    { key: 'label', label: 'Kelas' },
    { key: 'prodi', label: 'Program Studi' },
    { key: 'semester', label: 'Semester' },
    { key: 'mahasiswa_count', label: 'Mahasiswa', align: 'center' },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'aksi', label: '', align: 'right', width: '90px' },
];

const prodiFilter = ref(props.filters.prodi_id ?? '');
const semesterFilter = ref(props.filters.semester_id ?? '');

const applyFilters = () => {
    router.get(route('kelas.index'), {
        search: props.filters.search || undefined,
        prodi_id: prodiFilter.value || undefined,
        semester_id: semesterFilter.value || undefined,
        per_page: props.filters.per_page,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

// Pratinjau label kombinasi tingkat + huruf.
const preview = computed(() => {
    const t = form.tingkat;
    const h = form.nama;
    return t && h ? `${t}${h}` : '';
});

const showForm = ref(false);
const editingId = ref(null);
const form = useForm({
    prodi_id: '', semester_id: '', tingkat: '', nama: '', status: 'aktif',
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.status = 'aktif';
    showForm.value = true;
};

const openEdit = (row) => {
    editingId.value = row.id;
    form.clearErrors();
    form.prodi_id = row.prodi_id ?? '';
    form.semester_id = row.semester_id ?? '';
    form.tingkat = row.tingkat ?? '';
    form.nama = row.nama ?? '';
    form.status = row.status;
    showForm.value = true;
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => { showForm.value = false; form.reset(); } };
    editingId.value ? form.put(route('kelas.update', editingId.value), opts) : form.post(route('kelas.store'), opts);
};

const confirmState = reactive({ show: false, id: null, processing: false });
const askDelete = (row) => { confirmState.id = row.id; confirmState.show = true; };
const doDelete = () => {
    confirmState.processing = true;
    router.delete(route('kelas.destroy', confirmState.id), {
        preserveScroll: true,
        onFinish: () => { confirmState.processing = false; confirmState.show = false; },
    });
};
</script>

<template>
    <Head title="Kelas" />

    <PageHeader title="Kelas" subtitle="Master kelas: semester, tingkat, dan huruf">
        <template #actions>
            <button class="btn-primary" @click="openCreate">
                <Icon name="plus" class="h-4 w-4" /> Tambah Kelas
            </button>
        </template>
    </PageHeader>

    <DataTable
        :columns="columns"
        :rows="items"
        :filters="filters"
        route-name="kelas.index"
        search-placeholder="Cari tingkat atau huruf..."
        :extra-params="{ prodi_id: prodiFilter || undefined, semester_id: semesterFilter || undefined }"
    >
        <template #filters>
            <select v-model="prodiFilter" class="input w-auto py-2" @change="applyFilters">
                <option value="">Semua Prodi</option>
                <option v-for="p in prodis" :key="p.id" :value="p.id">{{ p.nama }}</option>
            </select>
            <select v-model="semesterFilter" class="input w-auto py-2" @change="applyFilters">
                <option value="">Semua Semester</option>
                <option v-for="s in semesters" :key="s.id" :value="s.id">{{ s.nama }}</option>
            </select>
        </template>

        <template #cell:label="{ row }">
            <span class="inline-flex items-center rounded-lg bg-brand-50 px-2.5 py-1 text-sm font-semibold text-brand-700">
                {{ row.label }}
            </span>
        </template>
        <template #cell:status="{ row }"><StatusBadge :value="row.status" /></template>
        <template #cell:aksi="{ row }">
            <div class="flex items-center justify-end gap-1">
                <button type="button" :aria-label="`Ubah kelas ${row.label}`" class="rounded-lg p-2 text-slate-400 hover:bg-brand-50 hover:text-brand-600" @click="openEdit(row)">
                    <Icon name="edit" class="h-4 w-4" aria-hidden="true" />
                </button>
                <button type="button" :aria-label="`Hapus kelas ${row.label}`" class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" @click="askDelete(row)">
                    <Icon name="trash" class="h-4 w-4" aria-hidden="true" />
                </button>
            </div>
        </template>
    </DataTable>

    <Modal :show="showForm" max-width="lg" :title="editingId ? 'Edit Kelas' : 'Tambah Kelas'" @close="showForm = false">
        <form id="kelas-form" class="space-y-4" @submit.prevent="submit">
            <div>
                <label class="label">Semester</label>
                <select v-model="form.semester_id" class="input">
                    <option value="">Pilih semester</option>
                    <option v-for="s in semesters" :key="s.id" :value="s.id">{{ s.nama }}</option>
                </select>
                <InputError :message="form.errors.semester_id" />
            </div>

            <div>
                <label class="label">Program Studi</label>
                <select v-model="form.prodi_id" class="input">
                    <option value="">Pilih prodi</option>
                    <option v-for="p in prodis" :key="p.id" :value="p.id">{{ p.nama }}</option>
                </select>
                <InputError :message="form.errors.prodi_id" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="label">Tingkat</label>
                    <select v-model="form.tingkat" class="input">
                        <option value="">Pilih tingkat</option>
                        <option v-for="t in tingkatOptions" :key="t" :value="t">Tingkat {{ t }}</option>
                    </select>
                    <InputError :message="form.errors.tingkat" />
                </div>
                <div>
                    <label class="label">Huruf</label>
                    <select v-model="form.nama" class="input">
                        <option value="">Pilih huruf</option>
                        <option v-for="h in hurufOptions" :key="h" :value="h">{{ h }}</option>
                    </select>
                    <InputError :message="form.errors.nama" />
                </div>
            </div>

            <div
                v-if="preview"
                class="flex items-center gap-3 rounded-xl border border-brand-100 bg-brand-50/60 px-4 py-3"
            >
                <span class="text-xs font-medium uppercase tracking-wider text-brand-500">Terbentuk</span>
                <span class="rounded-lg bg-white px-3 py-1 text-lg font-bold text-brand-700 shadow-sm">{{ preview }}</span>
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
            <button class="btn-primary" form="kelas-form" type="submit" :disabled="form.processing">
                {{ form.processing ? 'Menyimpan...' : 'Simpan' }}
            </button>
        </template>
    </Modal>

    <ConfirmDialog
        :show="confirmState.show"
        title="Hapus Kelas"
        message="Kelas akan dihapus. Kelas dengan mahasiswa atau jadwal tidak dapat dihapus. Lanjutkan?"
        :processing="confirmState.processing"
        @confirm="doDelete"
        @cancel="confirmState.show = false"
    />
</template>
