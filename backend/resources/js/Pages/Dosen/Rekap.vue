<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    summary: { type: Array, default: () => [] },
    detail: { type: Object, default: null },
    selectedMkId: { type: [Number, null], default: null },
});

const selected = ref(props.selectedMkId);

const openDetail = (id) => {
    selected.value = id;
    router.get(route('dosen.rekap'), { mata_kuliah_id: id }, { preserveScroll: true });
};

const closeDetail = () => {
    selected.value = null;
    router.get(route('dosen.rekap'), {}, { preserveScroll: true });
};

const pct = (v) => `${v}%`;
const pctColor = (v) => v >= 75 ? 'text-emerald-600' : v >= 50 ? 'text-amber-600' : 'text-rose-600';
</script>

<template>
    <Head title="Rekap Kehadiran" />
    <PageHeader title="Rekap Kehadiran" subtitle="Ringkasan kehadiran per mata kuliah yang Anda ampu" />

    <!-- Ringkasan per MK -->
    <div v-if="!detail" class="card overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-4">
            <h3 class="text-sm font-semibold text-slate-700">Mata Kuliah Diampu ({{ summary.length }})</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100">
                <thead>
                    <tr class="bg-slate-50/60 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                        <th class="px-5 py-3">Mata Kuliah</th>
                        <th class="px-5 py-3 text-center">Peserta</th>
                        <th class="px-5 py-3 text-center">Total Absensi</th>
                        <th class="px-5 py-3 text-center">Alpha</th>
                        <th class="px-5 py-3 text-center">Pending</th>
                        <th class="px-5 py-3 text-center">Kehadiran</th>
                        <th class="px-5 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                    <tr v-if="summary.length === 0"><td colspan="7" class="px-5 py-10 text-center text-slate-400">Belum ada mata kuliah</td></tr>
                    <tr v-for="mk in summary" :key="mk.id" class="hover:bg-slate-50/70">
                        <td class="px-5 py-3">
                            <p class="font-medium text-slate-700">{{ mk.nama }}</p>
                            <p class="text-xs text-slate-400">{{ mk.kode_mk }}{{ mk.kelas ? ' · ' + mk.kelas : '' }}</p>
                        </td>
                        <td class="px-5 py-3 text-center text-slate-600">{{ mk.peserta }}</td>
                        <td class="px-5 py-3 text-center text-slate-600">{{ mk.total_absensi }}</td>
                        <td class="px-5 py-3 text-center text-slate-600">{{ mk.alpha }}</td>
                        <td class="px-5 py-3 text-center">
                            <span v-if="mk.pending > 0" class="badge bg-amber-50 text-amber-600">{{ mk.pending }}</span>
                            <span v-else class="text-slate-300">0</span>
                        </td>
                        <td :class="['px-5 py-3 text-center font-semibold', pctColor(mk.persentase)]">{{ pct(mk.persentase) }}</td>
                        <td class="px-5 py-3 text-right">
                            <button class="text-brand-600 hover:text-brand-700" title="Lihat detail" @click="openDetail(mk.id)">
                                <Icon name="chevron-right" class="h-4 w-4" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Detail per mahasiswa -->
    <div v-else class="card overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
                <h3 class="text-sm font-semibold text-slate-700">{{ detail.mata_kuliah }}</h3>
                <p class="text-xs text-slate-400">Total pertemuan: {{ detail.total_pertemuan }}</p>
            </div>
            <button class="btn-secondary" @click="closeDetail"><Icon name="chevron-left" class="h-4 w-4" /> Kembali</button>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100">
                <thead>
                    <tr class="bg-slate-50/60 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                        <th class="px-5 py-3">Mahasiswa</th>
                        <th class="px-5 py-3 text-center">Hadir</th>
                        <th class="px-5 py-3 text-center">Terlambat</th>
                        <th class="px-5 py-3 text-center">Alpha</th>
                        <th class="px-5 py-3 text-center">Izin/Sakit</th>
                        <th class="px-5 py-3 text-center">Kehadiran</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                    <tr v-if="detail.rows.length === 0"><td colspan="6" class="px-5 py-10 text-center text-slate-400">Belum ada peserta</td></tr>
                    <tr v-for="(r, i) in detail.rows" :key="i" class="hover:bg-slate-50/70">
                        <td class="px-5 py-3"><p class="font-medium text-slate-700">{{ r.nama }}</p><p class="text-xs text-slate-400">{{ r.nim }} · {{ r.kelas }}</p></td>
                        <td class="px-5 py-3 text-center text-slate-600">{{ r.hadir }}</td>
                        <td class="px-5 py-3 text-center text-slate-600">{{ r.terlambat }}</td>
                        <td class="px-5 py-3 text-center text-slate-600">{{ r.alpha }}</td>
                        <td class="px-5 py-3 text-center text-slate-600">{{ r.izin_sakit }}</td>
                        <td :class="['px-5 py-3 text-center font-semibold', pctColor(r.persentase)]">{{ pct(r.persentase) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
