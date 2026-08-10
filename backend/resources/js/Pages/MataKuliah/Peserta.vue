<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';

const props = defineProps({
    mataKuliah: { type: Object, required: true },
    enrolled: { type: Array, default: () => [] },
    available: { type: Array, default: () => [] },
});

const showEnroll = ref(false);
const search = ref('');
const selected = ref([]);

const filteredAvailable = computed(() => {
    const q = search.value.toLowerCase().trim();
    if (!q) return props.available;
    return props.available.filter(
        (m) => (m.nama ?? '').toLowerCase().includes(q) || (m.nim ?? '').toLowerCase().includes(q),
    );
});

const allFilteredSelected = computed(
    () => filteredAvailable.value.length > 0 && filteredAvailable.value.every((m) => selected.value.includes(m.id)),
);

const toggleAll = () => {
    if (allFilteredSelected.value) {
        const ids = filteredAvailable.value.map((m) => m.id);
        selected.value = selected.value.filter((id) => !ids.includes(id));
    } else {
        const ids = filteredAvailable.value.map((m) => m.id);
        selected.value = [...new Set([...selected.value, ...ids])];
    }
};

const enrollForm = useForm({ mahasiswa_ids: [] });
const submitEnroll = () => {
    enrollForm.mahasiswa_ids = selected.value;
    enrollForm.post(route('mata-kuliah.enroll', props.mataKuliah.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEnroll.value = false;
            selected.value = [];
            search.value = '';
        },
    });
};

const confirmState = ref({ show: false, id: null, nama: '', processing: false });
const askUnenroll = (m) => {
    confirmState.value = { show: true, id: m.id, nama: m.nama, processing: false };
};
const doUnenroll = () => {
    confirmState.value.processing = true;
    router.delete(route('mata-kuliah.unenroll', [props.mataKuliah.id, confirmState.value.id]), {
        preserveScroll: true,
        onFinish: () => {
            confirmState.value.processing = false;
            confirmState.value.show = false;
        },
    });
};
</script>

<template>
    <Head :title="`Peserta — ${mataKuliah.nama}`" />

    <PageHeader :title="`Peserta: ${mataKuliah.nama}`" :subtitle="`${mataKuliah.kode_mk} · ${mataKuliah.prodi ?? '—'}${mataKuliah.kelas ? ' · Kelas ' + mataKuliah.kelas : ''}`">
        <template #actions>
            <Link :href="route('mata-kuliah.index')" class="btn-secondary">
                <Icon name="chevron-left" class="h-4 w-4" /> Kembali
            </Link>
            <button class="btn-primary" @click="showEnroll = true">
                <Icon name="user" class="h-4 w-4" /> Enroll Mahasiswa
            </button>
        </template>
    </PageHeader>

    <div class="card overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-4">
            <h3 class="text-sm font-semibold text-slate-700">Peserta Terdaftar ({{ enrolled.length }})</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100">
                <thead>
                    <tr class="bg-slate-50/60 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                        <th class="px-5 py-3">Nama</th>
                        <th class="px-5 py-3">NIM</th>
                        <th class="px-5 py-3">Kelas</th>
                        <th class="px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-if="enrolled.length === 0">
                        <td colspan="4" class="px-5 py-10 text-center text-sm text-slate-400">Belum ada peserta</td>
                    </tr>
                    <tr v-for="m in enrolled" :key="m.id" class="text-sm hover:bg-slate-50/70">
                        <td class="px-5 py-3 font-medium text-slate-700">{{ m.nama }}</td>
                        <td class="px-5 py-3 text-slate-600">{{ m.nim ?? '—' }}</td>
                        <td class="px-5 py-3 text-slate-600">{{ m.kelas ?? '—' }}</td>
                        <td class="px-5 py-3 text-right">
                            <button class="text-rose-600 hover:text-rose-700" title="Keluarkan" @click="askUnenroll(m)">
                                <Icon name="close" class="h-4 w-4" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal enroll -->
    <Modal :show="showEnroll" title="Enroll Mahasiswa" max-width="2xl" @close="showEnroll = false">
        <div class="space-y-3">
            <div class="relative">
                <Icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input v-model="search" type="text" placeholder="Cari nama atau NIM..." class="input pl-9" />
            </div>

            <div v-if="available.length === 0" class="py-8 text-center text-sm text-slate-400">
                Semua mahasiswa di prodi ini sudah terdaftar.
            </div>
            <div v-else class="max-h-80 overflow-y-auto rounded-xl border border-slate-100">
                <label class="flex items-center gap-3 border-b border-slate-100 bg-slate-50/60 px-4 py-2.5 text-sm font-medium text-slate-600">
                    <input type="checkbox" :checked="allFilteredSelected" class="rounded border-slate-300" @change="toggleAll" />
                    Pilih semua ({{ filteredAvailable.length }})
                </label>
                <label
                    v-for="m in filteredAvailable"
                    :key="m.id"
                    class="flex items-center gap-3 border-b border-slate-50 px-4 py-2.5 text-sm hover:bg-slate-50"
                >
                    <input v-model="selected" type="checkbox" :value="m.id" class="rounded border-slate-300" />
                    <span class="flex-1">
                        <span class="font-medium text-slate-700">{{ m.nama }}</span>
                        <span class="ml-2 text-xs text-slate-400">{{ m.nim ?? '' }} {{ m.kelas ? '· ' + m.kelas : '' }}</span>
                    </span>
                </label>
            </div>
        </div>

        <template #footer>
            <span class="mr-auto text-sm text-slate-500">{{ selected.length }} dipilih</span>
            <button class="btn-secondary" @click="showEnroll = false">Batal</button>
            <button class="btn-primary" :disabled="selected.length === 0 || enrollForm.processing" @click="submitEnroll">
                Enroll
            </button>
        </template>
    </Modal>

    <ConfirmDialog
        :show="confirmState.show"
        title="Keluarkan Peserta"
        :message="`Keluarkan ${confirmState.nama} dari mata kuliah ini?`"
        :processing="confirmState.processing"
        @confirm="doUnenroll"
        @cancel="confirmState.show = false"
    />
</template>
