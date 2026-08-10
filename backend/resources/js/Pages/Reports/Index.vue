<script setup>
import { ref, watch, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    tab: { type: String, default: 'mata_kuliah' },
    filters: { type: Object, default: () => ({}) },
    prodis: { type: Array, default: () => [] },
    mataKuliahs: { type: Array, default: () => [] },
    mahasiswas: { type: Array, default: () => [] },
    report: { type: Object, default: null },
});

const tab = ref(props.tab);
const prodiId = ref(props.filters.prodi_id ?? '');
const mataKuliahId = ref(props.filters.mata_kuliah_id ?? '');
const userId = ref(props.filters.user_id ?? '');

const reload = () => {
    router.get(route('reports.index'), {
        tab: tab.value,
        prodi_id: prodiId.value || undefined,
        mata_kuliah_id: tab.value === 'mata_kuliah' ? (mataKuliahId.value || undefined) : undefined,
        user_id: tab.value === 'mahasiswa' ? (userId.value || undefined) : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

watch(tab, reload);

const pct = (v) => `${v}%`;
const pctColor = (v) => v >= 75 ? 'text-emerald-600' : v >= 50 ? 'text-amber-600' : 'text-rose-600';

const exportQuery = computed(() => {
    const p = new URLSearchParams();
    p.set('tab', tab.value);
    if (prodiId.value) p.set('prodi_id', prodiId.value);
    if (tab.value === 'mata_kuliah' && mataKuliahId.value) p.set('mata_kuliah_id', mataKuliahId.value);
    if (tab.value === 'mahasiswa' && userId.value) p.set('user_id', userId.value);
    return p.toString();
});

const exportExcel = () => { window.location.href = route('reports.export.excel') + '?' + exportQuery.value; };
const exportPdf = () => { window.location.href = route('reports.export.pdf') + '?' + exportQuery.value; };
</script>

<template>
    <Head title="Laporan" />
    <PageHeader title="Laporan & Rekapitulasi" subtitle="Rekap kehadiran per mata kuliah, mahasiswa, dan program studi" />

    <!-- Tabs -->
    <div class="mb-5 flex gap-1 rounded-xl bg-slate-100 p-1">
        <button :class="['flex-1 rounded-lg px-4 py-2 text-sm font-medium transition', tab === 'mata_kuliah' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500']" @click="tab = 'mata_kuliah'">Per Mata Kuliah</button>
        <button :class="['flex-1 rounded-lg px-4 py-2 text-sm font-medium transition', tab === 'mahasiswa' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500']" @click="tab = 'mahasiswa'">Per Mahasiswa</button>
        <button :class="['flex-1 rounded-lg px-4 py-2 text-sm font-medium transition', tab === 'prodi' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500']" @click="tab = 'prodi'">Per Program Studi</button>
    </div>

    <!-- Filters -->
    <div class="card mb-5 flex flex-wrap items-end gap-3 p-4">
        <div v-if="prodis.length > 1 || tab === 'prodi'">
            <label class="label">Program Studi</label>
            <select v-model="prodiId" class="input w-auto py-2" @change="reload">
                <option value="">Semua Prodi</option>
                <option v-for="p in prodis" :key="p.id" :value="p.id">{{ p.nama }}</option>
            </select>
        </div>
        <div v-if="tab === 'mata_kuliah'">
            <label class="label">Mata Kuliah</label>
            <select v-model="mataKuliahId" class="input w-auto py-2" @change="reload">
                <option value="">Pilih mata kuliah</option>
                <option v-for="m in mataKuliahs" :key="m.id" :value="m.id">{{ m.kode_mk }} — {{ m.nama }}</option>
            </select>
        </div>
        <div v-if="tab === 'mahasiswa'">
            <label class="label">Mahasiswa</label>
            <select v-model="userId" class="input w-auto py-2" @change="reload">
                <option value="">Pilih mahasiswa</option>
                <option v-for="m in mahasiswas" :key="m.id" :value="m.id">{{ m.nama }} ({{ m.nim }})</option>
            </select>
        </div>
        <div class="ml-auto flex gap-2">
            <button class="btn-secondary" :disabled="!report" @click="exportExcel"><Icon name="download" class="h-4 w-4" /> Excel</button>
            <button class="btn-secondary" :disabled="!report" @click="exportPdf"><Icon name="download" class="h-4 w-4" /> PDF</button>
        </div>
    </div>

    <div v-if="!report" class="card p-12 text-center text-slate-400">
        <Icon name="inbox" class="mx-auto h-10 w-10" />
        <p class="mt-2 text-sm">Pilih filter untuk menampilkan laporan.</p>
    </div>

    <div v-else class="card overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
                <h3 class="text-sm font-semibold text-slate-700">{{ report.title }}</h3>
                <p v-if="report.subtitle" class="text-xs text-slate-400">{{ report.subtitle }}</p>
                <p v-if="report.total_pertemuan" class="text-xs text-slate-400">Total pertemuan: {{ report.total_pertemuan }}</p>
            </div>
            <div v-if="report.avg_kehadiran !== undefined" class="text-right">
                <p class="text-xs text-slate-400">Rata-rata kehadiran</p>
                <p :class="['text-lg font-semibold', pctColor(report.avg_kehadiran)]">{{ pct(report.avg_kehadiran) }}</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <!-- per mata kuliah -->
            <table v-if="report.type === 'mata_kuliah'" class="min-w-full divide-y divide-slate-100">
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
                    <tr v-if="report.rows.length === 0"><td colspan="6" class="px-5 py-10 text-center text-slate-400">Belum ada data</td></tr>
                    <tr v-for="r in report.rows" :key="r.nim" class="hover:bg-slate-50/70">
                        <td class="px-5 py-3"><p class="font-medium text-slate-700">{{ r.nama }}</p><p class="text-xs text-slate-400">{{ r.nim }} · {{ r.kelas }}</p></td>
                        <td class="px-5 py-3 text-center text-slate-600">{{ r.hadir }}</td>
                        <td class="px-5 py-3 text-center text-slate-600">{{ r.terlambat }}</td>
                        <td class="px-5 py-3 text-center text-slate-600">{{ r.alpha }}</td>
                        <td class="px-5 py-3 text-center text-slate-600">{{ r.izin_sakit }}</td>
                        <td :class="['px-5 py-3 text-center font-semibold', pctColor(r.persentase)]">{{ pct(r.persentase) }}</td>
                    </tr>
                </tbody>
            </table>

            <!-- per mahasiswa -->
            <table v-else-if="report.type === 'mahasiswa'" class="min-w-full divide-y divide-slate-100">
                <thead>
                    <tr class="bg-slate-50/60 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                        <th class="px-5 py-3">Mata Kuliah</th>
                        <th class="px-5 py-3 text-center">Hadir</th>
                        <th class="px-5 py-3 text-center">Terlambat</th>
                        <th class="px-5 py-3 text-center">Alpha</th>
                        <th class="px-5 py-3 text-center">Izin/Sakit</th>
                        <th class="px-5 py-3 text-center">Kehadiran</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                    <tr v-if="report.rows.length === 0"><td colspan="6" class="px-5 py-10 text-center text-slate-400">Belum ada data</td></tr>
                    <tr v-for="(r, i) in report.rows" :key="i" class="hover:bg-slate-50/70">
                        <td class="px-5 py-3 font-medium text-slate-700">{{ r.mata_kuliah }}</td>
                        <td class="px-5 py-3 text-center text-slate-600">{{ r.hadir }}</td>
                        <td class="px-5 py-3 text-center text-slate-600">{{ r.terlambat }}</td>
                        <td class="px-5 py-3 text-center text-slate-600">{{ r.alpha }}</td>
                        <td class="px-5 py-3 text-center text-slate-600">{{ r.izin_sakit }}</td>
                        <td :class="['px-5 py-3 text-center font-semibold', pctColor(r.persentase)]">{{ pct(r.persentase) }}</td>
                    </tr>
                </tbody>
            </table>

            <!-- per prodi -->
            <table v-else class="min-w-full divide-y divide-slate-100">
                <thead>
                    <tr class="bg-slate-50/60 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                        <th class="px-5 py-3">Program Studi</th>
                        <th class="px-5 py-3 text-center">Mahasiswa</th>
                        <th class="px-5 py-3 text-center">Total Absensi</th>
                        <th class="px-5 py-3 text-center">Kehadiran</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                    <tr v-if="report.rows.length === 0"><td colspan="4" class="px-5 py-10 text-center text-slate-400">Belum ada data</td></tr>
                    <tr v-for="r in report.rows" :key="r.prodi" class="hover:bg-slate-50/70">
                        <td class="px-5 py-3 font-medium text-slate-700">{{ r.prodi }}</td>
                        <td class="px-5 py-3 text-center text-slate-600">{{ r.mahasiswa }}</td>
                        <td class="px-5 py-3 text-center text-slate-600">{{ r.total_absensi }}</td>
                        <td :class="['px-5 py-3 text-center font-semibold', pctColor(r.persentase)]">{{ pct(r.persentase) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
