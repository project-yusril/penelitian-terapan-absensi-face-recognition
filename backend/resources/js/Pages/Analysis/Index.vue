<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    prodis: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    verification: { type: Object, required: true },
    geofence: { type: Object, default: () => ({}) },
    latency: { type: Object, default: () => ({}) },
    attendanceSp: { type: Object, default: () => ({}) },
    simultaneous: { type: Object, default: () => ({}) },
});


const prodiId = ref(props.filters.prodi_id ?? '');
const threshold = ref(props.filters.threshold ?? 1.0);
const reload = () => {
    router.get(route('analysis.index'), {
        prodi_id: prodiId.value || undefined,
        threshold: threshold.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const v = computed(() => props.verification);
const maxDist = computed(() => Math.max(1, ...Object.values(v.value.distribution ?? {})));
const maxSweep = 100;

const geo = computed(() => props.geofence ?? {});
const maxGeo = computed(() => Math.max(1, ...Object.values(geo.value.distribution ?? {})));
const lat = computed(() => props.latency ?? {});
const asp = computed(() => props.attendanceSp ?? {});
const maxTrend = computed(() => Math.max(1, ...((asp.value.weekly_trend ?? []).map((w) => w.total))));
const sim = computed(() => props.simultaneous ?? {});
</script>


<template>
    <Head title="Analisis FAR/FRR" />
    <PageHeader title="Analisis & Evaluasi" subtitle="Distribusi jarak wajah, kurva FAR/FRR, EER & threshold optimal (MobileFaceNet)" />

    <!-- Filter -->
    <div class="card mb-5 p-4">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="label" for="filter-prodi">Program Studi</label>
                <select id="filter-prodi" v-model="prodiId" class="input w-auto py-2" aria-describedby="filter-prodi-help" @change="reload">
                    <option value="">Semua prodi (gabungan)</option>
                    <option v-for="p in prodis" :key="p.id" :value="p.id">{{ p.nama }}</option>
                </select>
            </div>
            <div>
                <label class="label" for="filter-threshold">Threshold (θ)</label>
                <input id="filter-threshold" v-model.number="threshold" type="number" step="0.01" class="input w-32" @change="reload" />
            </div>
        </div>
        <!-- R-04: filter prodi mempersempit dataset, bukan hanya ambang. Angka
             gabungan tidak boleh dilaporkan sebagai hasil satu prodi. -->
        <p id="filter-prodi-help" class="mt-3 text-sm text-slate-400">
            Filter prodi mempersempit seluruh dataset (genuine/impostor, geofence, latensi, kehadiran), bukan hanya ambang θ.
            Atribusi memakai prodi mahasiswa. Pilihan <strong>gabungan</strong> menggabungkan semua prodi &mdash; jangan laporkan angkanya sebagai hasil satu prodi.
        </p>
    </div>

    <!-- KPI -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="card p-5">
            <p class="text-sm text-slate-400">FAR @ θ={{ v.threshold }}</p>
            <p class="mt-1 text-2xl font-semibold text-rose-600">{{ v.far ?? '—' }}<span v-if="v.far !== null" class="text-base">%</span></p>
        </div>
        <div class="card p-5">
            <p class="text-sm text-slate-400">FRR @ θ={{ v.threshold }}</p>
            <p class="mt-1 text-2xl font-semibold text-amber-600">{{ v.frr ?? '—' }}<span v-if="v.frr !== null" class="text-base">%</span></p>
        </div>
        <div class="card p-5">
            <p class="text-sm text-slate-400">EER</p>
            <p class="mt-1 text-2xl font-semibold text-brand-600">{{ v.eer ?? '—' }}<span v-if="v.eer !== null" class="text-base">%</span></p>
        </div>
        <div class="card p-5">
            <p class="text-sm text-slate-400">θ Optimal</p>
            <p class="mt-1 text-2xl font-semibold text-emerald-600">{{ v.optimal_threshold ?? '—' }}</p>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Distribusi distance -->
        <div class="card p-5">
            <h3 class="mb-1 text-sm font-semibold text-slate-700">Distribusi Jarak Verifikasi</h3>
            <p class="mb-4 text-xs text-slate-400">{{ v.total }} verifikasi · rata-rata {{ v.distance_stats.avg ?? '—' }}</p>
            <div class="flex h-48 items-end gap-3">
                <div v-for="(count, label) in v.distribution" :key="label" class="flex flex-1 flex-col items-center gap-2">
                    <div class="flex w-full flex-1 items-end justify-center">
                        <div class="w-full max-w-12 rounded-t-lg bg-brand-500/80" :style="{ height: `${(count / maxDist) * 100}%` }" :title="`${count}`" />
                    </div>
                    <span class="text-[10px] text-slate-400">{{ label }}</span>
                </div>
            </div>
        </div>

        <!-- Kurva FAR/FRR -->
        <div class="card p-5">
            <h3 class="mb-1 text-sm font-semibold text-slate-700">Kurva FAR vs FRR</h3>
            <p class="mb-4 text-xs text-slate-400">{{ v.genuine_count }} genuine · {{ v.impostor_count }} impostor</p>

            <div v-if="!v.sweep || v.sweep.length === 0" class="flex h-48 items-center justify-center text-sm text-slate-400">
                <div class="text-center">
                    <Icon name="search" class="mx-auto h-8 w-8" />
                    <p class="mt-2">Belum ada data berlabel. Aktifkan Mode Pengujian untuk mengumpulkan data.</p>
                </div>
            </div>
            <div v-else class="flex h-48 items-end gap-0.5">
                <div v-for="pt in v.sweep" :key="pt.threshold" class="group flex flex-1 flex-col items-center justify-end" :title="`θ=${pt.threshold} · FAR ${pt.far}% · FRR ${pt.frr}%`">
                    <div class="flex w-full items-end justify-center gap-0.5">
                        <div class="w-1.5 rounded-t bg-rose-400" :style="{ height: `${((pt.far ?? 0) / maxSweep) * 160}px` }" />
                        <div class="w-1.5 rounded-t bg-amber-400" :style="{ height: `${((pt.frr ?? 0) / maxSweep) * 160}px` }" />
                    </div>
                </div>
            </div>
            <div class="mt-3 flex items-center justify-center gap-4 text-xs text-slate-500">
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded bg-rose-400" /> FAR</span>
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded bg-amber-400" /> FRR</span>
            </div>
        </div>
    </div>

    <!-- ===== Evaluasi Geofence ===== -->
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="card p-5">
            <h3 class="mb-1 text-sm font-semibold text-slate-700">Evaluasi Geofence</h3>
            <p class="mb-4 text-xs text-slate-400">
                {{ geo.total_attempts ?? 0 }} percobaan · success rate
                <span class="font-semibold text-emerald-600">{{ geo.success_rate ?? 0 }}%</span>
            </p>
            <div class="flex h-40 items-end gap-3">
                <div v-for="(count, label) in (geo.distribution ?? {})" :key="label" class="flex flex-1 flex-col items-center gap-2">
                    <div class="flex w-full flex-1 items-end justify-center">
                        <div class="w-full max-w-12 rounded-t-lg bg-emerald-500/80" :style="{ height: `${(count / maxGeo) * 100}%` }" :title="`${count}`" />
                    </div>
                    <span class="text-[10px] text-slate-400">{{ label }}</span>
                </div>
            </div>
            <p class="mt-3 text-xs text-slate-400">Jarak rata-rata ke geofence: {{ geo.distance_stats?.avg ?? '—' }} m</p>
        </div>

        <!-- ===== Latensi Inferensi ===== -->
        <div class="card p-5">
            <h3 class="mb-1 text-sm font-semibold text-slate-700">Latensi Inferensi (MobileFaceNet)</h3>
            <p class="mb-4 text-xs text-slate-400">{{ lat.total_records ?? 0 }} record</p>
            <div class="grid grid-cols-3 gap-3 text-center">
                <div class="rounded-lg bg-slate-50 p-3">
                    <p class="text-xs text-slate-400">Rata-rata</p>
                    <p class="text-lg font-semibold text-slate-700">{{ lat.stats?.avg ?? '—' }}<span class="text-xs"> ms</span></p>
                </div>
                <div class="rounded-lg bg-slate-50 p-3">
                    <p class="text-xs text-slate-400">P95</p>
                    <p class="text-lg font-semibold text-slate-700">{{ lat.stats?.p95 ?? '—' }}<span class="text-xs"> ms</span></p>
                </div>
                <div class="rounded-lg bg-slate-50 p-3">
                    <p class="text-xs text-slate-400">Max</p>
                    <p class="text-lg font-semibold text-slate-700">{{ lat.stats?.max ?? '—' }}<span class="text-xs"> ms</span></p>
                </div>
            </div>
            <div v-if="lat.per_device && Object.keys(lat.per_device).length" class="mt-4 space-y-1.5">
                <p class="text-xs font-medium text-slate-500">Per Perangkat</p>
                <div v-for="(d, name) in lat.per_device" :key="name" class="flex items-center justify-between text-xs text-slate-500">
                    <span class="truncate">{{ name }}</span>
                    <span>{{ d.avg ?? '—' }} ms ({{ d.count }})</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Tren Kehadiran & SP ===== -->
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="card p-5">
            <h3 class="mb-1 text-sm font-semibold text-slate-700">Tren Kehadiran (4 Minggu)</h3>
            <p class="mb-4 text-xs text-slate-400">Total vs hadir vs alpha per minggu</p>
            <div class="flex h-44 items-end gap-4">
                <div v-for="(w, i) in (asp.weekly_trend ?? [])" :key="i" class="flex flex-1 flex-col items-center gap-2">
                    <div class="flex w-full flex-1 items-end justify-center gap-1">
                        <div class="w-3 rounded-t bg-brand-500/80" :style="{ height: `${(w.total / maxTrend) * 100}%` }" :title="`Total ${w.total}`" />
                        <div class="w-3 rounded-t bg-emerald-500/80" :style="{ height: `${(w.hadir / maxTrend) * 100}%` }" :title="`Hadir ${w.hadir}`" />
                        <div class="w-3 rounded-t bg-rose-500/80" :style="{ height: `${(w.alpha / maxTrend) * 100}%` }" :title="`Alpha ${w.alpha}`" />
                    </div>
                    <span class="text-[10px] text-slate-400">{{ w.week }}</span>
                </div>
            </div>
            <div class="mt-3 flex items-center justify-center gap-4 text-xs text-slate-500">
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded bg-brand-500" /> Total</span>
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded bg-emerald-500" /> Hadir</span>
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded bg-rose-500" /> Alpha</span>
            </div>
        </div>

        <!-- ===== Uji Simultan ===== -->
        <div class="card p-5">
            <h3 class="mb-1 text-sm font-semibold text-slate-700">Uji Simultan (Concurrent)</h3>
            <p class="mb-4 text-xs text-slate-400">{{ sim.total_tests ?? 0 }} pengujian</p>
            <div v-if="sim.per_concurrent_level && Object.keys(sim.per_concurrent_level).length" class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-slate-400">
                            <th class="py-2">Level</th>
                            <th class="py-2 text-center">Jumlah</th>
                            <th class="py-2 text-center">Avg (ms)</th>
                            <th class="py-2 text-center">Max (ms)</th>
                            <th class="py-2 text-center">Sukses</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="(row, level) in sim.per_concurrent_level" :key="level">
                            <td class="py-2 font-medium text-slate-600">{{ level }}</td>
                            <td class="py-2 text-center text-slate-600">{{ row.count }}</td>
                            <td class="py-2 text-center text-slate-600">{{ row.avg_latency ?? '—' }}</td>
                            <td class="py-2 text-center text-slate-600">{{ row.max_latency ?? '—' }}</td>
                            <td class="py-2 text-center text-slate-600">{{ row.success_rate }}%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="flex h-40 items-center justify-center text-center text-sm text-slate-400">
                <div>
                    <Icon name="search" class="mx-auto h-8 w-8" />
                    <p class="mt-2">Belum ada data uji simultan.</p>
                </div>
            </div>
        </div>
    </div>
</template>


